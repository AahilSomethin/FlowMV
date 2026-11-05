<?php

namespace App\Services\AI;

use App\Services\Integrations\ConnectionAccess;
use Illuminate\Support\Facades\DB;

class AiAccess
{
    public const OPERATIONS = ['analyze/email', 'analyze/document', 'analyze/slack', 'summarize', 'classify', 'extract/fields', 'draft/reply'];

    public function role(int $user, int $org): string
    {
        return app(ConnectionAccess::class)->role($user, $org);
    }

    public function policy(int $org): object
    {
        abort_unless(config('ai.enabled'), 403);
        $p = DB::table('ai_policies')->where('organization_id', $org)->lockForUpdate()->first();
        abort_unless($p && $p->enabled, 403);

        return $p;
    }

    public function manage(int $user, int $org, string $id): object
    {
        $c = DB::table('connections')->where('organization_id', $org)->where('id', $id)->whereIn('provider_id', ['ai_openai', 'ai_gemini'])->lockForUpdate()->first();
        abort_unless($c, 404);
        $role = $this->role($user, $org);
        abort_unless($c->owner_user_id === null ? $role === 'admin' : (int) $c->owner_user_id === $user, 404);

        return $c;
    }

    public function connection(int $org, string $id, bool $approval = true): array
    {
        $p = $this->policy($org);
        $c = DB::table('connections')->where('organization_id', $org)->where('id', $id)->whereIn('provider_id', ['ai_openai', 'ai_gemini'])->lockForUpdate()->first();
        abort_unless($c && $c->status === 'enabled', 403);
        $provider = substr($c->provider_id, 3);
        abort_unless(in_array($provider, json_decode($p->providers, true), true) && DB::table('integration_providers')->where('id', $c->provider_id)->value('enabled') && DB::table('integration_approvals')->where('organization_id', $org)->where('provider_id', $c->provider_id)->value('approved'), 403);
        $a = DB::table('ai_connections')->where('connection_id', $id)->lockForUpdate()->first();
        abort_unless($a && in_array($a->model, config('ai.models.'.$provider, []), true), 403);
        if ($approval) {
            abort_unless($a->approved_by && $this->role($a->approved_by, $org) === 'admin', 403);
        }
        if ($c->owner_user_id !== null) {
            $this->role($c->owner_user_id, $org);
        }

        return [$c, $a, $p];
    }

    public function workflow(int $user, int $org, int $workflow, string $id, string $operation): array
    {
        $this->role($user, $org);
        [$c, $a, $p] = $this->connection($org, $id);
        abort_unless(in_array($operation, self::OPERATIONS, true) && in_array($operation, json_decode($a->operations, true), true), 403);
        abort_unless(DB::table('workflows')->where('id', $workflow)->where('organization_id', $org)->where('status', '!=', 'archived')->exists(), 403);
        $g = DB::table('ai_workflow_grants')->where('organization_id', $org)->where('workflow_id', $workflow)->where('connection_id', $id)->where('operation', $operation)->first();
        abort_unless($g, 403);
        $role = $this->role($g->authorized_by, $org);
        abort_unless($c->owner_user_id === null ? $role === 'admin' : ((int) $g->authorized_by === (int) $c->owner_user_id && (int) $c->owner_user_id === $user), 403);

        return [$c, $a, $p];
    }
}
