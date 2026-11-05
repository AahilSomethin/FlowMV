<?php

namespace App\Services\Demo;

use App\Actions\Organizations\CreateOrganization;
use App\Actions\WorkflowInstances\CompleteWorkflowAssignment;
use App\Actions\WorkflowInstances\DecideWorkflowTask;
use App\Actions\WorkflowInstances\StartWorkflowInstance;
use App\Actions\WorkflowInstances\SubmitWorkflowRevision;
use App\Actions\Workflows\CreateWorkflow;
use App\Actions\Workflows\PublishWorkflow;
use App\Actions\Workflows\SaveWorkflowDraft;
use App\Enums\OrganizationRole;
use App\Http\Controllers\Api\V1\SlackController;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowInstance;
use App\Services\Analytics\OrganizationAnalytics;
use App\Services\Integrations\ConnectionManager;
use App\Services\Integrations\DocumentTemplates;
use App\Services\Integrations\EmailConnections;
use App\Services\Integrations\EmailDelivery;
use App\Services\Integrations\EmailMessageReader;
use App\Services\Integrations\EmailQaTransport;
use App\Services\Integrations\EmailSync;
use App\Services\Integrations\EmailWorkflow;
use App\Services\Integrations\SlackActivation;
use App\Services\Integrations\SlackApi;
use App\Services\Integrations\SlackDelivery;
use App\Services\Integrations\SlackQaTransport;
use App\Services\Integrations\SlackTokens;
use App\Services\Integrations\SmsConnections;
use App\Services\Integrations\SmsDelivery;
use App\Services\Integrations\SmsQaTransport;
use App\Services\Integrations\TelegramConnections;
use App\Services\Integrations\TelegramDecisions;
use App\Services\Integrations\TelegramIdentity;
use App\Services\Integrations\TelegramQaTransport;
use App\Services\Workflows\WorkflowTemplates;
use App\Support\DemoEnvironment;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use RuntimeException;

/** Synthetic fixtures built through ordinary domain services under a controlled clock. */
class ShowcaseDataset
{
    public const RECIPE = 'mindco-v1';

    private array $users = [];

    private Organization $organization;

    private array $connections = [];

    public function seed(): array
    {
        app(DemoEnvironment::class)->assertOwned();
        if (DB::table('demo_datasets')->exists()) {
            return $this->verify();
        }
        if (User::query()->exists() || Organization::query()->exists()) {
            throw new RuntimeException('Unrecognized nonempty demo target. No records changed.');
        }
        $private = rtrim(config('demo.private_root'), '/\\');
        File::ensureDirectoryExists($private, 0700);
        if (File::exists($private.'/accounts.json')) {
            throw new RuntimeException('Private account manifest exists without a dataset. Refusing overwrite.');
        }
        $clock = Carbon::now('UTC')->startOfDay()->subDays(60);
        Queue::fake();
        config(['demo.seeding' => true]);
        try {
            Carbon::setTestNow($clock);
            $manifest = DB::transaction(function () use ($clock, $private): array {
                $accounts = $this->people();
                $this->providers();
                $recipes = ['leave-request' => 16, 'expense-reimbursement' => 16, 'equipment-procurement' => 14, 'it-access' => 14, 'onboarding-checklist' => 12, 'multi-stage-approval' => 12, 'email-application' => 8, 'slack-intake' => 8];
                $workflows = [];
                foreach ($recipes as $recipe => $count) {
                    $workflows[$recipe] = $this->publish($recipe);
                }
                $requests = [];
                $index = 0;
                foreach ($recipes as $recipe => $count) {
                    for ($case = 0; $case < $count; $case++) {
                        Carbon::setTestNow($clock->copy()->addDay()->addHours($index * 14));
                        $instance = match ($recipe) {
                            'email-application' => $this->emailRequest($workflows[$recipe], $case),
                            'slack-intake' => $this->slackRequest($workflows[$recipe], $case),
                            default => $this->internalRequest($workflows[$recipe], $recipe, $case, $index),
                        };
                        $requests[] = $instance->uuid;
                        $index++;
                    }
                }
                Carbon::setTestNow($clock->copy()->addDays(60));
                $manifest = ['organization_uuid' => $this->organization->uuid, 'users' => array_map(fn ($a) => array_diff_key($a, ['password' => true]), $accounts), 'workflows' => array_map(fn ($w) => $w->uuid, $workflows), 'requests' => $requests, 'transport' => 'simulated', 'scanner' => 'simulated', 'extraction' => 'real DOCX', 'clock_start' => $clock->toIso8601String()];
                DB::table('demo_datasets')->insert(['id' => (string) Str::uuid(), 'organization_id' => $this->organization->id, 'recipe_version' => self::RECIPE, 'state' => 'ready', 'manifest' => json_encode($manifest, JSON_THROW_ON_ERROR), 'created_at' => now()]);
                $this->verify();
                File::put($private.'/accounts.json', json_encode(['notice' => 'Local synthetic accounts. Do not publish.', 'organization' => $this->organization->uuid, 'accounts' => $accounts], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
                chmod($private.'/accounts.json', 0600);

                return $manifest;
            });

            return ['recipe' => self::RECIPE, 'users' => 24, 'workflows' => 8, 'requests' => count($manifest['requests']), 'result' => 'seeded'];
        } finally {
            Carbon::setTestNow();
            config(['demo.seeding' => false]);
        }
    }

    private function people(): array
    {
        $departments = ['People', 'Finance', 'Operations', 'IT', 'Client Services'];
        $accounts = [];
        foreach (['admin' => 2, 'manager' => 6, 'employee' => 16] as $role => $count) {
            for ($i = 1; $i <= $count; $i++) {
                $password = Str::password(24);
                $user = User::factory()->create(['name' => ucfirst($role).' '.str_pad((string) $i, 2, '0', STR_PAD_LEFT), 'email' => "mindco.$role$i@example.com", 'email_verified_at' => now(), 'password' => $password]);
                $this->users[$role][] = $user;
                if ($role === 'admin' && $i === 1) {
                    $membership = app(CreateOrganization::class)($user, 'MINDCo Demonstration', 'mindco-showcase');
                    $this->organization = $membership->organization;
                } else {
                    OrganizationMembership::factory()->create(['user_id' => $user->id, 'organization_id' => $this->organization->id, 'role' => OrganizationRole::from($role)]);
                    $user->forceFill(['current_organization_id' => $this->organization->id])->save();
                }
                $accounts[] = ['name' => $user->name, 'email' => $user->email, 'role' => $role, 'department' => $departments[count($accounts) % 5], 'password' => $password];
            }
        }
        $this->organization->forceFill(['admin_count' => 2])->save();

        return $accounts;
    }

    private function request(User $user, array $data = []): Request
    {
        $r = Request::create('/', 'POST', $data);
        $r->setUserResolver(fn () => $user);
        $session = new Store('demo-seeder', new ArraySessionHandler(120));
        $session->start();
        $session->put('integration_step_up.user', $user->id);
        $session->put('integration_step_up.at', now()->timestamp);
        $r->setLaravelSession($session);
        $r->attributes->set('resolved_organization', $this->organization);

        return $r;
    }

    private function slackAction(string $action, array $data = []): void
    {
        app(SlackController::class)->action($this->request($this->users['admin'][0], $data), $this->organization->uuid, $this->connections['slack'], $action, app(SlackApi::class), app(SlackTokens::class));
    }

    private function providers(): void
    {
        $admin = $this->users['admin'][0];
        $org = $this->organization->id;
        foreach (['telegram', 'slack', 'outlook'] as $provider) {
            DB::table('integration_approvals')->updateOrInsert(['organization_id' => $org, 'provider_id' => $provider], ['approved' => true]);
            app(ConnectionManager::class)->audit($org, null, $admin->id, $provider.'.provider.policy');
        }
        $telegram = app(TelegramConnections::class);
        $id = $this->connections['telegram'] = $telegram->create($admin->id, $org, ['name' => 'MINDCo simulated Telegram', 'ownership' => 'organization'], ['bot_token' => '900010:'.str_repeat('D', 40).'_FLOWMV_QA_ONLY']);
        foreach (['approve', 'configure-webhook', 'approve-destination'] as $action) {
            $telegram->action($admin->id, $org, $id, $action, ['chat_id' => '888', 'label' => 'Synthetic Manager private chat']);
        }
        $command = app(TelegramIdentity::class)->begin($this->users['manager'][0]->id, $org, $id);
        app(TelegramQaTransport::class)->emit($id, '888', '888', $command);
        app(TelegramIdentity::class)->confirm($this->users['manager'][0]->id, $org, $id);
        $this->connections['slack'] = app(SlackQaTransport::class)->connect($this->request($admin), $org, ['name' => 'MINDCo simulated Slack', 'ownership' => 'organization']);
        $this->slackAction('approve');
        $this->slackAction('approve-channel', ['destination' => 'C90000001']);
        $member = $this->users['employee'][0];
        $command = app(SlackController::class)->identity($this->request($member), $this->organization->uuid, $this->connections['slack'], 'start')->getData(true)['data']['command'];
        app(SlackQaTransport::class)->event($this->connections['slack'], 'U90000002', '/flowmv_link', substr($command, strlen('/flowmv_link ')), 'showcase-link');
        app(SlackController::class)->identity($this->request($member), $this->organization->uuid, $this->connections['slack'], 'confirm');
        $id = $this->connections['email'] = app(EmailQaTransport::class)->connect($this->request($admin), $org, ['provider' => 'outlook', 'capabilities' => ['read', 'send'], 'name' => 'MINDCo simulated applications mailbox', 'ownership' => 'organization'], 'mindco.applications@example.com');
        app(EmailConnections::class)->action($admin->id, $org, $id, 'approve', []);
        app(EmailConnections::class)->action($admin->id, $org, $id, 'approve-destination', ['address' => $member->email]);
        app(EmailSync::class)->process($id);
        $this->connections['document_template'] = app(DocumentTemplates::class)->create($org, $admin->id, 'MINDCo application', [
            ['id' => 'employee_name', 'label' => 'Employee name', 'type' => 'text', 'required' => true], ['id' => 'employee_id', 'label' => 'Employee ID', 'type' => 'identifier', 'required' => true],
            ['id' => 'start_date', 'label' => 'Start date', 'type' => 'date', 'required' => true], ['id' => 'end_date', 'label' => 'End date', 'type' => 'date', 'required' => true, 'after_or_equal_to' => 'start_date'],
        ]);
        $sms = app(SmsConnections::class);
        $sms->policy($admin->id, $org, ['approved' => true, 'enabled' => true, 'allowed_countries' => ['US'], 'per_minute' => 10, 'daily_limit' => 50, 'monthly_limit' => 100, 'recipient_daily_limit' => 10]);
        $id = $this->connections['sms'] = $sms->create($admin->id, $org, 'MINDCo simulated SMS', ['account_sid' => SmsQaTransport::ACCOUNT, 'auth_token' => SmsQaTransport::TOKEN]);
        $sms->action($admin->id, $org, $id, 'approve', []);
        $this->connections['sender'] = $sms->action($admin->id, $org, $id, 'approve-sender', ['label' => 'Synthetic sender'], [], '+15005550006');
        $this->connections['recipient'] = $sms->action($admin->id, $org, $id, 'approve-recipient', ['label' => 'Synthetic recipient', 'consent' => true], [], '+15005550009');
        $this->connections['sms_template'] = $sms->template($admin->id, $org, 'Minimal outcome', 'FlowMV request {{request.uuid}}: {{approval.decision}}');
    }

    private function publish(string $recipe): Workflow
    {
        $admin = $this->users['admin'][0];
        $org = $this->organization;
        $templates = app(WorkflowTemplates::class);
        $bindings = match ($recipe) {
            'email-application' => ['connection_id' => $this->connections['email'], 'document_template_id' => $this->connections['document_template'], 'email_sender' => $this->users['employee'][0]->email],
            'slack-intake' => ['connection_id' => $this->connections['slack'], 'destination' => 'C90000001'], default => [],
        };
        $definition = $templates->definition($recipe, $templates->bind($admin, $org, $recipe, $bindings));
        $workflow = app(CreateWorkflow::class)($admin, $org, $templates->recipe($recipe)[0], 'Synthetic showcase. Provider transports and scanning are simulated.');
        if ($recipe === 'email-application') {
            foreach (['email.receive', 'email.reply'] as $operation) {
                app(EmailConnections::class)->action($admin->id, $org->id, $this->connections['email'], 'grant', ['workflow_id' => $workflow->uuid, 'operation' => $operation, 'address' => $this->users['employee'][0]->email]);
            }
        }
        if ($recipe === 'slack-intake') {
            $graph = &$definition['graph_definition'];
            foreach ($graph['nodes'] as &$node) {
                if ($node['type'] === 'approval') {
                    $node['data']['telegram_approval'] = ['connection_id' => $this->connections['telegram'], 'destination' => '888'];
                }
            } unset($node);
            $notification = collect($graph['nodes'])->firstWhere('type', 'slack_notification');
            $end = collect($graph['nodes'])->first(fn ($n) => $n['type'] === 'end' && $n['data']['result'] === 'completed');
            $smsId = (string) Str::uuid();
            $graph['nodes'][] = ['id' => $smsId, 'type' => 'sms_notification', 'position' => ['x' => 600, 'y' => 660], 'data' => ['label' => 'Minimal SMS outcome', 'description' => '', 'connection_id' => $this->connections['sms'], 'sender_id' => $this->connections['sender'], 'recipient_id' => $this->connections['recipient'], 'template_id' => $this->connections['sms_template']]];
            foreach ($graph['edges'] as &$edge) {
                if ($edge['source'] === $notification['id'] && $edge['target'] === $end['id']) {
                    $edge['target'] = $smsId;
                }
            } unset($edge);
            $graph['edges'][] = ['id' => (string) Str::uuid(), 'source' => $smsId, 'source_handle' => 'out', 'target' => $end['id'], 'target_handle' => 'in'];
            foreach (['slack.message', 'slack.notification'] as $operation) {
                $this->slackAction('grant', ['workflow_id' => $workflow->uuid, 'operation' => $operation, 'destination' => 'C90000001']);
            }
            app(TelegramConnections::class)->action($admin->id, $org->id, $this->connections['telegram'], 'grant', ['workflow_id' => $workflow->uuid, 'operation' => 'telegram.approval', 'chat_id' => '888']);
            app(SmsConnections::class)->action($admin->id, $org->id, $this->connections['sms'], 'grant', ['workflow_id' => $workflow->uuid, 'operation' => 'sms.notification', 'sender_id' => $this->connections['sender'], 'recipient_id' => $this->connections['recipient'], 'template_id' => $this->connections['sms_template']]);
        }
        $workflow = app(SaveWorkflowDraft::class)($admin, $workflow, $workflow->draft->revision, $definition['graph_definition'], $definition['form_definition']);

        return app(PublishWorkflow::class)($admin, $workflow, $workflow->draft->revision);
    }

    private function internalRequest(Workflow $workflow, string $recipe, int $case, int $index): WorkflowInstance
    {
        $data = ['details' => 'Synthetic '.$recipe.' case '.($case + 1)];
        if ($recipe === 'leave-request') {
            $data += ['start_date' => now()->addDays(5)->toDateString(), 'end_date' => now()->addDays(8)->toDateString()];
        }
        if ($recipe === 'expense-reimbursement') {
            $data['amount'] = $case % 2 === 0 ? 1500 : 250;
        }
        $instance = app(StartWorkflowInstance::class)($this->users['employee'][$index % 16], $this->organization, $workflow, $data);
        if ($case === 0) {
            return $instance;
        }

        return $this->finish($instance, $case === 1 || ($case === 2 && in_array($recipe, ['leave-request', 'expense-reimbursement'], true)), $case === 2 || $case === 3);
    }

    private function finish(WorkflowInstance $instance, bool $reject = false, bool $leaveAssignment = false): WorkflowInstance
    {
        for ($step = 0; $step < 8; $step++) {
            $task = $instance->tasks()->where('status', 'open')->first();
            if (! $task) {
                break;
            }
            Carbon::setTestNow(now()->addMinutes(20));
            if ($task->task_type->value === 'assignment') {
                if ($leaveAssignment) {
                    break;
                }
                $actor = $task->assigned_user_id ? User::findOrFail($task->assigned_user_id) : $this->users[$task->assigned_role][1];
                $instance = app(CompleteWorkflowAssignment::class)($actor, $task);
            } elseif ($task->task_type->value === 'approval') {
                $actor = $task->assigned_user_id ? User::findOrFail($task->assigned_user_id) : $this->users[$task->assigned_role ?? 'manager'][0];
                $instance = app(DecideWorkflowTask::class)($actor, $task, $reject ? 'rejected' : 'approved');
                $reject = false;
            } else {
                break;
            }
        }

        return $instance->fresh();
    }

    private function emailRequest(Workflow $workflow, int $case): WorkflowInstance
    {
        $org = $this->organization->id;
        app(EmailQaTransport::class)->emit($org, $this->connections['email'], $this->users['employee'][0]->email, 'missing');
        app(EmailSync::class)->process($this->connections['email']);
        foreach (DB::table('email_fetch_queue')->where('status', 'pending')->pluck('id') as $id) {
            app(EmailMessageReader::class)->process($id);
        }
        foreach (DB::table('email_attachment_fetches')->where('status', 'pending')->pluck('id') as $id) {
            app(EmailMessageReader::class)->attachment($id);
        }
        $intake = DB::table('email_intakes')->where('organization_id', $org)->where('status', 'awaiting_confirmation')->first();
        if (! $intake) {
            throw new RuntimeException('Synthetic email did not produce an authorized intake.');
        }
        $instance = app(EmailWorkflow::class)->confirm($this->users['employee'][0], $org, $intake->id);
        $this->deliver();
        if (in_array($case, [4, 5], true)) {
            return $instance;
        }
        Carbon::setTestNow(now()->addMinutes(30));
        $task = $instance->tasks()->where('status', 'open')->where('task_type', 'revision')->firstOrFail();
        $instance = app(SubmitWorkflowRevision::class)($this->users['employee'][0], $task, ['employee_name' => 'Synthetic Applicant', 'employee_id' => 'DEMO-900', 'start_date' => '2026-11-01', 'end_date' => '2026-11-05']);
        if ($case !== 6) {
            $instance = $this->finish($instance, $case === 7);
        }
        $this->deliver();

        return $instance;
    }

    private function slackRequest(Workflow $workflow, int $case): WorkflowInstance
    {
        app(SmsConnections::class)->action($this->users['admin'][0]->id, $this->organization->id, $this->connections['sms'], 'approve-recipient', ['label' => 'Synthetic recipient', 'consent' => true], [], '+15005550009');
        app(SlackQaTransport::class)->event($this->connections['slack'], 'U90000002', '/request', 'Synthetic cross-channel request '.($case + 1), 'showcase-request-'.$case);
        foreach (DB::table('slack_trigger_activations')->where('status', 'pending')->pluck('id') as $id) {
            app(SlackActivation::class)->process($id);
        }
        $instance = WorkflowInstance::query()->where('workflow_id', $workflow->id)->latest('id')->firstOrFail();
        Carbon::setTestNow(now()->addMinute());
        $task = $instance->tasks()->where('status', 'open')->firstOrFail();
        $delivery = DB::table('telegram_approval_deliveries')->where('workflow_task_id', $task->id)->firstOrFail();
        app(TelegramDecisions::class)->deliver($delivery->id);
        if ($case < 6) {
            Carbon::setTestNow(now()->addMinute());
            app(TelegramQaTransport::class)->decision(DB::table('telegram_approval_deliveries')->where('id', $delivery->id)->first(), $case === 5 ? 'rejected' : 'approved');
            foreach (DB::table('telegram_decision_callbacks')->where('status', 'pending')->pluck('id') as $id) {
                app(TelegramDecisions::class)->process($id);
            }
            $this->deliver();
        }

        return $instance->fresh();
    }

    private function deliver(): void
    {
        foreach (['email_action_outbox' => EmailDelivery::class, 'slack_action_outbox' => SlackDelivery::class, 'sms_action_outbox' => SmsDelivery::class] as $table => $service) {
            foreach (DB::table($table)->where('status', 'pending')->where('available_at', '<=', now())->pluck('id') as $id) {
                app($service)->process($id);
            }
        }
    }

    public function verify(): array
    {
        app(DemoEnvironment::class)->assertOwned();
        $row = DB::table('demo_datasets')->where('recipe_version', self::RECIPE)->first();
        if (! $row || $row->state !== 'ready') {
            throw new RuntimeException('No complete owned showcase dataset.');
        }
        $manifest = json_decode($row->manifest, true, flags: JSON_THROW_ON_ERROR);
        $org = Organization::findOrFail($row->organization_id);
        $failures = [];
        $baselineWorkflows = $org->workflows()->whereIn('uuid', array_values($manifest['workflows']));
        $baselineInstances = $org->workflowInstances()->whereIn('uuid', $manifest['requests']);
        $baselineIds = (clone $baselineInstances)->pluck('id');
        foreach (['admin' => 2, 'manager' => 6, 'employee' => 16] as $role => $expected) {
            if ($org->activeMemberships()->where('role', $role)->count() !== $expected) {
                $failures[] = 'membership counts';
            }
        }
        if ($org->admin_count !== 2 || (clone $baselineWorkflows)->where('status', 'published')->count() !== 8 || (clone $baselineInstances)->count() !== 100) {
            $failures[] = 'dataset counts';
        }
        if (count(array_unique(array_column($manifest['users'], 'department'))) !== 5) {
            $failures[] = 'department metadata';
        }
        foreach ((clone $baselineInstances)->with(['tasks', 'events'])->get() as $instance) {
            if ($instance->workflow->organization_id !== $org->id || $instance->version->workflow_id !== $instance->workflow_id) {
                $failures[] = 'version ownership';
            }
            $previous = $instance->created_at;
            foreach ($instance->events->sortBy('id') as $event) {
                if ($event->created_at->lt($previous)) {
                    $failures[] = 'event chronology';
                } $previous = $event->created_at;
            }
            foreach ($instance->tasks as $task) {
                if ($task->completed_at && $task->completed_at->lt($task->assigned_at)) {
                    $failures[] = 'task chronology';
                }
                if ($task->completed_by && ! $org->activeMemberships()->where('user_id', $task->completed_by)->exists()) {
                    $failures[] = 'decision actor membership';
                }
                if ($task->completed_by && $task->assigned_role && ! $org->activeMemberships()->where('user_id', $task->completed_by)->where('role', $task->assigned_role)->exists()) {
                    $failures[] = 'decision actor role';
                }
                if ($task->completed_by && $task->assigned_user_id && $task->completed_by !== $task->assigned_user_id) {
                    $failures[] = 'decision assignee';
                }
                if ($task->task_type->value === 'approval' && $task->status->value === 'completed' && DB::table('workflow_submission_snapshots')->where('workflow_task_id', $task->id)->where('reason', 'approval_decision')->count() !== 1) {
                    $failures[] = 'decision snapshots';
                }
            }
            if (DB::table('workflow_submission_snapshots')->where('workflow_instance_id', $instance->id)->where('reason', 'initial')->count() !== 1) {
                $failures[] = 'initial snapshots';
            }
        }
        $statuses = (clone $baselineInstances)->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status')->map(fn ($n) => (int) $n)->all();
        $expected = ['awaiting_approval' => 9, 'awaiting_assignment' => 6, 'awaiting_revision' => 2, 'completed' => 73, 'rejected' => 10];
        ksort($statuses);
        ksort($expected);
        if ($statuses !== $expected) {
            $failures[] = 'historical outcomes';
        }
        if (array_diff($manifest['requests'], $org->workflowInstances()->pluck('uuid')->all()) !== [] || array_diff($manifest['workflows'], $org->workflows()->pluck('uuid')->all()) !== []) {
            $failures[] = 'manifest identity';
        }
        if (DB::table('workflow_versions')->whereIn('workflow_id', (clone $baselineWorkflows)->pluck('id'))->count() !== 8) {
            $failures[] = 'single published versions';
        }
        foreach (['slack_action_outbox' => 5, 'sms_action_outbox' => 5, 'email_action_outbox' => 12] as $table => $expectedCount) {
            $accepted = $table === 'sms_action_outbox' ? ['accepted', 'sent', 'delivered'] : ['accepted'];
            if (DB::table($table)->where('organization_id', $org->id)->whereIn('workflow_instance_id', $baselineIds)->whereIn('status', $accepted)->count() !== $expectedCount) {
                $failures[] = $table.' simulated acceptance';
            }
        }
        if (DB::table('integration_audit_events')->where('organization_id', $org->id)->where('evidence_source', 'provider_response')->exists()) {
            $failures[] = 'fabricated live provenance';
        }
        $analytics = app(OrganizationAnalytics::class)->summary($org->creator, $org, Carbon::parse($manifest['clock_start']), now()->addDay(), null, null);
        $workspaceStatuses = $org->workflowInstances()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');
        if ($analytics['cohort']['total'] !== $org->workflowInstances()->count() || $analytics['completion']['completed'] !== (int) $workspaceStatuses->get('completed', 0) || $analytics['rejection']['rejected'] !== (int) $workspaceStatuses->get('rejected', 0)) {
            $failures[] = 'analytics agreement';
        }
        if ($failures !== []) {
            throw new RuntimeException('Demo integrity failed: '.implode(', ', array_unique($failures)));
        }

        return ['recipe' => self::RECIPE, 'users' => 24, 'workflows' => 8, 'requests' => 100, 'statuses' => $statuses, 'workspace_requests' => $org->workflowInstances()->count(), 'result' => 'verified'];
    }
}
