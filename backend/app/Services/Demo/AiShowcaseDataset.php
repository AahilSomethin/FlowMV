<?php

namespace App\Services\Demo;

use App\Actions\WorkflowInstances\StartWorkflowInstance;
use App\Actions\WorkflowInstances\SubmitWorkflowRevision;
use App\Actions\Workflows\CreateWorkflow;
use App\Actions\Workflows\PublishWorkflow;
use App\Actions\Workflows\SaveWorkflowDraft;
use App\Http\Controllers\Api\V1\SlackController;
use App\Jobs\ProcessAiRun;
use App\Models\Organization;
use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowInstance;
use App\Services\AI\AiAccess;
use App\Services\AI\AiConnections;
use App\Services\AI\AiNodes;
use App\Services\AI\AiProcessing;
use App\Services\AI\AiWorkflow;
use App\Services\Integrations\CredentialVault;
use App\Services\Integrations\EmailConnections;
use App\Services\Integrations\EmailDelivery;
use App\Services\Integrations\EmailMessageReader;
use App\Services\Integrations\EmailQaTransport;
use App\Services\Integrations\EmailSync;
use App\Services\Integrations\EmailWorkflow;
use App\Services\Integrations\SlackActivation;
use App\Services\Integrations\SlackApi;
use App\Services\Integrations\SlackQaTransport;
use App\Services\Integrations\SlackTokens;
use App\Services\Integrations\TelegramConnections;
use App\Services\Integrations\TelegramDecisions;
use App\Services\Integrations\TelegramQaTransport;
use App\Services\Workflows\WorkflowTemplates;
use App\Support\DemoEnvironment;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

class AiShowcaseDataset
{
    public const RECIPE = 'mindco-ai-v1';

    private Organization $org;

    private User $admin;

    private User $employee;

    private string $connection;

    public function seed(): array
    {
        app(DemoEnvironment::class)->assertOwned();
        abort_unless(config('ai.enabled') && config('ai.simulated'), 503);
        app(ShowcaseDataset::class)->verify();
        if (DB::table('ai_demo_scenarios')->where('recipe', self::RECIPE)->exists()) {
            return $this->verify();
        }
        abort_if(DB::table('ai_policies')->exists(), 409, 'Existing AI policy found. No showcase settings were overwritten.');
        $baseline = DB::table('demo_datasets')->where('recipe_version', ShowcaseDataset::RECIPE)->firstOrFail();
        $this->org = Organization::findOrFail($baseline->organization_id);
        $this->admin = User::where('email', 'mindco.admin1@example.com')->firstOrFail();
        $this->employee = User::where('email', 'mindco.employee1@example.com')->firstOrFail();
        Queue::fake();
        config(['demo.seeding' => true]);
        DB::transaction(function () {
            $connections = app(AiConnections::class);
            $connections->policy($this->admin->id, $this->org->id, ['enabled' => true, 'providers' => ['openai', 'gemini'], 'data_categories' => ['workplace_request', 'email_body', 'document_text', 'slack_message', 'hr_sensitive'], 'max_input_bytes' => 20000, 'max_output_tokens' => 1024, 'daily_organization_units' => 2000000, 'daily_user_units' => 1000000, 'daily_workflow_units' => 1000000, 'max_corrections' => 2, 'retention_days' => 7]);
            $this->connection = $connections->create($this->admin->id, $this->org->id, $this->settings(ShowcaseLabels::CONNECTION_NAMES['MINDCo simulated OpenAI']), ['api_key' => 'FLOWMV_QA_AI_'.Str::random(40)]);
            $connections->action($this->admin->id, $this->org->id, $this->connection, 'approve', []);
            $connections->test($this->admin->id, $this->org->id, $this->connection);
            $email = $this->emailExample();
            $this->record('email-summarization', $email);
            $doc = DB::table('workflow_document_evidence')->where('workflow_instance_id', $email['instance_id'])->value('document_id');
            abort_unless(is_string($doc), 409);
            $fields = [['id' => 'employee_id', 'label' => 'Employee ID', 'type' => 'identifier', 'required' => true]];
            $this->record('missing-document-fields', $this->manual($email, 'analyze/document', 'document', $doc, $fields));
            $this->record('ai-generated-correction-draft', $this->manual($email, 'draft/reply', 'document', $doc, $fields));
            $incomplete = $this->requestExample('Incomplete leave application', 'extract/fields', 'Employee name: Synthetic Applicant', $fields);
            $this->record('incomplete-leave-application', $incomplete);
            $instance = WorkflowInstance::findOrFail($incomplete['instance_id']);
            $task = $instance->tasks()->where('status', 'open')->where('task_type', 'revision')->firstOrFail();
            app(SubmitWorkflowRevision::class)($this->employee, $task, ['details' => "Employee name: Synthetic Applicant\nEmployee ID: DEMO-901"]);
            $this->drain($instance->id);
            $this->record('revalidated-application', $this->references($instance->fresh()));
            $telegram = $this->requestExample('Manager Telegram summary and approval', 'summarize', 'Synthetic leave request; review original evidence in FlowMV.', [], true);
            $task = WorkflowInstance::findOrFail($telegram['instance_id'])->tasks()->where('status', 'open')->firstOrFail();
            $delivery = DB::table('telegram_approval_deliveries')->where('workflow_task_id', $task->id)->firstOrFail();
            app(TelegramDecisions::class)->deliver($delivery->id);
            $priorCallbacks = DB::table('telegram_decision_callbacks')->pluck('id')->all();
            app(TelegramQaTransport::class)->decision(DB::table('telegram_approval_deliveries')->where('id', $delivery->id)->firstOrFail(), 'approved');
            foreach (DB::table('telegram_decision_callbacks')->where('connection_id', $delivery->connection_id)->whereNotIn('id', $priorCallbacks)->where('status', 'pending')->pluck('id') as $id) {
                app(TelegramDecisions::class)->process($id);
            }
            $this->record('manager-telegram-review', $this->references(WorkflowInstance::findOrFail($telegram['instance_id'])));
            $this->record('slack-natural-language-request', $this->slackExample());
            $this->record('classification-routing-suggestion', $this->requestExample('Classify synthetic request', 'classify', 'leave request for manager_review', [], false, ['leave', 'procurement'], ['manager_review']));
            $this->record('uncertain-human-review', $this->requestExample('Uncertain evidence', 'summarize', 'Synthetic ambiguous request [SIMULATE_UNCERTAIN]'));
            $failure = $this->requestExample('Provider failure and budget fallback', 'summarize', 'Synthetic provider failure [SIMULATE_TIMEOUT]');
            $budgetConnection = $connections->create($this->admin->id, $this->org->id, [...$this->settings(ShowcaseLabels::CONNECTION_NAMES['MINDCo exhausted synthetic budget']), 'daily_units' => 1000], ['api_key' => 'FLOWMV_QA_AI_'.Str::random(40)]);
            $connections->action($this->admin->id, $this->org->id, $budgetConnection, 'approve', []);
            $connections->action($this->admin->id, $this->org->id, $budgetConnection, 'grant', ['workflow_id' => $failure['workflow_uuid'], 'operation' => 'summarize']);
            try {
                app(AiProcessing::class)->request($this->employee->id, $this->org->id, $failure['workflow_id'], $budgetConnection, 'summarize', 'request', $failure['instance_uuid'], $this->configuration([]));
                abort(409, 'Expected the synthetic usage cap to refuse processing.');
            } catch (HttpException $error) {
                abort_unless($error->getStatusCode() === 429, 409);
            }
            $this->record('provider-budget-and-failure', [...$failure, 'budget_refused' => true]);
        }, 3);

        return $this->verify();
    }

    private function settings(string $name): array
    {
        return ['name' => $name, 'provider' => 'openai', 'ownership' => 'organization', 'model' => config('ai.models.openai.0'), 'operations' => AiAccess::OPERATIONS, 'max_input_bytes' => 20000, 'max_output_tokens' => 1024, 'daily_units' => 1000000];
    }

    private function configuration(array $fields, string $category = 'workplace_request', array $categories = [], array $routes = []): array
    {
        return ['input_fields' => $category === 'workplace_request' ? ['details'] : [], 'fields' => $fields, 'categories' => $categories, 'routes' => $routes, 'data_categories' => [$category]];
    }

    private function node(string $type, array $data, int $x = 0): array
    {
        return ['id' => (string) Str::uuid(), 'type' => $type, 'position' => ['x' => $x, 'y' => 0], 'data' => ['label' => ucwords(str_replace('_', ' ', $type)), 'description' => 'Synthetic AI demonstration.', ...$data]];
    }

    private function edge(string $source, string $handle, string $target): array
    {
        return ['id' => (string) Str::uuid(), 'source' => $source, 'source_handle' => $handle, 'target' => $target, 'target_handle' => 'in'];
    }

    private function requestExample(string $name, string $operation, string $text, array $fields = [], bool $telegram = false, array $categories = [], array $routes = []): array
    {
        $type = array_search($operation, AiNodes::OPERATIONS, true);
        $workflow = app(CreateWorkflow::class)($this->admin, $this->org, 'AI demo: '.$name, 'Synthetic providers; human decisions remain authoritative.');
        $this->grant($workflow, $operation);
        $start = $this->node('start', []);
        $ai = $this->node($type, ['connection_id' => $this->connection, 'source_kind' => 'request', 'document_field' => null, 'field_map' => [], 'configuration' => $this->configuration($fields, 'workplace_request', $categories, $routes)], 200);
        $approval = $this->node('approval', ['assignee_roles' => ['manager']], 400);
        $revision = $this->node('revision', ['max_returns' => 2, 'return_to_node_id' => $ai['id']], 600);
        if ($telegram) {
            $bot = DB::table('connections')->where('organization_id', $this->org->id)->where('provider_id', 'telegram')->whereIn('name', ShowcaseLabels::aliases(ShowcaseLabels::CONNECTION_NAMES['MINDCo simulated Telegram']))->where('status', 'enabled')->value('id');
            abort_unless($bot, 409);
            app(TelegramConnections::class)->action($this->admin->id, $this->org->id, $bot, 'grant', ['workflow_id' => $workflow->uuid, 'operation' => 'telegram.approval', 'chat_id' => '888']);
            $approval['data']['telegram_approval'] = ['connection_id' => $bot, 'destination' => '888'];
        }
        $complete = $this->node('end', ['result' => 'completed'], 800);
        $rejected = $this->node('end', ['result' => 'rejected'], 800);
        $graph = ['nodes' => [$start, $ai, $approval, $revision, $complete, $rejected], 'edges' => [$this->edge($start['id'], 'out', $ai['id']), $this->edge($ai['id'], 'complete', $approval['id']), $this->edge($ai['id'], 'missing', $revision['id']), $this->edge($ai['id'], 'uncertain', $approval['id']), $this->edge($ai['id'], 'unavailable', $approval['id']), $this->edge($approval['id'], 'approved', $complete['id']), $this->edge($approval['id'], 'rejected', $rejected['id']), $this->edge($revision['id'], 'return', $ai['id']), $this->edge($revision['id'], 'exhausted', $rejected['id'])]];
        $form = ['fields' => [['id' => 'details', 'type' => 'textarea', 'label' => 'Details', 'description' => 'Synthetic source only.', 'required' => false, 'default_value' => null, 'validation' => [], 'options' => []]]];
        $this->publish($workflow, $graph, $form);
        $instance = app(StartWorkflowInstance::class)($this->employee, $this->org, $workflow->fresh(), ['details' => $text]);
        $this->drain($instance->id);

        return $this->references($instance->fresh());
    }

    private function grant(Workflow $workflow, string $operation): void
    {
        app(AiConnections::class)->action($this->admin->id, $this->org->id, $this->connection, 'grant', ['workflow_id' => $workflow->uuid, 'operation' => $operation]);
    }

    private function publish(Workflow $workflow, array $graph, array $form): void
    {
        app(SaveWorkflowDraft::class)($this->admin, $workflow, 1, $graph, $form);
        app(PublishWorkflow::class)($this->admin, $workflow, 2);
    }

    private function drain(int $instanceId): void
    {
        for ($i = 0; $i < 8; $i++) {
            $instance = WorkflowInstance::findOrFail($instanceId);
            if ($instance->status->value !== 'awaiting_ai') {
                return;
            }
            app(AiWorkflow::class)->request($instanceId, $instance->current_node_id);
            foreach (DB::table('ai_processing_runs')->where('workflow_instance_id', $instanceId)->where('status', 'pending')->pluck('id') as $id) {
                (new ProcessAiRun($id))->handle();
            }
        }
        abort(409, 'AI demonstration transition bound reached.');
    }

    private function references(WorkflowInstance $instance): array
    {
        return ['workflow_id' => $instance->workflow_id, 'workflow_uuid' => $instance->workflow->uuid, 'instance_id' => $instance->id, 'instance_uuid' => $instance->uuid, 'runs' => DB::table('ai_processing_runs')->where('workflow_instance_id', $instance->id)->pluck('id')->all()];
    }

    private function record(string $scenario, array $references): void
    {
        DB::table('ai_demo_scenarios')->insert(['organization_id' => $this->org->id, 'recipe' => self::RECIPE, 'scenario' => $scenario, 'references' => json_encode($references, JSON_THROW_ON_ERROR), 'created_at' => now()]);
    }

    private function request(User $user): Request
    {
        $request = Request::create('/', 'POST');
        $request->setUserResolver(fn () => $user);
        $session = new Store('ai-demo-seeder', new ArraySessionHandler(120));
        $session->start();
        $session->put('integration_step_up.user', $user->id);
        $session->put('integration_step_up.at', now()->timestamp);
        $request->setLaravelSession($session);
        $request->attributes->set('resolved_organization', $this->org);

        return $request;
    }

    private function manual(array $references, string $operation, string $kind, string $source, array $fields): array
    {
        $workflow = Workflow::findOrFail($references['workflow_id']);
        $this->grant($workflow, $operation);
        $id = app(AiProcessing::class)->request($this->employee->id, $this->org->id, $workflow->id, $this->connection, $operation, $kind, $source, $this->configuration($fields, 'document_text'));
        (new ProcessAiRun($id))->handle();

        return [...$references, 'runs' => [$id]];
    }

    private function emailExample(): array
    {
        $email = app(EmailQaTransport::class)->connect($this->request($this->admin), $this->org->id, ['provider' => 'outlook', 'capabilities' => ['read', 'send'], 'name' => ShowcaseLabels::CONNECTION_NAMES['MINDCo simulated mailbox'], 'ownership' => 'organization'], 'mindco.ai.applications@example.com');
        $connections = app(EmailConnections::class);
        $connections->action($this->admin->id, $this->org->id, $email, 'approve', []);
        $connections->action($this->admin->id, $this->org->id, $email, 'approve-destination', ['address' => $this->employee->email]);
        app(EmailSync::class)->process($email);
        $template = DB::table('document_templates')->where('organization_id', $this->org->id)->whereIn('name', [ShowcaseLabels::DOCUMENT_TEMPLATE_NAME, ShowcaseLabels::LEGACY_DOCUMENT_TEMPLATE_NAME])->firstOrFail();
        $templates = app(WorkflowTemplates::class);
        $definition = $templates->definition('email-application', $templates->bind($this->admin, $this->org, 'email-application', ['connection_id' => $email, 'document_template_id' => $template->id, 'email_sender' => $this->employee->email]));
        $workflow = app(CreateWorkflow::class)($this->admin, $this->org, 'AI demo: Email and private document analysis', 'Simulated email and scanner; real DOCX extraction.');
        foreach (['email.receive', 'email.reply'] as $operation) {
            $connections->action($this->admin->id, $this->org->id, $email, 'grant', ['workflow_id' => $workflow->uuid, 'operation' => $operation, 'address' => $this->employee->email]);
        }
        $this->grant($workflow, 'analyze/email');
        $ai = $this->node('ai_email_analysis', ['connection_id' => $this->connection, 'source_kind' => 'email', 'document_field' => null, 'field_map' => [], 'configuration' => $this->configuration([], 'email_body')]);
        $graph = $definition['graph_definition'];
        $trigger = collect($graph['nodes'])->firstWhere('type', 'email_trigger');
        $successor = null;
        foreach ($graph['edges'] as &$edge) {
            if ($edge['source'] === $trigger['id']) {
                $successor = $edge['target'];
                $edge['target'] = $ai['id'];
            }
        }unset($edge);
        $graph['nodes'][] = $ai;
        foreach (['complete', 'missing', 'uncertain', 'unavailable'] as $handle) {
            $graph['edges'][] = $this->edge($ai['id'], $handle, $successor);
        }
        $this->publish($workflow, $graph, $definition['form_definition']);
        app(EmailQaTransport::class)->emit($this->org->id, $email, $this->employee->email, 'missing');
        app(EmailSync::class)->process($email);
        foreach (DB::table('email_fetch_queue')->where('connection_id', $email)->where('status', 'pending')->pluck('id') as $id) {
            app(EmailMessageReader::class)->process($id);
        }
        foreach (DB::table('email_attachment_fetches')->whereIn('message_id', DB::table('email_messages')->where('connection_id', $email)->select('id'))->where('status', 'pending')->pluck('id') as $id) {
            app(EmailMessageReader::class)->attachment($id);
        }
        $intake = DB::table('email_intakes as i')->join('email_trigger_bindings as b', 'b.id', '=', 'i.binding_id')->where('b.workflow_id', $workflow->id)->where('i.status', 'awaiting_confirmation')->select('i.*')->firstOrFail();
        $instance = app(EmailWorkflow::class)->confirm($this->employee, $this->org->id, $intake->id);
        $this->drain($instance->id);

        return $this->references($instance->fresh());
    }

    private function slackExample(): array
    {
        $qa = app(SlackQaTransport::class);
        $connection = DB::table('connections')->where('organization_id', $this->org->id)->where('provider_id', 'slack')->whereIn('name', ShowcaseLabels::aliases(ShowcaseLabels::CONNECTION_NAMES['MINDCo simulated Slack']))->where('status', 'enabled')->value('id');
        abort_unless(is_string($connection), 409);
        $act = function (string $action, array $data = []) use ($connection) {
            $r = $this->request($this->admin);
            $r->merge($data);
            app(SlackController::class)->action($r, $this->org->uuid, $connection, $action, app(SlackApi::class), app(SlackTokens::class));
        };
        $act('approve-channel', ['destination' => 'C90000002']);
        $templates = app(WorkflowTemplates::class);
        $definition = $templates->definition('slack-intake', $templates->bind($this->admin, $this->org, 'slack-intake', ['connection_id' => $connection, 'destination' => 'C90000002']));
        $workflow = app(CreateWorkflow::class)($this->admin, $this->org, 'AI demo: Verified Slack natural-language request', 'Verified synthetic event and linked identity; advisory AI only.');
        foreach (['slack.message', 'slack.notification'] as $operation) {
            $act('grant', ['workflow_id' => $workflow->uuid, 'operation' => $operation, 'destination' => 'C90000002']);
        }
        $this->grant($workflow, 'analyze/slack');
        $ai = $this->node('ai_slack_analysis', ['connection_id' => $this->connection, 'source_kind' => 'slack', 'document_field' => null, 'field_map' => [], 'configuration' => $this->configuration([], 'slack_message')]);
        $graph = $definition['graph_definition'];
        $approval = collect($graph['nodes'])->firstWhere('type', 'approval');
        foreach ($graph['edges'] as &$edge) {
            if ($edge['target'] === $approval['id']) {
                $edge['target'] = $ai['id'];
            }
        }unset($edge);
        $graph['nodes'][] = $ai;
        foreach (['complete', 'missing', 'uncertain', 'unavailable'] as $handle) {
            $graph['edges'][] = $this->edge($ai['id'], $handle, $approval['id']);
        }
        $this->publish($workflow, $graph, $definition['form_definition']);
        $qa->event($connection, 'U90000002', '/request', 'Please request IT access for a synthetic employee.', 'phase11-slack-request', 'C90000002');
        foreach (DB::table('slack_trigger_activations as a')->join('slack_trigger_subscriptions as s', 's.id', '=', 'a.subscription_id')->where('s.workflow_id', $workflow->id)->where('a.status', 'pending')->pluck('a.id') as $id) {
            app(SlackActivation::class)->process($id);
        }
        $instance = WorkflowInstance::where('workflow_id', $workflow->id)->firstOrFail();
        $this->drain($instance->id);

        return $this->references($instance->fresh());
    }

    public function rehearse(bool $verifyOnly = false): array
    {
        app(DemoEnvironment::class)->assertOwned();
        abort_unless(config('ai.enabled') && config('ai.simulated'), 503);
        $this->verify();
        $baseline = DB::table('demo_datasets')->where('recipe_version', ShowcaseDataset::RECIPE)->firstOrFail();
        $this->org = Organization::findOrFail($baseline->organization_id);
        $row = DB::table('ai_demo_scenarios')->where('organization_id', $this->org->id)->where('recipe', 'mindco-ai-leave-v1')->first();
        if (! $row && ! $verifyOnly) {
            $this->admin = User::where('email', 'mindco.admin1@example.com')->firstOrFail();
            $this->employee = User::where('email', 'mindco.employee1@example.com')->firstOrFail();
            $this->connection = DB::table('connections')->where('organization_id', $this->org->id)->whereIn('name', ShowcaseLabels::aliases(ShowcaseLabels::CONNECTION_NAMES['MINDCo simulated OpenAI']))->where('status', 'enabled')->value('id');
            Queue::fake();
            config(['demo.seeding' => true]);
            DB::transaction(function () {
                $mail = app(EmailQaTransport::class)->connect($this->request($this->admin), $this->org->id, ['provider' => 'outlook', 'capabilities' => ['read', 'send'], 'name' => ShowcaseLabels::CONNECTION_NAMES['MINDCo composed AI leave mailbox'], 'ownership' => 'organization'], 'mindco.ai.leave@example.com');
                $email = app(EmailConnections::class);
                $email->action($this->admin->id, $this->org->id, $mail, 'approve', []);
                foreach ([$this->employee->email, 'mindco.hr@example.com'] as $address) {
                    $email->action($this->admin->id, $this->org->id, $mail, 'approve-destination', ['address' => $address]);
                }
                app(EmailSync::class)->process($mail);
                $template = DB::table('document_templates')->where('organization_id', $this->org->id)->whereIn('name', [ShowcaseLabels::DOCUMENT_TEMPLATE_NAME, ShowcaseLabels::LEGACY_DOCUMENT_TEMPLATE_NAME])->firstOrFail();
                $fields = json_decode($template->fields, true);
                $templates = app(WorkflowTemplates::class);
                $definition = $templates->definition('email-application', $templates->bind($this->admin, $this->org, 'email-application', ['connection_id' => $mail, 'document_template_id' => $template->id, 'email_sender' => $this->employee->email]));
                $workflow = app(CreateWorkflow::class)($this->admin, $this->org, 'AI demo: Composed email leave approval', 'One synthetic email, real DOCX extraction, bounded AI correction, linked Telegram decision and fixed employee/HR outcomes.');
                foreach (['email.receive', 'email.reply'] as $operation) {
                    $email->action($this->admin->id, $this->org->id, $mail, 'grant', ['workflow_id' => $workflow->uuid, 'operation' => $operation, 'address' => $this->employee->email]);
                }
                $email->action($this->admin->id, $this->org->id, $mail, 'grant', ['workflow_id' => $workflow->uuid, 'operation' => 'email.send', 'address' => 'mindco.hr@example.com']);
                foreach (['analyze/document', 'extract/fields', 'summarize'] as $operation) {
                    $this->grant($workflow, $operation);
                }
                $graph = $definition['graph_definition'];
                $validation = collect($graph['nodes'])->firstWhere('type', 'document_validation');
                $approval = collect($graph['nodes'])->firstWhere('type', 'approval');
                $correction = collect($graph['nodes'])->first(fn ($node) => $node['type'] === 'email_reply' && str_contains($node['data']['text'], 'validation.missing'));
                $revision = collect($graph['nodes'])->firstWhere('type', 'revision');
                $complete = collect($graph['nodes'])->first(fn ($node) => $node['type'] === 'end' && $node['data']['result'] === 'completed');
                abort_unless($validation && $approval && $correction && $revision && $complete, 409);
                $configuration = ['input_fields' => array_column($definition['form_definition']['fields'], 'id'), 'fields' => $fields, 'categories' => [], 'routes' => [], 'data_categories' => ['workplace_request', 'hr_sensitive']];
                $doc = $this->node('ai_document_analysis', ['connection_id' => $this->connection, 'source_kind' => 'document', 'document_field' => null, 'field_map' => $validation['data']['field_map'], 'configuration' => [...$configuration, 'input_fields' => [], 'data_categories' => ['document_text', 'hr_sensitive']]], 400);
                $doc['id'] = $validation['id'];
                $recheck = $this->node('ai_field_extraction', ['connection_id' => $this->connection, 'source_kind' => 'request', 'document_field' => null, 'field_map' => $validation['data']['field_map'], 'configuration' => $configuration], 600);
                $summary = $this->node('ai_summarization', ['connection_id' => $this->connection, 'source_kind' => 'request', 'document_field' => null, 'field_map' => [], 'configuration' => [...$configuration, 'fields' => []]], 800);
                $hr = $this->node('email_send', ['connection_id' => $mail, 'destination' => 'mindco.hr@example.com', 'subject' => 'Approved leave request outcome', 'text' => 'Request {{request.uuid}}: {{approval.decision}}. {{request.link}}', 'allowed_field_ids' => []], 1600);
                $bot = DB::table('connections')->where('organization_id', $this->org->id)->where('provider_id', 'telegram')->whereIn('name', ShowcaseLabels::aliases(ShowcaseLabels::CONNECTION_NAMES['MINDCo simulated Telegram']))->where('status', 'enabled')->value('id');
                abort_unless($bot, 409);
                app(TelegramConnections::class)->action($this->admin->id, $this->org->id, $bot, 'grant', ['workflow_id' => $workflow->uuid, 'operation' => 'telegram.approval', 'chat_id' => '888']);
                foreach ($graph['nodes'] as &$node) {
                    if ($node['id'] === $validation['id']) {
                        $node = $doc;
                    }
                    if ($node['id'] === $approval['id']) {
                        $node['data']['telegram_approval'] = ['connection_id' => $bot, 'destination' => '888'];
                    }
                    if ($node['id'] === $correction['id']) {
                        $node['data']['text'] = 'Please sign in to FlowMV to review and correct the missing information. {{request.link}}';
                    }
                    if ($node['id'] === $revision['id']) {
                        $node['data']['return_to_node_id'] = $recheck['id'];
                        $node['data']['max_returns'] = 2;
                    }
                }unset($node);
                $graph['nodes'] = [...$graph['nodes'], $recheck, $summary, $hr];
                $graph['edges'] = array_values(array_filter($graph['edges'], fn ($edge) => $edge['source'] !== $doc['id']));
                foreach ($graph['edges'] as &$edge) {
                    if ($edge['source'] === $revision['id'] && $edge['source_handle'] === 'return') {
                        $edge['target'] = $recheck['id'];
                    }
                    if ($edge['target'] === $complete['id']) {
                        $edge['target'] = $hr['id'];
                    }
                }unset($edge);
                foreach (['complete' => $recheck['id'], 'missing' => $correction['id'], 'uncertain' => $approval['id'], 'unavailable' => $approval['id']] as $handle => $target) {
                    $graph['edges'][] = $this->edge($doc['id'], $handle, $target);
                }
                foreach (['complete' => $summary['id'], 'missing' => $correction['id'], 'uncertain' => $approval['id'], 'unavailable' => $approval['id']] as $handle => $target) {
                    $graph['edges'][] = $this->edge($recheck['id'], $handle, $target);
                }
                foreach (['complete', 'missing', 'uncertain', 'unavailable'] as $handle) {
                    $graph['edges'][] = $this->edge($summary['id'], $handle, $approval['id']);
                }
                $graph['edges'][] = $this->edge($hr['id'], 'out', $complete['id']);
                $this->publish($workflow, $graph, $definition['form_definition']);
                app(EmailQaTransport::class)->emit($this->org->id, $mail, $this->employee->email, 'missing');
                app(EmailSync::class)->process($mail);
                foreach (DB::table('email_fetch_queue')->where('connection_id', $mail)->where('status', 'pending')->pluck('id') as $id) {
                    app(EmailMessageReader::class)->process($id);
                }
                foreach (DB::table('email_attachment_fetches')->whereIn('message_id', DB::table('email_messages')->where('connection_id', $mail)->select('id'))->where('status', 'pending')->pluck('id') as $id) {
                    app(EmailMessageReader::class)->attachment($id);
                }
                $intake = DB::table('email_intakes as i')->join('email_trigger_bindings as b', 'b.id', '=', 'i.binding_id')->where('b.workflow_id', $workflow->id)->where('i.status', 'awaiting_confirmation')->select('i.*')->firstOrFail();
                $instance = app(EmailWorkflow::class)->confirm($this->employee, $this->org->id, $intake->id);
                $this->drain($instance->id);
                abort_unless($instance->fresh()->status->value === 'awaiting_revision', 409);
                $revisionTask = $instance->tasks()->where('task_type', 'revision')->where('status', 'open')->firstOrFail();
                app(SubmitWorkflowRevision::class)($this->employee, $revisionTask, ['employee_name' => 'Synthetic Applicant', 'employee_id' => 'DEMO-902', 'start_date' => '2026-11-01', 'end_date' => '2026-11-05']);
                $this->drain($instance->id);
                $task = $instance->tasks()->where('task_type', 'approval')->where('status', 'open')->firstOrFail();
                $delivery = DB::table('telegram_approval_deliveries')->where('workflow_task_id', $task->id)->firstOrFail();
                app(TelegramDecisions::class)->deliver($delivery->id);
                $prior = DB::table('telegram_decision_callbacks')->pluck('id')->all();
                app(TelegramQaTransport::class)->decision(DB::table('telegram_approval_deliveries')->where('id', $delivery->id)->firstOrFail(), 'approved');
                foreach (DB::table('telegram_decision_callbacks')->where('connection_id', $bot)->whereNotIn('id', $prior)->where('status', 'pending')->pluck('id') as $id) {
                    app(TelegramDecisions::class)->process($id);
                }
                foreach (DB::table('email_action_outbox')->where('workflow_instance_id', $instance->id)->where('status', 'pending')->pluck('id') as $id) {
                    app(EmailDelivery::class)->process($id);
                }
                DB::table('ai_demo_scenarios')->insert(['organization_id' => $this->org->id, 'recipe' => 'mindco-ai-leave-v1', 'scenario' => 'email-ai-telegram-outcome', 'references' => json_encode($this->references($instance->fresh()), JSON_THROW_ON_ERROR), 'created_at' => now()]);
            }, 3);
            $row = DB::table('ai_demo_scenarios')->where('organization_id', $this->org->id)->where('recipe', 'mindco-ai-leave-v1')->firstOrFail();
        }
        abort_unless($row, 409);
        $refs = json_decode($row->references, true);
        $instance = WorkflowInstance::where('organization_id', $this->org->id)->findOrFail($refs['instance_id']);
        abort_unless($instance->status->value === 'completed' && $instance->version->node_registry_version === 4, 409);
        abort_unless(DB::table('workflow_submission_snapshots')->where('workflow_instance_id', $instance->id)->where('reason', 'initial')->count() === 1 && DB::table('workflow_submission_snapshots')->where('workflow_instance_id', $instance->id)->where('reason', 'revision_submitted')->count() === 1 && DB::table('workflow_submission_snapshots')->where('workflow_instance_id', $instance->id)->where('reason', 'approval_decision')->count() === 1, 409);
        $accepted = DB::table('email_action_outbox')->where('workflow_instance_id', $instance->id)->where('status', 'accepted')->count();
        abort_unless($accepted === 3 && DB::table('ai_processing_runs')->where('workflow_instance_id', $instance->id)->where('status', 'completed')->where('provenance', 'simulated')->count() === 3, 409);

        return ['recipe' => 'mindco-ai-leave-v1', 'instance' => $instance->uuid, 'state' => 'completed', 'advice_runs' => 3, 'email_actions_accepted' => 3, 'decision_snapshots' => 1, 'transport' => 'simulated', 'live_provider_verification' => 'pending', 'result' => 'verified'];
    }

    public function verify(): array
    {
        app(DemoEnvironment::class)->assertOwned();
        app(ShowcaseDataset::class)->verify();
        $rows = DB::table('ai_demo_scenarios')->where('recipe', self::RECIPE)->get();
        abort_unless($rows->count() === 10, 409);
        $evidence = [];
        foreach ($rows as $row) {
            $refs = json_decode($row->references, true);
            $instance = WorkflowInstance::where('organization_id', $row->organization_id)->findOrFail($refs['instance_id']);
            abort_unless($instance->uuid === $refs['instance_uuid'] && $instance->workflow_id === $refs['workflow_id'] && $instance->version->node_registry_version === 4, 409);
            $states = [];
            $outcomes = [];
            foreach ($refs['runs'] as $id) {
                $run = DB::table('ai_processing_runs')->where('id', $id)->where('organization_id', $row->organization_id)->where('workflow_instance_id', $instance->id)->first();
                abort_unless($run && in_array($run->status, ['completed', 'uncertain', 'failed', 'expired'], true) && ($run->provenance === null || $run->provenance === 'simulated'), 409);
                $states[] = $run->status;
                if ($run->ciphertext !== null) {
                    $advice = app(CredentialVault::class)->decrypt($run, 'ai-result:'.$id)['advice'];
                    $outcomes[] = $advice['outcome'];
                    if ($row->scenario === 'ai-generated-correction-draft') {
                        abort_unless($advice['draft_reply'] !== '', 409);
                    }
                    if ($row->scenario === 'classification-routing-suggestion') {
                        abort_unless($advice['classification'] === 'leave', 409);
                    }
                }
            }
            abort_unless($states !== [], 409);
            if (in_array($row->scenario, ['missing-document-fields', 'incomplete-leave-application'], true) && $outcomes !== []) {
                abort_unless(in_array('missing', $outcomes, true), 409);
            }
            if ($row->scenario === 'revalidated-application' && $outcomes !== []) {
                abort_unless(in_array('complete', $outcomes, true) && $instance->status->value === 'awaiting_approval', 409);
            }
            if ($row->scenario === 'uncertain-human-review') {
                abort_unless(in_array('uncertain', $states, true) || in_array('expired', $states, true), 409);
            }
            if ($row->scenario === 'manager-telegram-review') {
                abort_unless($instance->status->value === 'completed' && $instance->tasks()->where('decision', 'approved')->count() === 1 && DB::table('workflow_submission_snapshots')->where('workflow_instance_id', $instance->id)->where('reason', 'approval_decision')->count() === 1, 409);
            }
            if ($row->scenario === 'provider-budget-and-failure') {
                abort_unless(($refs['budget_refused'] ?? false) && in_array('failed', $states, true), 409);
            }
            if ($row->scenario === 'slack-natural-language-request') {
                abort_unless(DB::table('slack_trigger_activations')->where('workflow_instance_id', $instance->id)->where('status', 'done')->exists(), 409);
            }
            $evidence[$row->scenario] = ['instance' => $instance->uuid, 'state' => $instance->status->value, 'processing' => $states, 'outcomes' => $outcomes];
        }

        return ['recipe' => self::RECIPE, 'scenarios' => 10, 'transport' => 'simulated', 'live_provider_verification' => 'pending', 'result' => 'verified', 'evidence' => $evidence];
    }
}
