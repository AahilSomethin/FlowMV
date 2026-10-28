<?php

namespace App\Services\Integrations;

use App\Enums\WorkflowInstanceStatus;
use App\Models\WorkflowInstance;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;

class IntegrationRuntime
{
    public function __construct(private ConnectionAccess $access, private CredentialVault $vault, private MockConnector $connector, private ConnectionManager $manager) {}

    public function action(WorkflowInstance $instance, array $config, string $nodeId): bool
    {
        return DB::transaction(function () use ($instance, $config, $nodeId) {
            $locked = WorkflowInstance::query()->whereKey($instance->id)->lockForUpdate()->firstOrFail();
            abort_unless($locked->status === WorkflowInstanceStatus::Running && $locked->current_node_id === $nodeId, 403);
            $version = DB::table('workflow_versions')->where('id', $locked->workflow_version_id)->where('workflow_id', $locked->workflow_id)->first();
            abort_unless($version && (int) $version->node_registry_version === 3, 403);
            $graph = json_decode($version->graph_definition, true);
            $node = collect($graph['nodes'] ?? [])->firstWhere('id', $nodeId);
            abort_unless(($node['type'] ?? null) === 'integration_action' && ($node['data']['connection_id'] ?? null) === ($config['connection_id'] ?? null) && ($node['data']['operation'] ?? null) === ($config['operation'] ?? null) && ($node['data']['destination'] ?? null) === ($config['destination'] ?? null), 403);
            $connection = $this->authorized($locked->organization_id, $locked->workflow_id, $config);
            abort_unless($locked->submitted_by !== null, 403);
            $this->access->role($locked->submitted_by, $locked->organization_id);
            if ($connection->owner_user_id !== null) {
                abort_unless((int) $connection->owner_user_id === (int) $locked->submitted_by, 403);
            }

            return $this->deliver($connection, 'action', hash('sha256', $locked->uuid.':'.$nodeId), $config['operation'], $config['destination'], $locked->submitted_by);
        });
    }

    public function webhook(string $connectionId, string $eventId, int $timestamp, string $signature): bool
    {
        return DB::transaction(function () use ($connectionId, $eventId, $timestamp, $signature) {
            $connection = DB::table('connections')->where('id', $connectionId)->lockForUpdate()->first();
            abort_if($connection === null || $connection->status !== 'enabled', 404);
            abort_unless($connection->provider_id === 'local_mock', 403);
            $this->access->approved($connection->organization_id);
            $secret = DB::table('credential_secrets')->where('connection_id', $connectionId)->first();
            abort_if($secret === null, 403);
            abort_unless($this->connector->verifyWebhook($eventId, $timestamp, $signature, $this->vault->decrypt($secret, $connectionId)), 403);
            $grants = DB::table('workflow_connection_grants')->where('connection_id', $connectionId)->where('operation', 'mock.event')->get();
            abort_if($grants->isEmpty(), 403);
            foreach ($grants as $grant) {
                $role = $this->access->role($grant->authorized_by, $connection->organization_id);
                abort_unless($connection->owner_user_id === null ? $role === 'admin' : (int) $grant->authorized_by === (int) $connection->owner_user_id, 403);
                abort_unless(DB::table('workflows')->where('id', $grant->workflow_id)->where('organization_id', $connection->organization_id)->exists(), 403);
            }
            if ($connection->owner_user_id !== null) {
                $this->access->role($connection->owner_user_id, $connection->organization_id);
            }

            // Foundation ingress records a verified event; it does not start an arbitrary workflow.
            return $this->deliver($connection, 'trigger', hash('sha256', $eventId), 'mock.event', 'mock:inbox', null);
        });
    }

    public function authorized(int $organizationId, int $workflowId, array $config): object
    {
        $this->connector->validateConfiguration($config['operation'], ['destination' => $config['destination']]);
        $connection = DB::table('connections')->where('id', $config['connection_id'])->where('organization_id', $organizationId)->lockForUpdate()->first();
        abort_if($connection === null || $connection->status !== 'enabled', 403, 'Integration permission unavailable.');
        abort_unless($connection->provider_id === 'local_mock', 403);
        $this->access->approved($organizationId);
        abort_unless(DB::table('workflows')->where('id', $workflowId)->where('organization_id', $organizationId)->exists(), 403);
        $grant = DB::table('workflow_connection_grants')->where('connection_id', $connection->id)->where('organization_id', $organizationId)->where('workflow_id', $workflowId)->where('operation', $config['operation'])->first();
        abort_if($grant === null, 403, 'Workflow grant unavailable.');
        $grantRole = $this->access->role($grant->authorized_by, $organizationId);
        abort_unless($connection->owner_user_id === null ? $grantRole === 'admin' : (int) $grant->authorized_by === (int) $connection->owner_user_id, 403);
        if ($connection->owner_user_id !== null) {
            $this->access->role($connection->owner_user_id, $organizationId);
        }
        abort_unless(DB::table('authorized_destinations')->where('connection_id', $connection->id)->where('organization_id', $organizationId)->where('destination', $config['destination'])->exists(), 403, 'Destination unavailable.');

        return $connection;
    }

    private function deliver(object $connection, string $kind, string $key, string $operation, string $destination, ?int $actor): bool
    {
        abort_unless(DB::table('authorized_destinations')->where('connection_id', $connection->id)->where('destination', $destination)->exists(), 403);
        if (DB::table('integration_receipts')->where('connection_id', $connection->id)->where('kind', $kind)->where('deduplication_key', $key)->exists()) {
            return false;
        }
        abort_if(RateLimiter::tooManyAttempts('integration:'.$connection->id, 30), 429, 'Integration rate limit reached.');
        RateLimiter::hit('integration:'.$connection->id, 60);
        $secret = DB::table('credential_secrets')->where('connection_id', $connection->id)->first();
        abort_if($secret === null, 403);
        if ($kind === 'action') {
            $this->connector->execute($operation, $destination, $this->vault->decrypt($secret, $connection->id), $key);
        }
        DB::table('integration_receipts')->insert(['connection_id' => $connection->id, 'kind' => $kind, 'deduplication_key' => $key, 'created_at' => now()]);
        $this->manager->audit($connection->organization_id, $connection->id, $actor, 'integration.'.$kind.'.accepted');

        return true;
    }
}
