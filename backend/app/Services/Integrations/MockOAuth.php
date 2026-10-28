<?php

namespace App\Services\Integrations;

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class MockOAuth
{
    public function __construct(private CredentialVault $vault, private ConnectionAccess $access, private ConnectionManager $manager) {}

    public function start(Request $request, int $organizationId, array $data): array
    {
        return DB::transaction(function () use ($request, $organizationId, $data) {
            if (isset($data['connection_id'])) {
                $connection = DB::table('connections')->where('id', $data['connection_id'])->where('organization_id', $organizationId)->lockForUpdate()->first();
                abort_unless($connection && $connection->credential_type === 'oauth' && $connection->status !== 'revoked', 404);
                $this->access->manage($request->user()->id, $connection);
                $this->access->approved($organizationId);
            } else {
                $this->access->create($request->user()->id, $organizationId, $data['ownership']);
            }
            $state = bin2hex(random_bytes(32));
            $verifier = $this->base64url(random_bytes(64));
            $challenge = $this->base64url(hash('sha256', $verifier, true));
            $encrypted = $this->vault->encrypt(['verifier' => $verifier, 'challenge' => $challenge, 'name' => $data['name'], 'ownership' => $data['ownership'], 'connection_id' => $data['connection_id'] ?? null, 'issuer' => 'flowmv:local_mock', 'redirect_uri' => 'flowmv:mock-callback'], $state);
            DB::table('integration_oauth_requests')->insert(['state_hash' => hash('sha256', $state), 'organization_id' => $organizationId, 'user_id' => $request->user()->id, 'session_hash' => hash('sha256', $request->session()->getId()), 'encrypted_context' => $encrypted['ciphertext'], 'key_version' => $encrypted['key_version'], 'expires_at' => now()->addMinutes(5)]);

            return ['state' => $state, 'code_challenge' => $challenge, 'code_challenge_method' => 'S256', 'issuer' => 'flowmv:local_mock'];
        });
    }

    public function authorize(Request $request, int $organizationId, string $state, string $challenge): array
    {
        return DB::transaction(function () use ($request, $organizationId, $state, $challenge) {
            [$row, $context] = $this->context($request, $organizationId, $state);
            abort_unless(hash_equals($context['challenge'], $challenge) && $row->code_hash === null, 403);
            $code = bin2hex(random_bytes(32));
            DB::table('integration_oauth_requests')->where('state_hash', $row->state_hash)->update(['code_hash' => hash('sha256', $code)]);

            return ['code' => $code, 'state' => $state, 'issuer' => 'flowmv:local_mock'];
        });
    }

    public function finish(Request $request, int $organizationId, string $state, string $code, string $issuer): string
    {
        return DB::transaction(function () use ($request, $organizationId, $state, $code, $issuer) {
            [$row, $context] = $this->context($request, $organizationId, $state);
            abort_unless($issuer === $context['issuer'] && is_string($row->code_hash) && hash_equals($row->code_hash, hash('sha256', $code)) && hash_equals($context['challenge'], $this->base64url(hash('sha256', $context['verifier'], true))), 403);
            DB::table('integration_oauth_requests')->where('state_hash', $row->state_hash)->update(['consumed_at' => now()]);
            if ($context['connection_id'] !== null) {
                return $this->manager->reauthorize($request->user()->id, $organizationId, $context['connection_id'], ['access_token' => bin2hex(random_bytes(32))]);
            }

            return $this->manager->create($request->user()->id, $organizationId, ['ownership' => $context['ownership'], 'name' => $context['name'], 'credential_type' => 'oauth'], ['access_token' => bin2hex(random_bytes(32))], true);
        });
    }

    private function context(Request $request, int $organizationId, string $state): array
    {
        abort_unless(strlen($state) === 64, 403);
        $row = DB::table('integration_oauth_requests')->where('state_hash', hash('sha256', $state))->lockForUpdate()->first();
        abort_unless($row && (int) $row->organization_id === $organizationId && (int) $row->user_id === $request->user()->id && hash_equals($row->session_hash, hash('sha256', $request->session()->getId())) && $row->consumed_at === null && Carbon::parse($row->expires_at)->isFuture(), 403);
        $record = (object) ['key_version' => $row->key_version, 'ciphertext' => $row->encrypted_context];

        return [$row, $this->vault->decrypt($record, $state)];
    }

    private function base64url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
