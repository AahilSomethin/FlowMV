<?php

namespace Tests\Feature;

use App\Enums\OrganizationRole;
use App\Enums\WorkflowInstanceStatus;
use App\Jobs\ProcessAiRun;
use App\Jobs\RunAiWorkflowNode;
use App\Models\Workflow;
use App\Models\WorkflowInstance;
use App\Services\AI\AiClient;
use App\Services\AI\AiConnections;
use App\Services\AI\AiNodes;
use App\Services\AI\AiProcessing;
use App\Services\AI\AiWorkflow;
use App\Services\Workflows\Nodes\NodeRegistry;
use App\Services\Workflows\WorkflowPreview;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Feature\Workflows\InteractsWithExecution;
use Tests\Support\ExecutionGraphs;
use Tests\Support\WorkflowGraphs;
use Tests\TestCase;

class AiWorkflowTest extends TestCase
{
    use InteractsWithExecution;
    use RefreshDatabase;

    private function fixture(): array
    {
        config(['ai.enabled' => true, 'ai.simulated' => false, 'ai.service_key' => str_repeat('a', 64), 'integrations.keys.v1' => base64_encode(random_bytes(32))]);
        $org = $this->signInMember(OrganizationRole::Admin);
        $this->addMember($org, OrganizationRole::Manager);
        $user = auth()->user();
        $workflowId = $this->createWorkflow($org);
        $workflow = Workflow::where('uuid', $workflowId)->firstOrFail();
        $connections = app(AiConnections::class);
        $connections->policy($user->id, $org->id, ['enabled' => true, 'providers' => ['openai'], 'data_categories' => ['workplace_request'], 'max_input_bytes' => 20000, 'max_output_tokens' => 1024, 'daily_organization_units' => 200000, 'daily_user_units' => 100000, 'daily_workflow_units' => 100000, 'max_corrections' => 2, 'retention_days' => 7]);
        $connection = $connections->create($user->id, $org->id, ['name' => 'Synthetic AI', 'provider' => 'openai', 'ownership' => 'organization', 'model' => 'gpt-5.4-mini', 'operations' => ['summarize'], 'max_input_bytes' => 20000, 'max_output_tokens' => 1024, 'daily_units' => 100000], ['api_key' => 'sk-FLOWMV_QA_AI_ONLY_'.str_repeat('x', 30)]);
        $connections->action($user->id, $org->id, $connection, 'approve', []);
        $connections->action($user->id, $org->id, $connection, 'grant', ['workflow_id' => $workflowId, 'operation' => 'summarize']);
        $definition = ExecutionGraphs::approval(fields: [ExecutionGraphs::field('details', 'text')]);
        $aiId = (string) Str::uuid();
        $start = collect($definition['graph_definition']['nodes'])->firstWhere('type', 'start')['id'];
        $approval = collect($definition['graph_definition']['nodes'])->firstWhere('type', 'approval')['id'];
        $data = ['label' => 'AI Summary', 'description' => '', 'connection_id' => $connection, 'source_kind' => 'request', 'document_field' => null, 'field_map' => [], 'configuration' => ['input_fields' => ['details'], 'fields' => [], 'categories' => [], 'routes' => [], 'data_categories' => ['workplace_request']]];
        $definition['graph_definition']['nodes'][] = WorkflowGraphs::node($aiId, 'ai_summarization', 100, 0, $data);
        $definition['graph_definition']['edges'] = array_values(array_filter($definition['graph_definition']['edges'], fn ($edge) => $edge['source'] !== $start));
        $definition['graph_definition']['edges'][] = WorkflowGraphs::edge($start, $aiId, 'out');
        foreach (AiNodes::HANDLES as $handle) {
            $definition['graph_definition']['edges'][] = WorkflowGraphs::edge($aiId, $approval, $handle);
        }
        $base = '/api/v1/organizations/'.$org->uuid.'/workflows/'.$workflowId;
        $this->putJson($base.'/draft', ['revision' => 1, ...$definition])->assertOk();
        $this->postJson($base.'/publish', ['revision' => 2])->assertOk();
        Queue::fake();

        return compact('org', 'user', 'workflow', 'workflowId', 'connection', 'definition', 'aiId');
    }

    public function test_v4_nodes_are_unavailable_to_frozen_earlier_registries(): void
    {
        $registry = app(NodeRegistry::class);
        foreach (array_keys(AiNodes::OPERATIONS) as $type) {
            foreach ([1, 2, 3] as $version) {
                $this->assertFalse($registry->allows($version, $type));
            }
            $this->assertTrue($registry->allows(4, $type));
            $this->assertSame(AiNodes::HANDLES, $registry->sourceHandles($type));
        }
        $this->assertSame([], $registry->typesFor(5));
    }

    public function test_pinned_ai_job_runs_once_and_resumes_to_human_approval(): void
    {
        $f = $this->fixture();
        $this->assertSame(4, $f['workflow']->fresh()->currentPublishedVersion->node_registry_version);
        $this->startInstance($f['org'], $f['workflowId'], ['details' => 'Synthetic leave request.'])->assertCreated();
        $instance = WorkflowInstance::where('workflow_id', $f['workflow']->id)->firstOrFail();
        $this->assertSame(WorkflowInstanceStatus::AwaitingAi, $instance->status);
        Queue::assertPushed(RunAiWorkflowNode::class);
        app(AiWorkflow::class)->request($instance->id, $f['aiId']);
        $id = DB::table('ai_processing_runs')->value('id');
        $this->assertNotNull($id);
        $configuration = json_decode(DB::table('ai_processing_runs')->where('id', $id)->value('configuration'), true);
        ksort($configuration);
        DB::table('ai_processing_runs')->where('id', $id)->update(['configuration' => json_encode($configuration)]);
        $client = new AiClient;
        $client->http->fake(function ($request) {
            if (str_ends_with($request->url(), '/readyz')) {
                return Factory::response(['contract' => 'flowmv.ai.v1', 'audience' => str_repeat('b', 64)]);
            }
            $body = json_decode($request->body(), true);

            return Factory::response(['contract' => 'flowmv.ai.v1', 'request_id' => $body['request_id'], 'provider' => 'openai', 'model' => 'gpt-5.4-mini', 'prompt_version' => 'workplace-v1', 'source' => 'provider_response', 'input_tokens' => 10, 'output_tokens' => 20, 'advice' => ['summary' => 'Review synthetic request.', 'classification' => 'unknown', 'fields' => [], 'missing' => [], 'inconsistent' => [], 'route' => '', 'draft_reply' => '', 'recommendation' => 'review', 'uncertain' => false]]);
        });
        app()->instance(AiClient::class, $client);
        (new ProcessAiRun($id))->handle();
        (new ProcessAiRun($id))->handle();
        $client->http->assertSentCount(2);
        $this->assertSame(WorkflowInstanceStatus::AwaitingApproval, $instance->fresh()->status);
        $this->assertSame(1, $instance->tasks()->count());
        $this->assertDatabaseHas('workflow_events', ['workflow_instance_id' => $instance->id, 'event_type' => 'ai_evaluated']);
        $this->assertSame('Review synthetic request.', app(AiProcessing::class)->result($f['user']->id, $f['org']->id, $id)['advice']['summary']);
    }

    public function test_preview_stops_at_ai_without_transport_jobs_or_domain_writes(): void
    {
        $f = $this->fixture();
        $client = new AiClient;
        $client->http->preventStrayRequests();
        app()->instance(AiClient::class, $client);
        $before = DB::table('integration_audit_events')->count();
        $result = app(WorkflowPreview::class)->run($f['user'], $f['workflow'], $f['definition'], ['details' => 'Synthetic input'], []);
        $this->assertSame('unavailable', $result['result']);
        $this->assertDatabaseCount('ai_processing_runs', 0);
        $this->assertDatabaseCount('workflow_instances', 0);
        $this->assertDatabaseCount('integration_audit_events', $before);
        Queue::assertNothingPushed();
        $client->http->assertNothingSent();
    }

    public function test_connection_revocation_routes_to_human_fallback_without_calling_provider(): void
    {
        $f = $this->fixture();
        $this->startInstance($f['org'], $f['workflowId'], ['details' => 'Synthetic request.'])->assertCreated();
        $instance = WorkflowInstance::where('workflow_id', $f['workflow']->id)->firstOrFail();
        app(AiConnections::class)->action($f['user']->id, $f['org']->id, $f['connection'], 'revoke', []);
        app(AiWorkflow::class)->request($instance->id, $f['aiId']);
        $this->assertSame(WorkflowInstanceStatus::AwaitingApproval, $instance->fresh()->status);
        $this->assertDatabaseCount('ai_processing_runs', 0);
    }
}
