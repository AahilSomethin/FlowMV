<?php

namespace App\Services\Integrations;

use Illuminate\Support\Facades\DB;

class EmailAuthorization
{
    public function __construct(private ConnectionAccess $access) {}

    public function approved(int $org, string $provider): void
    {
        abort_unless(in_array($provider, EmailProviders::IDS, true) && config('email_integrations.enabled') && DB::table('integration_providers')->where('id', $provider)->value('enabled') && DB::table('integration_approvals')->where('organization_id', $org)->where('provider_id', $provider)->value('approved'), 403);
    }

    public function create(int $user, int $org, string $provider, string $ownership): void
    {
        $role = $this->access->role($user, $org);
        abort_unless(in_array($ownership, ['personal', 'organization'], true), 422);
        abort_unless($ownership === 'organization' ? $role === 'admin' : DB::table('integration_policies')->where('organization_id', $org)->value('personal_connections_allowed'), 403);
        $this->approved($org, $provider);
    }

    public function manage(int $user, int $org, string $id): object
    {
        $c = DB::table('connections')->where('organization_id', $org)->where('id', $id)->whereIn('provider_id', EmailProviders::IDS)->lockForUpdate()->first();
        abort_unless($c, 404);
        $role = $this->access->role($user, $org);
        abort_unless($c->owner_user_id === null ? $role === 'admin' : (int) $c->owner_user_id === $user, 404);

        return $c;
    }

    public function connection(int $org, string $id, ?string $capability = null, bool $approval = true): object
    {
        $c = DB::table('connections')->where('organization_id', $org)->where('id', $id)->whereIn('provider_id', EmailProviders::IDS)->lockForUpdate()->first();
        abort_unless($c && $c->status === 'enabled', 403);
        $this->approved($org, $c->provider_id);
        $m = DB::table('email_mailboxes')->where('connection_id', $id)->first();
        abort_unless($m && $m->active_identity && $m->health !== 'reauthorization_required', 403);
        if ($approval) {
            abort_unless($m->approved_by && $this->access->role($m->approved_by, $org) === 'admin', 403);
        }
        if ($c->owner_user_id !== null) {
            $this->access->role($c->owner_user_id, $org);
        }
        if ($capability !== null) {
            abort_unless(in_array($capability, json_decode($m->capabilities, true), true), 403);
        }

        return $c;
    }

    public function workflow(int $org, int $workflow, string $id, string $operation, string $destination = '', ?int $actor = null): object
    {
        abort_unless(in_array($operation, ['email.receive', 'email.send', 'email.reply'], true), 403);
        $c = $this->connection($org, $id, $operation === 'email.receive' ? 'read' : 'send');
        abort_unless(DB::table('workflows')->where('organization_id', $org)->where('id', $workflow)->where('status', '!=', 'archived')->exists(), 403);
        $grant = DB::table('email_workflow_grants')->where('organization_id', $org)->where('connection_id', $id)->where('workflow_id', $workflow)->where('operation', $operation)->where('destination', $destination)->first();
        abort_unless($grant, 403);
        $role = $this->access->role($grant->authorized_by, $org);
        abort_unless($c->owner_user_id === null ? $role === 'admin' : (int) $c->owner_user_id === (int) $grant->authorized_by, 403);
        if ($destination !== '') {
            $dest = DB::table('email_destinations')->where('connection_id', $id)->where('address', $destination)->first();
            abort_unless($dest && $this->access->role($dest->approved_by, $org) === 'admin', 403);
        }
        if ($actor !== null) {
            $this->access->role($actor, $org);
            abort_unless($c->owner_user_id === null || (int) $c->owner_user_id === $actor, 403);
        }

        return $c;
    }
}
