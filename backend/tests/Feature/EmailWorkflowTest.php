<?php

namespace Tests\Feature;

use App\Enums\OrganizationRole;
use App\Models\User;
use App\Models\WorkflowInstance;
use App\Models\WorkflowVersion;
use App\Services\Integrations\CredentialVault;
use App\Services\Integrations\DocumentScanner;
use App\Services\Integrations\DocumentTemplates;
use App\Services\Integrations\EmailConnections;
use App\Services\Integrations\EmailDelivery;
use App\Services\Integrations\EmailHttp;
use App\Services\Integrations\EmailMessageReader;
use App\Services\Integrations\EmailSync;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Feature\Workflows\InteractsWithExecution;
use Tests\Support\ExecutionGraphs;
use Tests\Support\WorkflowGraphs;
use Tests\TestCase;

class EmailWorkflowTest extends TestCase
{
    use InteractsWithExecution { setUp as protected spaSetUp; }
    use RefreshDatabase;

    private EmailHttp $http;

    private string $sender = '';

    private string $document = '';

    private string $subject = 'Application request';

    private bool $automated = false;

    private array $deliveries = [];

    private array $sendResponses = [];

    protected function setUp(): void
    {
        $this->spaSetUp();
        Queue::fake();
        Storage::fake('integration_documents');
        config(['email_integrations.enabled' => true, 'integrations.keys.v1' => base64_encode(random_bytes(32))]);
        $this->app->instance(DocumentScanner::class, new class implements DocumentScanner
        {
            public function scan(#[\SensitiveParameter] string $bytes): string
            {
                return 'clean';
            }
        });
        $this->http = app(EmailHttp::class);
        $this->http->http->preventStrayRequests();
        $this->http->http->fake(function ($r) {
            $path = parse_url($r->url(), PHP_URL_PATH);
            if (str_ends_with($path, '/messages/send')) {
                $raw = base64_decode(strtr($r['raw'], '-_', '+/'), true);
                $this->deliveries[] = $raw;
                if ($this->sendResponses !== []) {
                    $response = array_shift($this->sendResponses);
                    if ($response instanceof \Throwable) {
                        throw $response;
                    }

                    return $response;
                }

                return Factory::response(['id' => 'synthetic-send-id']);
            }
            if (str_ends_with($path, '/history')) {
                return Factory::response(['historyId' => '200', 'history' => [['messagesAdded' => [['message' => ['id' => 'synthetic-message']]]]]]);
            }
            if (str_ends_with($path, '/attachments/synthetic-file')) {
                return Factory::response(['data' => rtrim(strtr(base64_encode($this->document), '+/', '-_'), '=')]);
            }
            if (str_ends_with($path, '/messages/synthetic-message')) {
                $headers = [['name' => 'From', 'value' => $this->sender], ['name' => 'To', 'value' => 'mailbox@example.com'], ['name' => 'Subject', 'value' => $this->subject], ['name' => 'Message-ID', 'value' => '<synthetic-message@example.test>']];
                if ($this->automated) {
                    $headers[] = ['name' => 'Auto-Submitted', 'value' => 'auto-generated'];
                }

                return Factory::response(['id' => 'synthetic-message', 'threadId' => 'synthetic-thread', 'internalDate' => (string) now()->getTimestampMs(), 'payload' => ['headers' => $headers, 'parts' => [['mimeType' => 'text/plain', 'body' => ['data' => base64_encode('Synthetic private email body')]], ['mimeType' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'filename' => 'application.docx', 'body' => ['attachmentId' => 'synthetic-file']]]]]);
            }

            return Factory::response([], 503);
        });
    }

    private function login(User $user): void
    {
        $this->withCredentials(false);
        $this->signIn($user);
        $this->withCookie(config('session.cookie'), session()->getId());
        $this->withCredentials();
    }

    private function docx(bool $complete = false): string
    {
        $path = tempnam(storage_path('framework/testing'), 'email-doc');
        $zip = new \ZipArchive;
        $zip->open($path, \ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/></Types>');
        $zip->addFromString('_rels/.rels', '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="r1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/></Relationships>');
        $lines = ['Employee name: Synthetic Private Name', 'Start date: 2026-11-01', 'End date: 2026-11-03'];
        if ($complete) {
            $lines[] = 'Employee ID: QA-123';
        }
        $body = '';
        foreach ($lines as $line) {
            $body .= '<w:p><w:r><w:t>'.htmlspecialchars($line, ENT_XML1 | ENT_QUOTES, 'UTF-8').'</w:t></w:r></w:p>';
        }
        $zip->addFromString('word/document.xml', '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body>'.$body.'</w:body></w:document>');
        $zip->close();
        $bytes = file_get_contents($path);
        unlink($path);

        return $bytes;
    }

    private function fixture(bool $complete = false): array
    {
        $org = $this->signInMember(OrganizationRole::Admin);
        $admin = User::findOrFail($org->created_by);
        $manager = $this->addMember($org, OrganizationRole::Manager);
        $id = (string) Str::uuid();
        $this->sender = $admin->email;
        $this->document = $this->docx($complete);
        DB::table('integration_approvals')->insert(['organization_id' => $org->id, 'provider_id' => 'gmail', 'approved' => true]);
        DB::table('connections')->insert(['id' => $id, 'organization_id' => $org->id, 'provider_id' => 'gmail', 'name' => 'Application mailbox', 'credential_type' => 'oauth', 'status' => 'enabled', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('email_mailboxes')->insert(['connection_id' => $id, 'address' => 'mailbox@example.com', 'account_id' => 'synthetic-account', 'active_identity' => hash('sha256', $id), 'capabilities' => json_encode(['read', 'send']), 'approved_by' => $admin->id, 'token_expires_at' => now()->addHour(), 'health' => 'ready', 'connected_at' => now()->subMinute()]);
        $vault = app(CredentialVault::class);
        DB::table('credential_secrets')->insert(['connection_id' => $id, ...$vault->encrypt(['access_token' => 'synthetic-only-access', 'refresh_token' => 'synthetic-only-refresh'], $id), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('email_sync_state')->insert(['connection_id' => $id, ...$vault->encrypt(['cursor' => '100', 'next' => null, 'subscription_secret' => null], 'email-sync:'.$id), 'watch_expires_at' => now()->addDays(2), 'available_at' => now(), 'requested' => true, 'created_at' => now(), 'updated_at' => now()]);
        $fields = [['id' => 'employee_name', 'label' => 'Employee name', 'type' => 'text', 'required' => true], ['id' => 'employee_id', 'label' => 'Employee ID', 'type' => 'identifier', 'required' => true], ['id' => 'start_date', 'label' => 'Start date', 'type' => 'date', 'required' => true], ['id' => 'end_date', 'label' => 'End date', 'type' => 'date', 'required' => true, 'after_or_equal_to' => 'start_date']];
        $template = app(DocumentTemplates::class)->create($org->id, $admin->id, 'Generic application', $fields);
        $workflow = $this->createWorkflow($org, 'Email document approval');
        $workflowId = DB::table('workflows')->where('uuid', $workflow)->value('id');
        foreach ([['email.receive', ''], ['email.reply', $admin->email], ['email.send', 'hr@example.com']] as [$operation,$destination]) {
            if ($destination !== '') {
                DB::table('email_destinations')->insert(['connection_id' => $id, 'address' => $destination, 'approved_by' => $admin->id]);
            }
            DB::table('email_workflow_grants')->insert(['connection_id' => $id, 'organization_id' => $org->id, 'workflow_id' => $workflowId, 'operation' => $operation, 'destination' => $destination, 'authorized_by' => $admin->id]);
        }
        $node = [];
        foreach (['trigger', 'filter', 'inspect', 'validate', 'reply', 'revision', 'approval', 'send', 'outcome', 'done', 'rejected'] as $key) {
            $node[$key] = (string) Str::uuid();
        }
        $map = [];
        $input = [];
        $form = [];
        foreach ($fields as $f) {
            $map[$f['id']] = $f['id'];
            $input[$f['id']] = 'document.'.$f['id'];
            $form[] = ExecutionGraphs::field($f['id'], $f['type'] === 'date' ? 'date' : 'text', ['required' => false]);
        }
        $definition = ['graph_definition' => ['nodes' => [
            WorkflowGraphs::node($node['trigger'], 'email_trigger', 0, 0, ['label' => 'Email Trigger', 'connection_id' => $id, 'input_mapping' => $input, 'rules' => [], 'match' => 'all']),
            WorkflowGraphs::node($node['filter'], 'email_filter', 100, 0, ['label' => 'Email Filter', 'rules' => [['field' => 'subject', 'operator' => 'contains', 'value' => 'application']], 'match' => 'all']),
            WorkflowGraphs::node($node['inspect'], 'attachment_inspector', 200, 0, ['label' => 'Attachment Inspector', 'require_attachments' => true]),
            WorkflowGraphs::node($node['validate'], 'document_validation', 300, 0, ['label' => 'Document Validation', 'template_id' => $template, 'document_fields' => $fields, 'field_map' => $map, 'require_document' => true]),
            WorkflowGraphs::node($node['reply'], 'email_reply', 300, 150, ['label' => 'Request missing information', 'connection_id' => $id, 'destination' => $admin->email, 'text' => 'Please supply {{validation.missing}} using {{request.link}}.', 'allowed_field_ids' => []]),
            WorkflowGraphs::node($node['revision'], 'revision', 400, 150, ['label' => 'Correct application', 'max_returns' => 3, 'return_to_node_id' => $node['validate']]),
            WorkflowGraphs::node($node['approval'], 'approval', 400, 0, ['label' => 'Manager approval', 'assignee_roles' => ['manager']]),
            WorkflowGraphs::node($node['send'], 'email_send', 500, 0, ['label' => 'Notify HR', 'connection_id' => $id, 'destination' => 'hr@example.com', 'subject' => 'Application decision', 'text' => 'Request {{request.uuid}}: {{approval.decision}}.', 'allowed_field_ids' => []]),
            WorkflowGraphs::node($node['outcome'], 'email_reply', 550, 0, ['label' => 'Notify applicant', 'connection_id' => $id, 'destination' => $admin->email, 'text' => 'Your request {{request.uuid}} was {{approval.decision}}. {{request.link}}', 'allowed_field_ids' => []]),
            WorkflowGraphs::node($node['done'], 'end', 600, 0, ['label' => 'Completed', 'result' => 'completed']),
            WorkflowGraphs::node($node['rejected'], 'end', 600, 150, ['label' => 'Rejected', 'result' => 'rejected']),
        ], 'edges' => [
            WorkflowGraphs::edge($node['trigger'], $node['filter'], 'out'), WorkflowGraphs::edge($node['filter'], $node['inspect'], 'matched'), WorkflowGraphs::edge($node['filter'], $node['rejected'], 'unmatched'),
            WorkflowGraphs::edge($node['inspect'], $node['validate'], 'inspected'), WorkflowGraphs::edge($node['inspect'], $node['validate'], 'missing'), WorkflowGraphs::edge($node['inspect'], $node['rejected'], 'blocked'),
            WorkflowGraphs::edge($node['validate'], $node['approval'], 'complete'), WorkflowGraphs::edge($node['validate'], $node['reply'], 'missing'), WorkflowGraphs::edge($node['validate'], $node['reply'], 'invalid'), WorkflowGraphs::edge($node['validate'], $node['rejected'], 'unknown'),
            WorkflowGraphs::edge($node['reply'], $node['revision'], 'out'), WorkflowGraphs::edge($node['revision'], $node['validate'], 'return'), WorkflowGraphs::edge($node['revision'], $node['rejected'], 'exhausted'),
            WorkflowGraphs::edge($node['approval'], $node['send'], 'approved'), WorkflowGraphs::edge($node['approval'], $node['rejected'], 'rejected'), WorkflowGraphs::edge($node['send'], $node['outcome'], 'out'), WorkflowGraphs::edge($node['outcome'], $node['done'], 'out'),
        ]], 'form_definition' => ['fields' => $form]];
        $this->putJson("/api/v1/organizations/{$org->uuid}/workflows/$workflow/draft", ['revision' => 1, ...$definition])->assertOk();
        $this->postJson("/api/v1/organizations/{$org->uuid}/workflows/$workflow/publish", ['revision' => 2])->assertOk();

        return [$org, $admin, $manager, $id, $workflow, $definition];
    }

    private function incoming(string $connection): string
    {
        app(EmailSync::class)->process($connection);
        $work = DB::table('email_fetch_queue')->first();
        $this->assertNotNull($work);
        app(EmailMessageReader::class)->process($work->id);
        $attachment = DB::table('email_attachment_fetches')->first();
        $this->assertNotNull($attachment);
        app(EmailMessageReader::class)->attachment($attachment->id);
        $intake = DB::table('email_intakes')->first();
        $this->assertNotNull($intake);

        return $intake->id;
    }

    private function confirm($org, string $intake): WorkflowInstance
    {
        $uuid = $this->postJson("/api/v1/organizations/{$org->uuid}/integrations/email/intakes/$intake/confirm", [])->assertOk()->json('data.request_uuid');

        return WorkflowInstance::where('uuid', $uuid)->firstOrFail();
    }

    public function test_incomplete_email_document_is_confirmed_corrected_approved_and_notifies_only_approved_hr(): void
    {
        [$org,$admin,$manager,$id,$workflow] = $this->fixture();
        $intake = $this->incoming($id);
        $this->assertDatabaseCount('workflow_instances', 0);
        $instance = $this->confirm($org, $intake);
        $this->assertSame('awaiting_revision', $instance->status->value);
        $delivery = DB::table('email_action_outbox')->first();
        $this->assertNotNull($delivery);
        app(EmailDelivery::class)->process($delivery->id);
        $this->assertDatabaseHas('email_action_outbox', ['id' => $delivery->id, 'status' => 'accepted']);
        $task = $instance->tasks()->where('status', 'open')->firstOrFail();
        $data = $instance->submitted_data;
        $data['employee_id'] = 'QA-123';
        $this->revise($org, $task->uuid, $data)->assertOk()->assertJsonPath('data.status', 'awaiting_approval');
        $this->login($manager);
        $approval = $instance->tasks()->where('status', 'open')->firstOrFail();
        $this->decide($org, $approval->uuid, 'approved')->assertOk()->assertJsonPath('data.status', 'completed');
        $hr = DB::table('email_action_outbox')->where('operation', 'email.send')->firstOrFail();
        app(EmailDelivery::class)->process($hr->id);
        $this->assertDatabaseHas('email_action_outbox', ['id' => $hr->id, 'status' => 'accepted']);
        $outcome = DB::table('email_action_outbox')->where('operation', 'email.reply')->where('id', '!=', $delivery->id)->firstOrFail();
        app(EmailDelivery::class)->process($outcome->id);
        $this->assertDatabaseHas('email_action_outbox', ['id' => $outcome->id, 'status' => 'accepted']);
        $this->assertCount(3, $this->deliveries);
        $this->assertStringContainsString('To: '.$admin->email, $this->deliveries[2]);
        $this->assertStringContainsString('was approved', $this->deliveries[2]);
        $this->assertStringContainsString('To: hr@example.com', $this->deliveries[1]);
        $this->assertStringContainsString('X-FlowMV-Automation: v3', $this->deliveries[1]);
        $this->assertStringNotContainsString('Synthetic Private Name', $this->deliveries[1]);
        $this->assertSame(3, WorkflowVersion::where('workflow_id', $instance->workflow_id)->value('node_registry_version'));
        $this->assertDatabaseCount('workflow_document_evidence', 1);
        $audit = json_encode(DB::table('integration_audit_events')->get());
        $events = json_encode($instance->events()->get());
        foreach ([$audit, $events] as $encoded) {
            $this->assertStringNotContainsString('Synthetic private email body', $encoded);
            $this->assertStringNotContainsString('Synthetic Private Name', $encoded);
            $this->assertStringNotContainsString('synthetic-only-access', $encoded);
        }
    }

    public function test_email_headers_never_auto_authorize_requests_or_allow_another_member_to_confirm(): void
    {
        [$org,,$manager,$id,$workflow] = $this->fixture(true);
        $intake = $this->incoming($id);
        $this->assertDatabaseCount('workflow_instances', 0);
        $this->startInstance($org, $workflow)->assertUnprocessable();
        $this->login($manager);
        $this->postJson("/api/v1/organizations/{$org->uuid}/integrations/email/intakes/$intake/confirm", [])->assertNotFound();
        $this->getJson("/api/v1/organizations/{$org->uuid}/integrations/email/intakes")->assertOk()->assertJsonCount(0, 'data.items');
        $this->assertDatabaseCount('workflow_instances', 0);
    }

    public function test_complete_document_opens_manager_approval_without_missing_information_email(): void
    {
        [$org,,,$id] = $this->fixture(true);
        $intake = $this->incoming($id);
        $instance = $this->confirm($org, $intake);
        $this->assertSame('awaiting_approval', $instance->status->value);
        $this->assertDatabaseCount('email_action_outbox', 0);
        $again = $this->confirm($org, $intake);
        $this->assertSame($instance->id, $again->id);
        $this->assertDatabaseCount('workflow_instances', 1);
    }

    public function test_duplicate_fetches_and_confirmation_preserve_one_intake_and_one_instance(): void
    {
        [$org,,,$id] = $this->fixture();
        $intake = $this->incoming($id);
        $work = DB::table('email_fetch_queue')->first();
        $attachment = DB::table('email_attachment_fetches')->first();
        app(EmailMessageReader::class)->process($work->id);
        app(EmailMessageReader::class)->attachment($attachment->id);
        $this->assertDatabaseCount('email_intakes', 1);
        $this->assertDatabaseCount('private_documents', 1);
        $this->confirm($org, $intake);
        $this->confirm($org, $intake);
        $this->assertDatabaseCount('workflow_instances', 1);
        $this->assertDatabaseCount('email_action_outbox', 1);
    }

    public function test_revocation_blocks_pending_confirmation_and_queued_delivery(): void
    {
        [$org,$admin,,$id] = $this->fixture();
        $intake = $this->incoming($id);
        $instance = $this->confirm($org, $intake);
        $delivery = DB::table('email_action_outbox')->first();
        app(EmailConnections::class)->action($admin->id, $org->id, $id, 'revoke', []);
        app(EmailDelivery::class)->process($delivery->id);
        $this->assertDatabaseHas('email_action_outbox', ['id' => $delivery->id, 'status' => 'cancelled']);
        $this->assertSame([], $this->deliveries);
        $this->assertSame($instance->workflow_version_id, $instance->fresh()->workflow_version_id);
    }

    public function test_revoked_connection_cannot_confirm_a_previously_received_email(): void
    {
        [$org,$admin,,$id] = $this->fixture();
        $intake = $this->incoming($id);
        app(EmailConnections::class)->action($admin->id, $org->id, $id, 'revoke', []);
        $this->postJson("/api/v1/organizations/{$org->uuid}/integrations/email/intakes/$intake/confirm", ['data' => []])->assertForbidden();
        $this->assertDatabaseCount('workflow_instances', 0);
    }

    public function test_stale_recipient_approval_cancels_queued_email_and_preserves_pinned_evidence(): void
    {
        [$org,$admin,,$id] = $this->fixture();
        $intake = $this->incoming($id);
        $instance = $this->confirm($org, $intake);
        $delivery = DB::table('email_action_outbox')->first();
        DB::table('email_destinations')->where('connection_id', $id)->where('address', $delivery->destination)->delete();
        app(EmailDelivery::class)->process($delivery->id);
        $this->assertDatabaseHas('email_action_outbox', ['id' => $delivery->id, 'status' => 'cancelled']);
        $this->assertSame([], $this->deliveries);
        $this->assertDatabaseCount('workflow_document_evidence', 1);
        $this->assertSame($instance->workflow_version_id, $instance->fresh()->workflow_version_id);
    }

    public function test_retry_after_is_bounded_and_timeouts_require_manual_duplicate_risk_review(): void
    {
        [$org,$admin,,$id] = $this->fixture();
        $instance = $this->confirm($org, $this->incoming($id));
        $delivery = DB::table('email_action_outbox')->first();
        $this->sendResponses = [Factory::response(['error' => 'rate limit'], 429, ['Retry-After' => '120']), new ConnectionException('synthetic transport failure')];
        app(EmailDelivery::class)->process($delivery->id);
        $row = DB::table('email_action_outbox')->where('id', $delivery->id)->first();
        $this->assertSame('pending', $row->status);
        $this->assertGreaterThanOrEqual(now()->addSeconds(119)->timestamp, Carbon::parse($row->available_at)->timestamp);
        $this->travel(121)->seconds();
        app(EmailDelivery::class)->process($delivery->id);
        $this->assertDatabaseHas('email_action_outbox', ['id' => $delivery->id, 'status' => 'uncertain', 'attempt_count' => 2]);
        try {
            app(EmailConnections::class)->action($admin->id, $org->id, $id, 'retry-delivery', ['delivery_id' => $delivery->id]);
            $this->fail('Uncertain retry lacked acknowledgment');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }
        app(EmailConnections::class)->action($admin->id, $org->id, $id, 'retry-delivery', ['delivery_id' => $delivery->id, 'acknowledge_duplicate_risk' => true]);
        app(EmailDelivery::class)->process($delivery->id);
        $this->assertDatabaseHas('email_action_outbox', ['id' => $delivery->id, 'status' => 'accepted', 'attempt_count' => 3]);
    }

    public function test_interrupted_delivery_becomes_uncertain_without_automatic_resend(): void
    {
        [$org,,,$id] = $this->fixture();
        $this->confirm($org, $this->incoming($id));
        $row = DB::table('email_action_outbox')->first();
        DB::table('email_action_outbox')->where('id', $row->id)->update(['status' => 'processing', 'claim_token' => (string) Str::uuid(), 'lease_expires_at' => now()->subMinute()]);
        app(EmailDelivery::class)->recover();
        $this->assertDatabaseHas('email_action_outbox', ['id' => $row->id, 'status' => 'uncertain']);
        app(EmailDelivery::class)->process($row->id);
        $this->assertSame([], $this->deliveries);
    }

    public function test_automated_email_loops_do_not_create_intakes_or_tasks(): void
    {
        [,,,$id] = $this->fixture();
        $this->automated = true;
        app(EmailSync::class)->process($id);
        $work = DB::table('email_fetch_queue')->first();
        app(EmailMessageReader::class)->process($work->id);
        $this->assertDatabaseCount('email_messages', 0);
        $this->assertDatabaseCount('email_intakes', 0);
        $this->assertDatabaseCount('workflow_instances', 0);
    }
}
