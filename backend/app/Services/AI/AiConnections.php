<?php

namespace App\Services\AI;

use App\Services\Integrations\ConnectionManager;
use App\Services\Integrations\CredentialVault;
use App\Support\DemoEnvironment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class AiConnections
{
    public function __construct(private AiAccess $access, private CredentialVault $vault, private ConnectionManager $audit) {}

    public function policy(int $user, int $org, array $data): void
    {
        DB::transaction(function () use ($user, $org, $data) {
            abort_unless($this->access->role($user, $org) === 'admin' && config('ai.enabled'), 403);
            $clean = Validator::make($data, [
                'enabled' => 'required|boolean', 'providers' => 'required|array|max:2', 'providers.*' => 'required|distinct|in:openai,gemini',
                'data_categories' => 'required|array|max:6', 'data_categories.*' => 'required|distinct|in:workplace_request,email_body,document_text,slack_message,hr_sensitive,financial',
                'max_input_bytes' => 'required|integer|min:512|max:100000', 'max_output_tokens' => 'required|integer|min:256|max:4096',
                'daily_organization_units' => 'required|integer|min:1000|max:10000000', 'daily_user_units' => 'required|integer|min:1000|max:10000000',
                'daily_workflow_units' => 'required|integer|min:1000|max:10000000', 'max_corrections' => 'required|integer|min:0|max:3', 'retention_days' => 'required|integer|min:1|max:30',
            ])->validate();
            $prior = DB::table('ai_policies')->where('organization_id', $org)->lockForUpdate()->first();
            DB::table('ai_policies')->updateOrInsert(['organization_id' => $org], [...$clean, 'providers' => json_encode($clean['providers']), 'data_categories' => json_encode($clean['data_categories']), 'generation' => ($prior->generation ?? 0) + 1, 'created_at' => $prior->created_at ?? now(), 'updated_at' => now()]);
            foreach (['openai', 'gemini'] as $provider) {
                DB::table('integration_approvals')->updateOrInsert(['organization_id' => $org, 'provider_id' => 'ai_'.$provider], ['approved' => in_array($provider, $clean['providers'], true)]);
            }
            DB::table('ai_processing_runs')->where('organization_id', $org)->whereIn('status', ['pending', 'processing'])->update(['status' => 'cancelled', 'error_code' => 'policy_changed', 'updated_at' => now()]);
            $this->audit->audit($org, null, $user, 'ai.policy.updated');
        }, 3);
    }

    public function settings(string $provider, array $data): array
    {
        abort_unless(in_array($provider, ['openai', 'gemini'], true), 422);
        $clean = Validator::make($data, ['model' => 'required|string|max:80', 'operations' => 'required|array|min:1|max:7', 'operations.*' => ['required', 'distinct', Rule::in(AiAccess::OPERATIONS)], 'max_input_bytes' => 'required|integer|min:512|max:100000', 'max_output_tokens' => 'required|integer|min:256|max:4096', 'daily_units' => 'required|integer|min:1000|max:10000000'])->validate();
        abort_unless(in_array($clean['model'], config('ai.models.'.$provider, []), true), 422);
        $clean['operations'] = json_encode($clean['operations']);

        return $clean;
    }

    private function credential(string $provider, #[\SensitiveParameter] array $secret): array
    {
        abort_unless(array_keys($secret) === ['api_key'] && is_string($secret['api_key']) && strlen($secret['api_key']) <= 512, 422);
        if (config('ai.simulated')) {
            app(DemoEnvironment::class)->assertOwned();
            abort_unless(preg_match('/^FLOWMV_QA_AI_[A-Za-z0-9_-]{12,160}$/D', $secret['api_key']), 422);
        } else {
            abort_unless(preg_match($provider === 'openai' ? '/^sk-[A-Za-z0-9_-]{20,480}$/D' : '/^AIza[A-Za-z0-9_-]{25,100}$/D', $secret['api_key']), 422);
        }

        return ['api_key' => $secret['api_key']];
    }

    public function create(int $user, int $org, array $data, #[\SensitiveParameter] array $secret): string
    {
        return DB::transaction(function () use ($user, $org, $data, $secret) {
            $role = $this->access->role($user, $org);
            $policy = $this->access->policy($org);
            $info = Validator::make($data, ['name' => 'required|string|min:1|max:100', 'provider' => 'required|in:openai,gemini', 'ownership' => 'required|in:personal,organization'])->validate();
            abort_unless(in_array($info['provider'], json_decode($policy->providers, true), true), 403);
            abort_unless($info['ownership'] === 'organization' ? $role === 'admin' : DB::table('integration_policies')->where('organization_id', $org)->value('personal_connections_allowed'), 403);
            $id = (string) Str::uuid();
            $encrypted = $this->vault->encrypt($this->credential($info['provider'], $secret), $id);
            DB::table('connections')->insert(['id' => $id, 'organization_id' => $org, 'owner_user_id' => $info['ownership'] === 'personal' ? $user : null, 'provider_id' => 'ai_'.$info['provider'], 'name' => $info['name'], 'credential_type' => 'api_key', 'status' => 'enabled', 'created_at' => now(), 'updated_at' => now()]);
            DB::table('credential_secrets')->insert(['connection_id' => $id, ...$encrypted, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('ai_connections')->insert(['connection_id' => $id, ...$this->settings($info['provider'], $data)]);
            $this->audit->audit($org, $id, $user, 'ai.connection.created');

            return $id;
        }, 3);
    }

    public function action(int $user, int $org, string $id, string $action, array $data, #[\SensitiveParameter] array $secret = []): void
    {
        DB::transaction(function () use ($user, $org, $id, $action, $data, $secret) {
            $role = $this->access->role($user, $org);
            if ($action === 'approve' && $role === 'admin') {
                $c = DB::table('connections as c')->join('ai_connections as a', 'a.connection_id', '=', 'c.id')->where('c.organization_id', $org)->where('c.id', $id)->where(fn ($q) => $q->whereNull('c.owner_user_id')->orWhere('c.owner_user_id', $user)->orWhere('a.approval_requested', true))->select('c.*')->lockForUpdate()->first();
                abort_unless($c, 404);
            } else {
                $c = $this->access->manage($user, $org, $id);
            }
            abort_if($c->status === 'revoked', 409);
            if (in_array($action, ['enable', 'approve', 'configure', 'grant'], true)) {
                $this->access->policy($org);
            }
            $provider = substr($c->provider_id, 3);
            if ($action === 'approve') {
                abort_unless($role === 'admin', 403);
                DB::table('ai_connections')->where('connection_id', $id)->update(['approved_by' => $user, 'approval_requested' => false]);
            } elseif ($action === 'request-approval') {
                $this->access->policy($org);
                DB::table('ai_connections')->where('connection_id', $id)->update(['approval_requested' => true]);
            } elseif ($action === 'rename') {
                $name = Validator::make($data, ['name' => 'required|string|min:1|max:100'])->validate()['name'];
                DB::table('connections')->where('id', $id)->update(['name' => $name, 'updated_at' => now()]);
            } elseif (in_array($action, ['disable', 'enable', 'revoke'], true)) {
                DB::table('connections')->where('id', $id)->update(['status' => ['disable' => 'disabled', 'enable' => 'enabled', 'revoke' => 'revoked'][$action], 'updated_at' => now()]);
                if ($action !== 'enable') {
                    $this->cancel($org, $id);
                }
                if ($action === 'revoke') {
                    DB::table('credential_secrets')->where('connection_id', $id)->delete();
                    DB::table('ai_workflow_grants')->where('connection_id', $id)->delete();
                    DB::table('ai_connections')->where('connection_id', $id)->update(['approved_by' => null, 'approval_requested' => false]);
                }
            } elseif ($action === 'rotate' || $action === 'configure') {
                if ($action === 'rotate') {
                    DB::table('credential_secrets')->where('connection_id', $id)->update([...$this->vault->encrypt($this->credential($provider, $secret), $id), 'updated_at' => now()]);
                } else {
                    DB::table('ai_connections')->where('connection_id', $id)->update($this->settings($provider, $data));
                }
                DB::table('ai_connections')->where('connection_id', $id)->update(['approved_by' => null, 'approval_requested' => false, 'generation' => DB::raw('generation + 1')]);
                $this->cancel($org, $id);
            } elseif (in_array($action, ['grant', 'ungrant'], true)) {
                abort_unless($c->owner_user_id !== null || $role === 'admin', 403);
                if ($action === 'grant') {
                    $this->access->connection($org, $id);
                }
                $input = Validator::make($data, ['workflow_id' => 'required|uuid', 'operation' => ['required', Rule::in(AiAccess::OPERATIONS)]])->validate();
                $workflow = DB::table('workflows')->where('organization_id', $org)->where('uuid', $input['workflow_id'])->where('status', '!=', 'archived')->first();
                abort_unless($workflow, 404);
                $key = ['organization_id' => $org, 'connection_id' => $id, 'workflow_id' => $workflow->id, 'operation' => $input['operation']];
                if ($action === 'grant') {
                    $settings = DB::table('ai_connections')->where('connection_id', $id)->first();
                    abort_unless(in_array($input['operation'], json_decode($settings->operations, true), true), 403);
                    DB::table('ai_workflow_grants')->updateOrInsert($key, ['authorized_by' => $user]);
                } else {
                    DB::table('ai_workflow_grants')->where($key)->delete();
                    $this->cancel($org, $id);
                }
            } else {
                abort(422);
            }
            $this->audit->audit($org, $id, $user, 'ai.connection.'.$action);
        }, 3);
    }

    private function cancel(int $org, string $id): void
    {
        DB::table('ai_processing_runs')->where('organization_id', $org)->where('connection_id', $id)->whereIn('status', ['pending', 'processing'])->update(['status' => 'cancelled', 'error_code' => 'connection_changed', 'updated_at' => now()]);
    }

    public function test(int $user, int $org, string $id): void
    {
        [$payload, $generation] = DB::transaction(function () use ($user, $org, $id) {
            $c = $this->access->manage($user, $org, $id);
            [, $a] = $this->access->connection($org, $id, false);
            $secret = DB::table('credential_secrets')->where('connection_id', $id)->first();
            abort_unless($secret, 403);

            return [['contract' => 'flowmv.ai.v1', 'request_id' => (string) Str::uuid(), 'organization' => (string) $org, 'actor' => (string) $user, 'connection' => $id, 'workflow' => 'connection-test', 'operation' => 'connections/test', 'provider' => substr($c->provider_id, 3), 'model' => $a->model, 'credential' => $this->vault->decrypt($secret, $id)['api_key'], 'text' => 'Synthetic connection metadata test.', 'fields' => [], 'categories' => [], 'routes' => [], 'template' => 'workplace-v1', 'max_output_tokens' => 256], $a->generation];
        }, 3);
        $result = app(AiClient::class)->call('connections/test', $payload);
        abort_unless(($result['contract'] ?? '') === 'flowmv.ai.v1' && ($result['provider'] ?? '') === $payload['provider'] && ($result['model'] ?? '') === $payload['model'] && ($result['request_id'] ?? '') === $payload['request_id'] && ($result['source'] ?? '') === (config('ai.simulated') ? 'simulated' : 'provider_response'), 503);
        unset($payload, $result);
        DB::transaction(function () use ($user, $org, $id, $generation) {
            $this->access->manage($user, $org, $id);
            [, $settings] = $this->access->connection($org, $id, false);
            abort_unless((int) $settings->generation === (int) $generation, 409);
            $this->audit->audit($org, $id, $user, 'ai.connection.test');
        }, 3);
    }
}
