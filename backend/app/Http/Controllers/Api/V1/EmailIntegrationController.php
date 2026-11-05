<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Integrations\ConnectionAccess;
use App\Services\Integrations\ConnectionManager;
use App\Services\Integrations\CredentialVault;
use App\Services\Integrations\DocumentPipeline;
use App\Services\Integrations\DocumentTemplates;
use App\Services\Integrations\EmailAuthorization;
use App\Services\Integrations\EmailConnections;
use App\Services\Integrations\EmailIngress;
use App\Services\Integrations\EmailNodes;
use App\Services\Integrations\EmailOAuth;
use App\Services\Integrations\EmailProviders;
use App\Services\Integrations\EmailQaTransport;
use App\Services\Integrations\EmailWorkflow;
use App\Support\ActiveMembershipLocator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response;

class EmailIntegrationController extends Controller
{
    public function simulation(Request $r, string $organization, string $stage, EmailQaTransport $qa): JsonResponse
    {
        $qa->assertLocal();
        $this->stepUp($r);
        $org = $this->org($r);
        $private = $r->attributes->get('integration_input');
        if ($stage === 'connect') {
            $data = $r->validate(['provider' => 'required|in:gmail,outlook', 'name' => 'required|string|max:100', 'ownership' => 'required|in:personal,organization', 'capabilities' => 'required|array|min:1|max:2', 'capabilities.*' => 'required|in:read,send', 'connection_id' => 'nullable|uuid']);

            return response()->json(['data' => ['id' => $qa->connect($r, $org, $data, (string) $private->get('simulation_email'))]]);
        }
        abort_unless($this->access->role($r->user()->id, $org) === 'admin', 403);
        $data = $r->validate(['connection_id' => 'required|uuid', 'scenario' => 'required|in:complete,missing,invalid,duplicate,blocked']);
        abort_unless($stage === 'emit', 404);
        $qa->emit($org, $data['connection_id'], (string) $private->get('simulation_email'), $data['scenario']);

        return response()->json(['data' => ['simulated' => true]]);
    }

    public function preview(Request $r, DocumentPipeline $documents, DocumentTemplates $templates): JsonResponse
    {
        $org = $this->org($r);
        $this->access->role($r->user()->id, $org);
        $data = $r->validate(['document_id' => 'required|uuid', 'template_id' => 'required|uuid', 'rules' => 'present|array|max:10', 'match' => 'required|in:all,any']);
        $documents->authorize($r->user(), $org, $data['document_id']);
        $template = DB::table('document_templates')->where('organization_id', $org)->where('id', $data['template_id'])->first();
        abort_unless($template, 404);
        $doc = DB::table('private_documents')->where('id', $data['document_id'])->first();
        abort_unless($doc->status === 'clean' && Carbon::parse($doc->expires_at)->isFuture(), 409);
        $extraction = $documents->extracted($doc)['text'];
        $fields = json_decode($template->fields, true);
        $extracted = $templates->extract($fields, $extraction);
        $message = $r->attributes->get('integration_input')->get('preview_inputs', []);
        abort_unless(is_array($message) && count($message) <= 4, 422);
        foreach ($message as $key => $value) {
            abort_unless(in_array($key, ['sender', 'subject', 'text', 'recipients'], true) && ($key === 'recipients' ? is_array($value) && count($value) <= 10 : is_string($value) && strlen($value) <= 4000), 422);
        }

        return response()->json(['data' => ['dry_run' => true, 'matched' => app(EmailNodes::class)->matches($data['rules'], $data['match'], $message, 1), 'extracted' => $extracted['values'], 'validation' => $templates->validate($fields, $extracted['values'], [$doc->id], $extracted['uncertain']), 'external_delivery' => false]]);
    }

    public function intakes(Request $r, CredentialVault $vault): JsonResponse
    {
        $org = $this->org($r);
        $this->access->role($r->user()->id, $org);
        $items = DB::table('email_intakes as i')->join('email_trigger_bindings as b', 'b.id', '=', 'i.binding_id')->join('workflow_versions as v', 'v.id', '=', 'b.workflow_version_id')->join('workflows as w', 'w.id', '=', 'b.workflow_id')->where('i.organization_id', $org)->where('i.actor_user_id', $r->user()->id)->orderByDesc('i.created_at')->limit(50)->select('i.*', 'w.name as workflow_name', 'v.version_number')->get()->map(function ($i) use ($vault, $org) {
            $m = DB::table('email_messages')->where('organization_id', $org)->where('id', $i->message_id)->first();
            if (! $m || Carbon::parse($m->expires_at)->isPast()) {
                return null;
            }
            $email = $vault->decrypt($m, 'email-message:'.$m->id);
            $data = $vault->decrypt($i, 'email-intake:'.$i->id);

            return ['id' => $i->id, 'message_id' => $m->id, 'workflow_name' => $i->workflow_name, 'version_number' => $i->version_number, 'status' => $i->status, 'subject' => $email['subject'], 'sender' => $email['sender'], 'validation' => $data['initial_validation'], 'inputs' => $data['inputs'], 'documents' => DB::table('private_documents')->where('organization_id', $org)->whereIn('id', $data['document_ids'])->get(['id', 'name', 'status', 'error_code']), 'request_uuid' => $i->workflow_instance_id ? DB::table('workflow_instances')->where('id', $i->workflow_instance_id)->value('uuid') : null];
        })->filter()->values();

        return response()->json(['data' => ['items' => $items]]);
    }

    public function confirmIntake(Request $r, string $organization, string $intake, EmailWorkflow $workflow): JsonResponse
    {
        $data = $r->attributes->get('integration_input')->get('data', []);
        abort_unless(is_array($data) && count($data) <= 40, 422);
        $instance = $workflow->confirm($r->user(), $this->org($r), $intake, $data);

        return response()->json(['data' => ['request_uuid' => $instance->uuid, 'status' => $instance->status->value]]);
    }

    public function upload(Request $r, DocumentPipeline $documents): JsonResponse
    {
        $org = $this->org($r);
        $this->access->role($r->user()->id, $org);
        $file = $r->attributes->get('integration_input')->get('document_upload');
        abort_unless($file instanceof UploadedFile && $file->isValid(), 422);
        abort_if($file->getSize() > (int) config('email_integrations.documents.max_bytes'), 413);
        $id = $documents->store($org, $r->user()->id, $file->getClientOriginalName(), $file->get());

        return response()->json(['data' => ['id' => $id, 'status' => 'quarantined']], 201);
    }

    public function inspectDocument(Request $r, string $organization, string $document, DocumentPipeline $documents): JsonResponse
    {
        $org = $this->org($r);
        $result = DB::transaction(function () use ($r, $org, $document, $documents) {
            $documents->authorize($r->user(), $org, $document);

            return $documents->inspect($org, $document);
        });

        return response()->json(['data' => $result]);
    }

    public function downloadDocument(Request $r, string $organization, string $document, DocumentPipeline $documents): Response
    {
        $file = $documents->download($r->user(), $this->org($r), $document);

        return response($file['bytes'], 200, ['Content-Type' => 'application/octet-stream', 'Content-Disposition' => HeaderUtils::makeDisposition('attachment', $file['name'], 'document.'.($file['mime'] === 'application/pdf' ? 'pdf' : 'docx')), 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'no-store, private', 'Content-Security-Policy' => "default-src 'none'; sandbox"]);
    }

    public function templates(Request $r, DocumentTemplates $templates): JsonResponse
    {
        $org = $this->org($r);
        $this->access->role($r->user()->id, $org);
        if ($r->isMethod('POST')) {
            $data = $r->validate(['name' => 'required|string|max:100', 'fields' => 'required|array|max:20']);

            return response()->json(['data' => ['id' => $templates->create($org, $r->user()->id, $data['name'], $data['fields'])]], 201);
        }

        return response()->json(['data' => ['items' => DB::table('document_templates')->where('organization_id', $org)->orderByDesc('created_at')->limit(100)->get(['id', 'name', 'fields'])->map(fn ($t) => ['id' => $t->id, 'name' => $t->name, 'fields' => json_decode($t->fields, true)])]]);
    }

    public function webhook(Request $r, string $provider, EmailIngress $ingress): Response
    {
        $private = $r->attributes->get('integration_input');
        if ($provider === 'outlook' && $private->get('email_validation_token') !== null) {
            $token = $private->get('email_validation_token');
            abort_unless(is_string($token) && strlen($token) <= 4096 && ! preg_match('/[\x00-\x1f\x7f]/', $token), 422);

            return response($token, 200, ['Content-Type' => 'text/plain; charset=utf-8', 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'no-store', 'Content-Security-Policy' => "default-src 'none'"]);
        }
        $data = $private->get('email_notification');
        abort_unless(is_array($data), 422);
        if ($provider === 'gmail') {
            $ingress->gmail((string) $private->get('email_push_authorization'), $data);
        } elseif ($provider === 'outlook') {
            $ingress->outlook($data);
        } else {
            abort(404);
        }

        return response()->json(['accepted' => true], 202);
    }

    public function __construct(private ActiveMembershipLocator $memberships, private ConnectionAccess $access, private EmailAuthorization $authorization) {}

    private function org(Request $r): int
    {
        return $this->memberships->organizationFrom($r)->id;
    }

    private function stepUp(Request $r): void
    {
        abort_unless($r->hasSession() && $r->session()->get('integration_step_up.user') === $r->user()->id && $r->session()->get('integration_step_up.at', 0) > now()->timestamp - 300 && $r->session()->get('integration_step_up.at', 0) <= now()->timestamp, 428);
    }

    public function index(Request $r): JsonResponse
    {
        $org = $this->org($r);
        $admin = $this->access->role($r->user()->id, $org) === 'admin';
        $items = DB::table('connections as c')->join('email_mailboxes as m', 'm.connection_id', '=', 'c.id')->where('c.organization_id', $org)->whereIn('c.provider_id', EmailProviders::IDS)->where(function ($q) use ($admin, $r) {
            if (! $admin) {
                $q->whereNull('c.owner_user_id')->orWhere('c.owner_user_id', $r->user()->id);
            }
        })->select('c.id', 'c.name', 'c.provider_id', 'c.owner_user_id', 'c.status', 'm.address', 'm.capabilities', 'm.approved_by', 'm.health', 'm.error_code')->get()->map(function ($c) use ($r, $admin) {
            $c->ownership = $c->owner_user_id === null ? 'organization' : 'personal';
            $c->can_manage = $c->owner_user_id === null ? $admin : (int) $c->owner_user_id === $r->user()->id;
            $c->approved = $c->approved_by !== null;
            $c->capabilities = json_decode($c->capabilities, true);
            unset($c->owner_user_id,$c->approved_by);
            $c->destinations = DB::table('email_destinations')->where('connection_id', $c->id)->pluck('address');
            $c->grants = DB::table('email_workflow_grants as g')->join('workflows as w', 'w.id', '=', 'g.workflow_id')->where('g.connection_id', $c->id)->select('w.uuid as workflow_id', 'w.name as workflow_name', 'g.operation', 'g.destination')->get();
            $c->bindings = DB::table('email_trigger_bindings as b')->join('workflow_versions as v', 'v.id', '=', 'b.workflow_version_id')->join('workflows as w', 'w.id', '=', 'b.workflow_id')->where('b.connection_id', $c->id)->select('b.id', 'b.status', 'v.version_number', 'w.name as workflow_name')->get();
            $c->audit = $admin ? DB::table('integration_audit_events')->where('connection_id', $c->id)->orderByDesc('id')->limit(20)->get(['event', 'created_at'])->map(fn ($a) => ['event' => $a->event, 'created_at' => Carbon::parse($a->created_at, 'UTC')->toIso8601String()]) : [];
            $c->deliveries = $c->can_manage || $admin ? DB::table('email_action_outbox as o')->join('workflow_instances as i', 'i.id', '=', 'o.workflow_instance_id')->where('o.connection_id', $c->id)->orderByDesc('o.created_at')->limit(30)->get(['o.id', 'o.status', 'o.attempt_count', 'o.error_code', 'o.created_at', 'i.uuid as request_uuid'])->map(fn ($d) => [...((array) $d), 'created_at' => Carbon::parse($d->created_at, 'UTC')->toIso8601String()]) : [];

            return $c;
        });

        return response()->json(['data' => ['items' => $items, 'admin' => $admin, 'enabled' => (bool) config('email_integrations.enabled'), 'simulated' => (bool) config('email_integrations.simulated'), 'personal_connections_allowed' => (bool) DB::table('integration_policies')->where('organization_id', $org)->value('personal_connections_allowed'), 'approvals' => DB::table('integration_approvals')->where('organization_id', $org)->whereIn('provider_id', EmailProviders::IDS)->pluck('approved', 'provider_id')]]);
    }

    public function policy(Request $r, ConnectionManager $audit): JsonResponse
    {
        $this->stepUp($r);
        $org = $this->org($r);
        $data = $r->validate(['provider' => 'required|in:gmail,outlook', 'approved' => 'required|boolean']);
        DB::transaction(function () use ($r, $org, $data, $audit) {
            abort_unless($this->access->role($r->user()->id, $org) === 'admin', 403);
            DB::table('integration_approvals')->updateOrInsert(['organization_id' => $org, 'provider_id' => $data['provider']], ['approved' => $data['approved']]);
            if (! $data['approved']) {
                DB::table('email_trigger_bindings')->where('organization_id', $org)->whereIn('connection_id', DB::table('connections')->where('provider_id', $data['provider'])->select('id'))->update(['status' => 'paused', 'updated_at' => now()]);
            }
            $audit->audit($org, null, $r->user()->id, 'email.provider.policy');
        });

        return response()->json(['data' => ['updated' => true]]);
    }

    public function oauth(Request $r, string $organization, string $stage, EmailOAuth $oauth): JsonResponse
    {
        $this->stepUp($r);
        $org = $this->org($r);
        if ($stage === 'start') {
            $data = $r->validate(['provider' => 'required|in:gmail,outlook', 'name' => ['required', 'string', 'max:100', 'regex:/^[\pL\pN ._-]+$/u'], 'ownership' => 'required|in:personal,organization', 'capabilities' => 'required|array|min:1|max:2', 'capabilities.*' => 'required|in:read,send', 'connection_id' => 'nullable|uuid']);

            return response()->json(['data' => $oauth->start($r, $org, $data)]);
        }
        abort_unless($stage === 'finish', 404);
        $data = $r->validate(['provider' => 'required|in:gmail,outlook']);
        $private = $r->attributes->get('integration_input');

        return response()->json(['data' => ['id' => $oauth->finish($r, $org, $data['provider'], (string) $private->get('state'), (string) $private->get('code'))]]);
    }

    public function action(Request $r, string $organization, string $connection, string $action, EmailConnections $connections): JsonResponse
    {
        $this->stepUp($r);
        $org = $this->org($r);
        $rules = match ($action) {
            'approve-destination','remove-destination' => ['address' => 'required|string|max:254'],
            'grant','ungrant' => ['workflow_id' => 'required|uuid', 'operation' => 'required|in:email.receive,email.send,email.reply', 'address' => 'sometimes|string|max:254'],
            'downgrade' => ['capabilities' => 'required|array|min:1|max:2', 'capabilities.*' => 'required|in:read,send'],
            'pause','resume' => ['binding_id' => 'required|uuid'],
            'retry-delivery' => ['delivery_id' => 'required|uuid', 'acknowledge_duplicate_risk' => 'sometimes|boolean'],default => [],
        };
        $connections->action($r->user()->id, $org, $connection, $action, $r->validate($rules));

        return response()->json(['data' => ['updated' => true]]);
    }
}
