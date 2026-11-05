<?php

namespace App\Services\Integrations;

use Illuminate\Support\Facades\DB;

class SlackAuthorization
{
    public function __construct(private ConnectionAccess $access) {}

    public function approved(int $org): void
    {
        abort_unless(config('slack.enabled') && DB::table('integration_providers')->where('id', 'slack')->value('enabled') && DB::table('integration_approvals')->where('organization_id', $org)->where('provider_id', 'slack')->value('approved'), 403);
    }

    public function create(int $user, int $org, string $ownership): void
    {
        $role = $this->access->role($user, $org);
        abort_unless(in_array($ownership, ['personal', 'organization'], true), 422);
        abort_unless($ownership === 'organization' ? $role === 'admin' : DB::table('integration_policies')->where('organization_id', $org)->value('personal_connections_allowed'), 403);
        $this->approved($org);
    }

    public function manage(int $user, int $org, string $id): object
    {
        $c = DB::table('connections')->where('organization_id', $org)->where('provider_id', 'slack')->where('id', $id)->lockForUpdate()->first();
        abort_unless($c, 404);
        $role = $this->access->role($user, $org);
        abort_unless($c->owner_user_id === null ? $role === 'admin' : (int) $c->owner_user_id === $user, 404);

        return $c;
    }

    public function connection(int $org, string $id, bool $approval = true): object
    {
        $c = DB::table('connections')->where('organization_id', $org)->where('provider_id', 'slack')->where('id', $id)->lockForUpdate()->first();
        abort_unless($c && $c->status === 'enabled', 403);
        $this->approved($org);
        $i = DB::table('slack_installations')->where('connection_id', $id)->first();
        abort_unless($i && $i->active_workspace_id && $i->health !== 'reauthorization_required', 403);
        if ($approval) {
            abort_unless($i->approved_by && $this->access->role($i->approved_by, $org) === 'admin', 403);
        }
        if ($c->owner_user_id !== null) {
            $this->access->role($c->owner_user_id, $org);
        }

        return $c;
    }

    public function workflow(int $org, int $workflow, string $id, string $operation, string $destination, ?int $actor = null): object
    {
        abort_unless(in_array($operation, ['slack.message', 'slack.send_message', 'slack.notification'], true), 403);
        $c = $this->connection($org, $id);
        abort_unless(DB::table('workflows')->where('id', $workflow)->where('organization_id', $org)->where('status', '!=', 'archived')->exists(), 403);
        $d = DB::table('slack_channels')->where('connection_id', $id)->where('channel_id', $destination)->first();
        abort_unless($d && $this->access->role($d->approved_by, $org) === 'admin', 403);
        $g = DB::table('slack_workflow_grants')->where('organization_id', $org)->where('workflow_id', $workflow)->where('connection_id', $id)->where('operation', $operation)->where('destination', $destination)->first();
        abort_unless($g, 403);
        $role = $this->access->role($g->authorized_by, $org);
        abort_unless($c->owner_user_id === null ? $role === 'admin' : (int) $c->owner_user_id === (int) $g->authorized_by, 403);
        if ($actor !== null) {
            $this->access->role($actor, $org);
            abort_unless($c->owner_user_id === null || (int) $c->owner_user_id === $actor, 403);
        }

        return $c;
    }
}
