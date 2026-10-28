<?php

namespace App\Services\Integrations;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ConnectionManager
{
    public function __construct(private ConnectionAccess $access, private CredentialVault $vault, private MockConnector $connector) {}

    public function audit(int $organizationId, ?string $connectionId, ?int $actorId, string $event): void
    {
        DB::table('integration_audit_events')->insert(['organization_id' => $organizationId, 'connection_id' => $connectionId, 'actor_user_id' => $actorId, 'event' => $event, 'created_at' => now()]);
    }

    public function reauthorize(int $userId, int $organizationId, string $id, #[\SensitiveParameter] array $credentials): string
    {
        return DB::transaction(function () use ($userId, $organizationId, $id, $credentials) {
            $connection = DB::table('connections')->where('organization_id', $organizationId)->where('id', $id)->lockForUpdate()->first();
            abort_unless($connection && $connection->credential_type === 'oauth' && $connection->status !== 'revoked', 404);
            $this->access->manage($userId, $connection);
            $this->access->approved($organizationId);
            $clean = $this->connector->validateCredentials('oauth', $credentials);
            DB::table('credential_secrets')->where('connection_id', $id)->update([...$this->vault->encrypt($clean, $id), 'updated_at' => now()]);
            $this->audit($organizationId, $id, $userId, 'credential.oauth_rotated');

            return $id;
        });
    }

    public function create(int $userId, int $organizationId, array $data, #[\SensitiveParameter] array $credentials, bool $oauth = false): string
    {
        return DB::transaction(function () use ($userId, $organizationId, $data, $credentials, $oauth) {
            $this->access->create($userId, $organizationId, $data['ownership']);
            abort_if($data['credential_type'] === 'oauth' && ! $oauth, 422, 'Use the OAuth authorization flow.');
            $clean = $this->connector->validateCredentials($data['credential_type'], $credentials);
            $id = (string) Str::uuid();
            $encrypted = $this->vault->encrypt($clean, $id);
            DB::table('connections')->insert(['id' => $id, 'organization_id' => $organizationId, 'owner_user_id' => $data['ownership'] === 'personal' ? $userId : null, 'provider_id' => 'local_mock', 'name' => $data['name'], 'credential_type' => $data['credential_type'], 'status' => 'enabled', 'created_at' => now(), 'updated_at' => now()]);
            DB::table('credential_secrets')->insert(['connection_id' => $id, ...$encrypted, 'created_at' => now(), 'updated_at' => now()]);
            $this->audit($organizationId, $id, $userId, 'connection.created');

            return $id;
        });
    }

    public function act(int $userId, int $organizationId, string $id, string $action, array $data, #[\SensitiveParameter] array $credentials = []): void
    {
        DB::transaction(function () use ($userId, $organizationId, $id, $action, $data, $credentials) {
            $connection = DB::table('connections')->where('organization_id', $organizationId)->where('id', $id)->lockForUpdate()->first();
            abort_if($connection === null, 404);
            $this->access->manage($userId, $connection);
            abort_if($connection->status === 'revoked', 409, 'Connection has been revoked.');
            if ($action === 'rename') {
                DB::table('connections')->where('id', $id)->update(['name' => $data['name'], 'updated_at' => now()]);
            } elseif (in_array($action, ['enable', 'disable', 'revoke'], true)) {
                DB::table('connections')->where('id', $id)->update(['status' => ['enable' => 'enabled', 'disable' => 'disabled', 'revoke' => 'revoked'][$action], 'updated_at' => now()]);
                if ($action === 'revoke') {
                    DB::table('credential_secrets')->where('connection_id', $id)->delete();
                    DB::table('workflow_connection_grants')->where('connection_id', $id)->delete();
                    DB::table('authorized_destinations')->where('connection_id', $id)->delete();
                }
            } elseif ($action === 'rotate') {
                abort_if($connection->credential_type === 'oauth', 422, 'Reconnect OAuth credentials through the authorization flow.');
                $clean = $this->connector->validateCredentials($connection->credential_type, $credentials);
                DB::table('credential_secrets')->where('connection_id', $id)->update([...$this->vault->encrypt($clean, $id), 'updated_at' => now()]);
            } elseif ($action === 'test') {
                $this->access->approved($organizationId);
                abort_unless($connection->status === 'enabled', 409);
                $secret = DB::table('credential_secrets')->where('connection_id', $id)->first();
                abort_if($secret === null, 409);
                abort_unless($this->connector->test($this->vault->decrypt($secret, $id)), 422, 'Connection test failed.');
            } elseif (in_array($action, ['share', 'unshare'], true)) {
                // Sharing grants a workflow use, never transfers ownership.
                if ($connection->owner_user_id === null) {
                    abort_unless($this->access->role($userId, $organizationId) === 'admin', 403);
                }
                $workflow = DB::table('workflows')->where('uuid', $data['workflow_id'])->where('organization_id', $organizationId)->first();
                abort_if($workflow === null, 404);
                $this->connector->validateConfiguration($data['operation'], ['destination' => $data['destination']]);
                if ($action === 'share') {
                    $this->access->approved($organizationId);
                    DB::table('workflow_connection_grants')->updateOrInsert(['connection_id' => $id, 'workflow_id' => $workflow->id, 'operation' => $data['operation']], ['organization_id' => $organizationId, 'authorized_by' => $userId]);
                    DB::table('authorized_destinations')->updateOrInsert(['connection_id' => $id, 'destination' => $data['destination']], ['organization_id' => $organizationId]);
                } else {
                    DB::table('workflow_connection_grants')->where('connection_id', $id)->where('workflow_id', $workflow->id)->where('operation', $data['operation'])->delete();
                }
            } else {
                abort(422, 'Unsupported connection action.');
            }
            $this->audit($organizationId, $id, $userId, 'connection.'.$action);
        });
    }
}
