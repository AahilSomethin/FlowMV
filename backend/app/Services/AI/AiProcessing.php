<?php

namespace App\Services\AI;

use App\Enums\WorkflowInstanceStatus;
use App\Jobs\ProcessAiRun;
use App\Models\User;
use App\Models\WorkflowInstance;
use App\Services\Integrations\ConnectionManager;
use App\Services\Integrations\CredentialVault;
use App\Services\Integrations\DocumentTemplates;
use App\Services\Workflows\Execution\WorkflowInstanceVisibility;
use App\Services\Workflows\WorkflowDefinitionHasher;
use App\Support\DemoEnvironment;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class AiProcessing
{
    public function __construct(private AiAccess $access, private AiSource $sources, private AiBudget $budget, private AiClient $client, private CredentialVault $vault, private ConnectionManager $audit) {}

    public function configuration(array $data): array
    {
        abort_if(preg_match('/(?:sk-[A-Za-z0-9_-]{16,}|AIza[A-Za-z0-9_-]{25,}|[0-9]{5,}:[A-Za-z0-9_-]{25,})/', json_encode($data, JSON_THROW_ON_ERROR)), 422, 'Credentials must be entered only through Connections.');
        $clean = Validator::make($data, [
            'input_fields' => 'present|array|max:40', 'input_fields.*' => 'required|string|max:64|distinct',
            'fields' => 'present|array|max:20', 'categories' => 'present|array|max:16', 'categories.*' => 'required|regex:/^[a-zA-Z0-9_-]{1,80}$/D|distinct',
            'routes' => 'present|array|max:16', 'routes.*' => 'required|regex:/^[a-zA-Z0-9_-]{1,80}$/D|distinct',
            'data_categories' => 'required|array|min:1|max:6', 'data_categories.*' => 'required|distinct|in:workplace_request,email_body,document_text,slack_message,hr_sensitive,financial',
        ])->validate();
        if ($clean['fields'] !== []) {
            $clean['fields'] = app(DocumentTemplates::class)->schema($clean['fields']);
        }

        return $clean;
    }

    public function request(int $user, int $org, int $workflow, string $connection, string $operation, string $kind, string $sourceId, array $parameters, string $nodeId = 'manual'): string
    {
        return DB::transaction(function () use ($user, $org, $workflow, $connection, $operation, $kind, $sourceId, $parameters, $nodeId) {
            [$c, $a, $p] = $this->access->workflow($user, $org, $workflow, $connection, $operation);
            $configuration = $this->configuration($parameters);
            $source = $this->sources->read($user, $org, $workflow, $kind, $sourceId, $configuration['input_fields']);
            $this->sourcePolicy($source, $configuration, $p, $operation, $kind);
            $text = $this->minimize($source['text']);
            abort_if(strlen($text) > min($p->max_input_bytes, $a->max_input_bytes), 422, 'AI input exceeds the approved limit.');
            $fingerprint = hash('sha256', $source['fingerprint'].$this->configurationFingerprint($configuration));
            $deduplication = hash('sha256', implode(':', [$user, $org, $workflow, $connection, $operation, $kind, $sourceId, $nodeId, $fingerprint, $a->generation, $p->generation]));
            $existing = DB::table('ai_processing_runs')->where('deduplication_key', $deduplication)->first();
            if ($existing) {
                return $existing->id;
            }
            $attempts = DB::table('ai_processing_runs')->where('organization_id', $org)->where('workflow_id', $workflow)->where('source_kind', $kind)->where('source_id', $sourceId)->where('node_id', $nodeId)->count();
            abort_if($attempts > $p->max_corrections, 409, 'AI correction limit reached; use human review.');
            $id = (string) Str::uuid();
            DB::table('ai_processing_runs')->insert(['id' => $id, 'organization_id' => $org, 'actor_user_id' => $user, 'connection_id' => $connection, 'workflow_id' => $workflow, 'workflow_instance_id' => $source['instance_id'], 'node_id' => $nodeId, 'operation' => $operation, 'source_kind' => $kind, 'source_id' => $sourceId, 'configuration' => json_encode($configuration), 'input_fingerprint' => $fingerprint, 'deduplication_key' => $deduplication, 'credential_generation' => $a->generation, 'policy_generation' => $p->generation, 'requested_model' => $a->model, 'expires_at' => now()->addDays($p->retention_days), 'created_at' => now(), 'updated_at' => now()]);
            $run = DB::table('ai_processing_runs')->where('id', $id)->first();
            $this->budget->reserve($run, $p, $a, strlen($text) + 2 * strlen(json_encode($configuration)) + min($p->max_output_tokens, $a->max_output_tokens) + 12000);
            $this->audit->audit($org, $connection, $user, 'ai.processing.queued');
            ProcessAiRun::dispatch($id)->afterCommit();

            return $id;
        }, 3);
    }

    private function configurationFingerprint(array $configuration): string
    {
        return app(WorkflowDefinitionHasher::class)->hash($configuration, []);
    }

    private function sourcePolicy(array $source, array $configuration, object $policy, string $operation, string $kind): void
    {
        $requiredKind = ['analyze/email' => 'email', 'analyze/document' => 'document', 'analyze/slack' => 'slack'][$operation] ?? null;
        abort_unless($requiredKind === null || $kind === $requiredKind, 422);
        abort_unless(in_array($source['category'], $configuration['data_categories'], true) && array_diff($configuration['data_categories'], json_decode($policy->data_categories, true)) === [], 403);
    }

    private function minimize(#[\SensitiveParameter] string $text): string
    {
        $text = preg_replace('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', '[email redacted]', $text);
        $text = preg_replace('/\b(?:sk-[A-Za-z0-9_-]{16,}|AIza[A-Za-z0-9_-]{25,}|[0-9]{5,}:[A-Za-z0-9_-]{25,})\b/', '[credential redacted]', $text);
        $text = preg_replace('/(?<!\w)\+\d[\d ()-]{7,}\d/', '[phone redacted]', $text);
        abort_unless(is_string($text), 503);

        return $text;
    }

    private function guard(object $run, bool $execution = false): array
    {
        if ($run->node_id !== 'manual') {
            $instance = WorkflowInstance::where('organization_id', $run->organization_id)->findOrFail($run->workflow_instance_id);
            $node = app(AiWorkflow::class)->node($instance, $run->node_id);
            abort_unless((! $execution || ($instance->current_node_id === $run->node_id && $instance->status === WorkflowInstanceStatus::AwaitingAi)) && $node['data']['connection_id'] === $run->connection_id && AiNodes::OPERATIONS[$node['type']] === $run->operation && hash_equals($this->configurationFingerprint($node['data']['configuration']), $this->configurationFingerprint(json_decode($run->configuration, true))), 403);
        }
        [$c, $a, $p] = $this->access->workflow($run->actor_user_id, $run->organization_id, $run->workflow_id, $run->connection_id, $run->operation);
        abort_unless((int) $a->generation === (int) $run->credential_generation && (int) $p->generation === (int) $run->policy_generation && Carbon::parse($run->expires_at)->isFuture(), 403);
        $configuration = json_decode($run->configuration, true);
        $source = $this->sources->read($run->actor_user_id, $run->organization_id, $run->workflow_id, $run->source_kind, $run->source_id, $configuration['input_fields']);
        $this->sourcePolicy($source, $configuration, $p, $run->operation, $run->source_kind);
        abort_unless(hash_equals($run->input_fingerprint, hash('sha256', $source['fingerprint'].$this->configurationFingerprint($configuration))), 409);
        $text = $this->minimize($source['text']);
        abort_if(strlen($text) > min($a->max_input_bytes, $p->max_input_bytes), 403);

        return [$c, $a, $p, $configuration, $text];
    }

    public function process(string $id): void
    {
        try {
            $prepared = DB::transaction(function () use ($id) {
                $run = DB::table('ai_processing_runs')->where('id', $id)->lockForUpdate()->first();
                if (! $run || $run->status !== 'pending') {
                    return null;
                }
                [$c, $a, $p, $config, $text] = $this->guard($run, true);
                $secret = DB::table('credential_secrets')->where('connection_id', $c->id)->first();
                abort_unless($secret, 403);
                $credential = $this->vault->decrypt($secret, $c->id)['api_key'];
                DB::table('ai_processing_runs')->where('id', $id)->update(['status' => 'processing', 'attempt_count' => $run->attempt_count + 1, 'processing_at' => now(), 'updated_at' => now()]);

                return ['contract' => 'flowmv.ai.v1', 'request_id' => $id, 'organization' => (string) $run->organization_id, 'actor' => (string) $run->actor_user_id, 'connection' => $c->id, 'workflow' => (string) $run->workflow_id, 'operation' => $run->operation, 'provider' => substr($c->provider_id, 3), 'model' => $a->model, 'credential' => $credential, 'text' => $text, 'fields' => array_map(fn ($f) => array_intersect_key($f, array_flip(['id', 'label', 'type', 'required'])), $config['fields']), 'categories' => $config['categories'], 'routes' => $config['routes'], 'template' => 'workplace-v1', 'max_output_tokens' => min($p->max_output_tokens, $a->max_output_tokens)];
            }, 3);
            if ($prepared === null) {
                return;
            }
            if (config('ai.simulated')) {
                app(DemoEnvironment::class)->assertOwned();
            }
            $result = $this->client->call($prepared['operation'], $prepared);
            unset($prepared);
            DB::transaction(function () use ($id, $result) {
                $run = DB::table('ai_processing_runs')->where('id', $id)->lockForUpdate()->firstOrFail();
                if ($run->status !== 'processing') {
                    return;
                }
                [$c, $a, $p, $config] = $this->guard($run, true);
                $checked = app(AiResult::class)->validate($result, (object) [...(array) $run, 'provider_id' => $c->provider_id], $config);
                abort_if($checked['output_tokens'] > min($p->max_output_tokens, $a->max_output_tokens), 503);
                DB::table('ai_processing_runs')->where('id', $id)->update([...$this->vault->encrypt(['advice' => $checked['advice']], 'ai-result:'.$id), 'status' => $checked['advice']['uncertain'] ? 'uncertain' : 'completed', 'provenance' => $result['source'], 'updated_at' => now()]);
                DB::table('ai_usage_reservations')->where('run_id', $id)->update(['reported_input_tokens' => $checked['input_tokens'], 'reported_output_tokens' => $checked['output_tokens'], 'state' => 'completed', 'updated_at' => now()]);
                $this->audit->audit($run->organization_id, $c->id, $run->actor_user_id, 'ai.processing.completed');
            }, 3);
        } catch (\Throwable $error) {
            unset($prepared);
            if (app()->environment('demo') && config('demo.seeding') === true && $error instanceof HttpExceptionInterface && in_array($error->getStatusCode(), [403, 404, 409], true)) {
                throw $error;
            }
            $code = $error instanceof HttpExceptionInterface && in_array($error->getStatusCode(), [403, 404, 409], true) ? 'authorization_changed' : 'manual_review_required';
            DB::transaction(function () use ($id, $code) {
                $run = DB::table('ai_processing_runs')->where('id', $id)->lockForUpdate()->first();
                if (! $run || ! in_array($run->status, ['pending', 'processing'], true)) {
                    return;
                }
                DB::table('ai_processing_runs')->where('id', $id)->update(['status' => 'failed', 'error_code' => $code, 'updated_at' => now()]);
                DB::table('ai_usage_reservations')->where('run_id', $id)->update(['state' => 'uncertain', 'updated_at' => now()]);
                $this->audit->audit($run->organization_id, $run->connection_id, $run->actor_user_id, 'ai.processing.failed');
            }, 3);
        }
    }

    public function result(int $user, int $org, string $id): array
    {
        return DB::transaction(function () use ($user, $org, $id) {
            $this->access->role($user, $org);
            $run = DB::table('ai_processing_runs')->where('organization_id', $org)->where('id', $id)->first();
            abort_unless($run, 404);
            $this->guard($run);
            if ((int) $run->actor_user_id !== $user) {
                $instance = $run->workflow_instance_id ? WorkflowInstance::where('organization_id', $org)->find($run->workflow_instance_id) : null;
                abort_unless($instance && app(WorkflowInstanceVisibility::class)->canView(User::findOrFail($user), $instance), 404);
            }
            $out = ['id' => $run->id, 'status' => $run->status, 'operation' => $run->operation, 'source' => $run->provenance ?? 'unknown', 'requested_model' => $run->requested_model, 'resolved_model' => $run->resolved_model, 'error_code' => $run->error_code, 'expires_at' => Carbon::parse($run->expires_at)->utc()->toIso8601String()];
            if (in_array($run->status, ['completed', 'uncertain'], true) && $run->ciphertext !== null) {
                $out['advice'] = $this->vault->decrypt($run, 'ai-result:'.$id)['advice'];
                $out['advice']['suggestions'] = [];
                if ($run->node_id !== 'manual') {
                    $instance = WorkflowInstance::findOrFail($run->workflow_instance_id);
                    $node = app(AiWorkflow::class)->node($instance, $run->node_id);
                    $fields = array_column($instance->version->form_definition['fields'], null, 'id');
                    foreach ($node['data']['field_map'] as $source => $target) {
                        if (array_key_exists($source, $out['advice']['fields'])) {
                            $out['advice']['suggestions'][] = ['field_id' => $target, 'label' => $fields[$target]['label'], 'value' => $out['advice']['fields'][$source]];
                        }
                    }
                }
            }

            return $out;
        }, 3);
    }
}
