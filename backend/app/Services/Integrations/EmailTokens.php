<?php

namespace App\Services\Integrations;

use App\Exceptions\EmailProviderFailure;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class EmailTokens
{
    public function __construct(private CredentialVault $vault, private EmailHttp $http, private EmailProviders $providers, private EmailAuthorization $authorization, private ConnectionManager $audit) {}

    public function get(int $org, string $id): string
    {
        $result = DB::transaction(function () use ($org, $id) {
            $c = $this->authorization->connection($org, $id);
            $m = DB::table('email_mailboxes')->where('connection_id', $id)->lockForUpdate()->first();
            $row = DB::table('credential_secrets')->where('connection_id', $id)->lockForUpdate()->first();
            abort_unless($row, 403);
            $secret = $this->vault->decrypt($row, $id);
            if (Carbon::parse($m->token_expires_at)->gt(now()->addMinute())) {
                return ['token' => $secret['access_token']];
            }
            try {
                $response = $this->http->token($c->provider_id, ['grant_type' => 'refresh_token', 'refresh_token' => $secret['refresh_token']]);
                $clean = $this->validate($c->provider_id, $response, json_decode($m->capabilities, true), $secret);
            } catch (EmailProviderFailure $failure) {
                if (in_array($failure->status, [400, 401, 403], true)) {
                    DB::table('email_mailboxes')->where('connection_id', $id)->update(['health' => 'reauthorization_required', 'error_code' => 'reauthorization_required']);
                    $this->pause($id);
                    $this->audit->audit($org, $id, null, 'email.token.reauthorization_required');
                }

                return ['failure' => $failure];
            }
            DB::table('credential_secrets')->where('connection_id', $id)->update([...$this->vault->encrypt($clean, $id), 'updated_at' => now()]);
            DB::table('email_mailboxes')->where('connection_id', $id)->update(['token_expires_at' => now()->addSeconds($clean['expires_in']), 'error_code' => null]);
            $this->audit->audit($org, $id, null, 'email.token.refreshed');

            return ['token' => $clean['access_token']];
        });
        if (isset($result['failure'])) {
            throw $result['failure'];
        }

        return $result['token'];
    }

    public function validate(string $provider, #[\SensitiveParameter] array $response, array $capabilities, #[\SensitiveParameter] array $previous = []): array
    {
        foreach (['access_token', 'refresh_token'] as $key) {
            $value = $response[$key] ?? ($key === 'refresh_token' ? ($previous[$key] ?? null) : null);
            if (! is_string($value) || strlen($value) < 8 || strlen($value) > 12000 || preg_match('/[\x00-\x20\x7f]/', $value)) {
                throw new EmailProviderFailure('invalid_token_response', 503);
            }
            $response[$key] = $value;
        }
        $scopes = $response['scope'] ?? ($previous['scope'] ?? null);
        if (! is_string($scopes) || ! is_numeric($response['expires_in'] ?? null) || (int) $response['expires_in'] < 60 || strcasecmp($response['token_type'] ?? 'Bearer', 'Bearer') !== 0) {
            throw new EmailProviderFailure('invalid_token_response', 503);
        }
        $normalize = fn ($scope) => str_replace(['https://graph.microsoft.com/', 'https://www.googleapis.com/auth/userinfo.email'], ['', 'email'], strtolower($scope));
        $granted = array_map($normalize, preg_split('/\s+/', trim($scopes)));
        foreach ($this->providers->scopes($provider, $capabilities) as $scope) {
            if ($scope === 'offline_access') {
                continue;
            }
            if (! in_array($normalize($scope), $granted, true)) {
                throw new EmailProviderFailure('provider_permission_denied', 403);
            }
        }

        return ['access_token' => $response['access_token'], 'refresh_token' => $response['refresh_token'], 'scope' => $scopes, 'expires_in' => min(86400, (int) $response['expires_in'])];
    }

    public function pause(string $id): void
    {
        DB::table('email_trigger_bindings')->where('connection_id', $id)->update(['status' => 'paused', 'updated_at' => now()]);
        DB::table('email_action_outbox')->where('connection_id', $id)->where('status', 'pending')->update(['status' => 'cancelled', 'error_code' => 'credential_unavailable', 'updated_at' => now()]);
    }
}
