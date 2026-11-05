<?php

namespace Tests\Feature;

use App\Enums\OrganizationRole;
use App\Services\Integrations\CredentialVault;
use App\Services\Integrations\SmsApi;
use App\Services\Integrations\SmsDelivery;
use App\Services\Integrations\SmsIngress;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Feature\Workflows\InteractsWithExecution;
use Tests\Support\WorkflowGraphs;
use Tests\TestCase;

class SmsIntegrationTest extends TestCase
{
    use InteractsWithExecution { setUp as protected spaSetUp; }
    use RefreshDatabase;

    private const ACCOUNT = 'AC11111111111111111111111111111111';

    private const TOKEN = '11111111111111111111111111111111';

    private const PHONE = '+15005550009';

    protected function setUp(): void
    {
        $this->spaSetUp();
        config(['sms.enabled' => true, 'sms.webhook_origin' => 'https://flowmv.example.test', 'integrations.keys.v1' => base64_encode(random_bytes(32))]);
        Queue::fake();
        $api = new SmsApi(new Factory);
        $api->http->preventStrayRequests();
        $api->http->fake(function ($r) {
            if (str_contains($r->url(), 'IncomingPhoneNumbers')) {
                return Factory::response(['incoming_phone_numbers' => [['account_sid' => self::ACCOUNT, 'phone_number' => '+15005550006', 'capabilities' => ['sms' => true], 'sid' => 'PN'.str_repeat('1', 32)]]]);
            }
            if (str_contains($r->url(), 'lookups.twilio.com')) {
                return Factory::response(['valid' => true, 'phone_number' => self::PHONE, 'country_code' => 'US']);
            }
            if ($r->method() === 'POST') {
                return Factory::response(['account_sid' => self::ACCOUNT, 'sid' => 'SM'.bin2hex(random_bytes(16))], 201);
            }

            return Factory::response(['sid' => self::ACCOUNT, 'status' => 'active']);
        });
        $this->app->instance(SmsApi::class, $api);
    }

    private function confirm(): void
    {
        $this->postJson('/api/v1/integration-auth/confirm', ['password' => 'password'])->assertOk();
        $this->withCookie(config('session.cookie'), session()->getId());
        $this->withCredentials();
    }

    private function fixture(): array
    {
        $org = $this->signInMember(OrganizationRole::Admin);
        $this->confirm();
        $base = "/api/v1/organizations/{$org->uuid}/integrations/sms";
        $this->putJson("$base/policy", ['approved' => true, 'enabled' => true, 'allowed_countries' => ['US'], 'per_minute' => 3, 'daily_limit' => 10, 'monthly_limit' => 100, 'recipient_daily_limit' => 3])->assertOk();
        $id = $this->postJson("$base/connections", ['name' => 'QA SMS', 'ownership' => 'organization', 'credentials' => ['account_sid' => self::ACCOUNT, 'auth_token' => self::TOKEN]])->assertOk()->json('data.id');
        $this->postJson("$base/$id/approve")->assertOk();
        $sender = $this->postJson("$base/$id/approve-sender", ['label' => 'Service', 'phone' => '+15005550006', 'consent' => true])->assertOk()->json('data.id');
        $recipient = $this->postJson("$base/$id/approve-recipient", ['label' => 'Employee', 'phone' => self::PHONE, 'consent' => true])->assertOk()->json('data.id');
        $template = $this->postJson("$base/templates", ['name' => 'Outcome', 'template_text' => 'Request {{request.uuid}}: {{approval.decision}}.', 'approved' => true])->assertOk()->json('data.id');

        return [$org, $id, $base, ['connection_id' => $id, 'sender_id' => $sender, 'recipient_id' => $recipient, 'template_id' => $template]];
    }

    private function queued(): array
    {
        [$org, $id, $base, $data] = $this->fixture();
        $w = $this->createWorkflow($org, 'SMS outcome');
        $this->postJson("$base/$id/grant", ['workflow_id' => $w, 'operation' => 'sms.notification', ...$data])->assertOk();
        [$start, $sms, $end] = array_map(fn () => (string) Str::uuid(), range(1, 3));
        $graph = ['nodes' => [WorkflowGraphs::node($start, 'start', 0, 0, ['label' => 'Start']), WorkflowGraphs::node($sms, 'sms_notification', 200, 0, ['label' => 'SMS', ...$data]), WorkflowGraphs::node($end, 'end', 400, 0, ['label' => 'Done', 'result' => 'completed'])], 'edges' => [WorkflowGraphs::edge($start, $sms, 'out'), WorkflowGraphs::edge($sms, $end, 'out')]];
        $this->putJson("/api/v1/organizations/{$org->uuid}/workflows/$w/draft", ['revision' => 1, 'graph_definition' => $graph, 'form_definition' => ['fields' => []]])->assertOk();
        $this->postJson("/api/v1/organizations/{$org->uuid}/workflows/$w/publish", ['revision' => 2])->assertOk();
        $this->startInstance($org, $w)->assertCreated();
        $row = DB::table('sms_action_outbox')->first();
        $this->assertNotNull($row);

        return [$org, $id, $base, $data, $row, $w];
    }

    public function test_credentials_and_phones_are_encrypted_and_catalog_is_safe(): void
    {
        [$org, $id, $base] = $this->fixture();
        $secret = DB::table('credential_secrets')->where('connection_id', $id)->first();
        $this->assertStringNotContainsString(self::TOKEN, $secret->ciphertext);
        $this->assertSame(self::TOKEN, app(CredentialVault::class)->decrypt($secret, $id)['auth_token']);
        $recipient = DB::table('sms_recipients')->first();
        $this->assertStringNotContainsString(self::PHONE, $recipient->ciphertext);
        $this->assertSame(self::PHONE, app(CredentialVault::class)->decrypt($recipient, 'sms-recipient:'.$recipient->id)['phone']);
        $json = $this->getJson($base)->assertOk()->getContent();
        foreach ([self::TOKEN, self::PHONE, 'ciphertext', 'fingerprint_key'] as $private) {
            $this->assertStringNotContainsString($private, $json);
        }
        $this->signIn($this->addMember($org, OrganizationRole::Employee));
        $this->getJson($base)->assertOk()->assertJsonPath('data.items', []);
        $this->confirm();
        $this->postJson("$base/$id/revoke")->assertForbidden();
    }

    public function test_durable_action_is_accepted_once_and_body_never_enters_workflow(): void
    {
        [, , , , $row] = $this->queued();
        app(SmsDelivery::class)->process($row->id);
        app(SmsDelivery::class)->process($row->id);
        $this->assertDatabaseHas('sms_action_outbox', ['id' => $row->id, 'status' => 'accepted', 'attempt_count' => 1]);
        $this->assertNotNull(DB::table('sms_action_outbox')->value('budget_reserved_at'));
        $this->assertStringNotContainsString(self::PHONE, json_encode(DB::table('workflow_versions')->get()));
        $this->assertStringNotContainsString(self::TOKEN, json_encode(DB::table('integration_audit_events')->get()));
        app(SmsApi::class)->http->assertSentCount(5);
    }

    public function test_revocation_and_removed_grants_stop_queued_delivery(): void
    {
        [, $id, $base, , $row] = $this->queued();
        $this->postJson("$base/$id/revoke")->assertOk();
        app(SmsDelivery::class)->process($row->id);
        $this->assertDatabaseHas('sms_action_outbox', ['id' => $row->id, 'status' => 'cancelled', 'attempt_count' => 0]);
        $this->assertDatabaseMissing('credential_secrets', ['connection_id' => $id]);
    }

    public function test_rate_limit_retries_but_ambiguous_send_is_not_resent(): void
    {
        [, , , , $row] = $this->queued();
        $api = new SmsApi(new Factory);
        $api->http->preventStrayRequests();
        $api->http->fakeSequence()->push([], 429, ['Retry-After' => '90'])->push([], 503);
        $this->app->instance(SmsApi::class, $api);
        app(SmsDelivery::class)->process($row->id);
        $r = DB::table('sms_action_outbox')->first();
        $this->assertSame('pending', $r->status);
        $this->assertTrue(Carbon::parse($r->available_at)->greaterThanOrEqualTo(now()->addSeconds(89)));
        $this->travel(91)->seconds();
        app(SmsDelivery::class)->process($row->id);
        app(SmsDelivery::class)->process($row->id);
        $this->assertDatabaseHas('sms_action_outbox', ['id' => $row->id, 'status' => 'uncertain', 'attempt_count' => 2]);
        $api->http->assertSentCount(2);
    }

    public function test_forged_callbacks_fail_and_valid_replays_cannot_regress_status(): void
    {
        [, $id, , , $row] = $this->queued();
        app(SmsDelivery::class)->process($row->id);
        $row = DB::table('sms_action_outbox')->first();
        $params = ['AccountSid' => self::ACCOUNT, 'MessageSid' => $row->provider_message_id, 'MessageStatus' => 'delivered', 'To' => self::PHONE, 'From' => '+15005550006'];
        $send = function (array $p, bool $forge = false) use ($id, $row) {
            ksort($p, SORT_STRING);
            $url = app(SmsApi::class)->callbackUrl($id, $row->id);
            $data = $url;
            foreach ($p as $k => $v) {
                $data .= $k.$v;
            }
            $signature = base64_encode(hash_hmac('sha1', $data, $forge ? 'wrong' : self::TOKEN, true));

            return $this->call('POST', parse_url($url, PHP_URL_PATH), [], [], [], ['CONTENT_TYPE' => 'application/x-www-form-urlencoded', 'HTTP_ACCEPT' => 'application/json', 'HTTP_X_TWILIO_SIGNATURE' => $signature], http_build_query($p));
        };
        $send($params, true)->assertForbidden();
        $send($params)->assertNoContent();
        $send($params)->assertNoContent();
        $params['MessageStatus'] = 'sent';
        $send($params)->assertNoContent();
        $this->assertDatabaseHas('sms_action_outbox', ['id' => $row->id, 'status' => 'delivered']);
        $this->assertDatabaseCount('sms_status_receipts', 2);
    }

    public function test_country_consent_template_and_personal_credentials_are_restricted(): void
    {
        [, $id, $base] = $this->fixture();
        $this->postJson("$base/connections", ['name' => 'Personal', 'ownership' => 'personal', 'credentials' => []])->assertUnprocessable();
        $this->postJson("$base/$id/approve-recipient", ['label' => 'No consent', 'phone' => self::PHONE, 'consent' => false])->assertForbidden();
        $this->postJson("$base/templates", ['name' => 'Private', 'template_text' => '{{input.salary}}', 'approved' => true])->assertUnprocessable();
        $this->postJson("$base/templates", ['name' => 'Long', 'template_text' => str_repeat('x', 161), 'approved' => true])->assertUnprocessable();
        DB::table('sms_policies')->update(['allowed_countries' => '["GB"]']);
        $this->postJson("$base/$id/approve-recipient", ['label' => 'Country denied', 'phone' => self::PHONE, 'consent' => true])->assertForbidden();
    }

    public function test_expired_consent_and_membership_changes_fail_closed(): void
    {
        [$org, , , , $row] = $this->queued();
        DB::table('sms_recipients')->update(['expires_at' => now()->subMinute()]);
        app(SmsDelivery::class)->process($row->id);
        $this->assertDatabaseHas('sms_action_outbox', ['id' => $row->id, 'status' => 'cancelled', 'attempt_count' => 0]);
    }

    public function test_key_rotation_preserves_phone_fingerprints_and_payloads(): void
    {
        [, $id, , , $row] = $this->queued();
        $before = DB::table('sms_recipients')->value('fingerprint');
        config(['integrations.keys.v2' => base64_encode(random_bytes(32)), 'integrations.active_key' => 'v2']);
        $this->artisan('integrations:rotate-keys')->assertSuccessful();
        foreach (['sms_policies', 'sms_senders', 'sms_recipients', 'sms_action_outbox', 'credential_secrets'] as $table) {
            $this->assertSame('v2', DB::table($table)->value('key_version'));
        }
        $this->assertSame($before, DB::table('sms_recipients')->value('fingerprint'));
        app(SmsDelivery::class)->process($row->id);
        $this->assertDatabaseHas('sms_action_outbox', ['id' => $row->id, 'status' => 'accepted']);
    }

    public function test_removed_exact_grant_and_demoted_authorizer_stop_delivery(): void
    {
        [, , , , $row] = $this->queued();
        DB::table('sms_workflow_grants')->delete();
        app(SmsDelivery::class)->process($row->id);
        $this->assertDatabaseHas('sms_action_outbox', ['id' => $row->id, 'status' => 'cancelled', 'attempt_count' => 0]);
    }

    public function test_organization_budget_blocks_new_delivery_before_http(): void
    {
        [$org, , , , $row, $w] = $this->queued();
        DB::table('sms_policies')->where('organization_id', $org->id)->update(['daily_limit' => 1]);
        app(SmsDelivery::class)->process($row->id);
        $this->startInstance($org, $w)->assertCreated();
        $second = DB::table('sms_action_outbox')->where('id', '!=', $row->id)->first();
        app(SmsDelivery::class)->process($second->id);
        $this->assertDatabaseHas('sms_action_outbox', ['id' => $second->id, 'status' => 'blocked', 'error_code' => 'daily_limit', 'attempt_count' => 0]);
        app(SmsApi::class)->http->assertSentCount(5);
    }

    public function test_sensitive_operations_require_step_up_and_tenant_binding(): void
    {
        [$org, $id, $base] = $this->fixture();
        $this->travel(301)->seconds();
        $this->postJson("$base/$id/disable")->assertStatus(428);
        $other = $this->signInMember(OrganizationRole::Admin);
        $this->confirm();
        $this->postJson("/api/v1/organizations/{$other->uuid}/integrations/sms/$id/revoke")->assertNotFound();
        $this->getJson($base)->assertNotFound();
    }

    public function test_rotated_credentials_invalidate_grants_and_pending_work(): void
    {
        [, $id, $base, , $row] = $this->queued();
        $this->postJson("$base/$id/rotate", ['credentials' => ['account_sid' => self::ACCOUNT, 'auth_token' => str_repeat('2', 32)]])->assertOk();
        $this->assertDatabaseHas('sms_accounts', ['connection_id' => $id, 'health' => 'needs_approval', 'credential_generation' => 2]);
        $this->assertDatabaseCount('sms_workflow_grants', 0);
        app(SmsDelivery::class)->process($row->id);
        $this->assertDatabaseHas('sms_action_outbox', ['id' => $row->id, 'status' => 'cancelled', 'attempt_count' => 0]);
    }

    public function test_signature_matches_the_published_twilio_reference_vector(): void
    {
        // https://www.twilio.com/docs/usage/security#explore-the-algorithm-yourself
        $params = ['Digits' => '1234', 'To' => '+18005551212', 'From' => '+14158675310', 'Caller' => '+14158675310', 'CallSid' => 'CA1234567890ABCDE'];
        $ingress = app(SmsIngress::class);
        $this->assertTrue($ingress->verify('https://example.com/myapp.php?foo=1&bar=2', $params, 'L/OH5YylLD5NRKLltdqwSvS0BnU=', '12345'));
        $this->assertFalse($ingress->verify('https://evil.example/myapp.php?foo=1&bar=2', $params, 'L/OH5YylLD5NRKLltdqwSvS0BnU=', '12345'));
    }

    public function test_signed_callback_can_reconcile_an_ambiguous_send_without_sending_again(): void
    {
        [, $id, , , $row] = $this->queued();
        DB::table('sms_action_outbox')->where('id', $row->id)->update(['status' => 'uncertain', 'attempt_count' => 1, 'budget_reserved_at' => now()]);
        $params = ['AccountSid' => self::ACCOUNT, 'MessageSid' => 'SM'.str_repeat('a', 32), 'MessageStatus' => 'delivered', 'To' => self::PHONE, 'From' => '+15005550006'];
        ksort($params, SORT_STRING);
        $url = app(SmsApi::class)->callbackUrl($id, $row->id);
        $data = $url;
        foreach ($params as $k => $v) {
            $data .= $k.$v;
        }
        app(SmsIngress::class)->receive($id, $row->id, http_build_query($params), base64_encode(hash_hmac('sha1', $data, self::TOKEN, true)));
        app(SmsDelivery::class)->process($row->id);
        $this->assertDatabaseHas('sms_action_outbox', ['id' => $row->id, 'status' => 'delivered', 'attempt_count' => 1]);
        app(SmsApi::class)->http->assertSentCount(4);
    }

    public function test_demoted_policy_authorizer_stops_queued_work(): void
    {
        [$org, , , , $row] = $this->queued();
        DB::table('organization_memberships')->where('organization_id', $org->id)->update(['role' => 'employee']);
        app(SmsDelivery::class)->process($row->id);
        $this->assertDatabaseHas('sms_action_outbox', ['id' => $row->id, 'status' => 'cancelled', 'attempt_count' => 0]);
    }
}
