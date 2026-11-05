<?php

namespace App\Services\Integrations;

use Illuminate\Support\Facades\DB;

class TelegramAuthorization
{
    public function __construct(private ConnectionAccess $access, private CredentialVault $vault) {}

    public function connection(int $organizationId, string $id, bool $approved = true): object
    {
        $c = DB::table('connections')->where('id', $id)->where('organization_id', $organizationId)->where('provider_id', 'telegram')->lockForUpdate()->first();
        abort_unless($c && $c->status === 'enabled', 403);
        $this->access->approved($organizationId, 'telegram');
        $bot = DB::table('telegram_bots')->where('connection_id', $id)->first();
        abort_unless($bot && $bot->active_bot_id !== null, 403);
        if ($approved) {
            abort_unless($bot->approved_by && $this->access->role($bot->approved_by, $organizationId) === 'admin', 403);
        }
        if ($c->owner_user_id !== null) {
            $this->access->role($c->owner_user_id, $organizationId);
        }

        return $c;
    }

    public function destination(object $connection, string $chatId): object
    {
        $d = DB::table('telegram_destinations')->where('connection_id', $connection->id)->where('chat_id', $chatId)->first();
        abort_unless($d && $d->approved_by && $this->access->role($d->approved_by, $connection->organization_id) === 'admin', 403);

        return $d;
    }

    public function workflow(int $org, int $workflowId, string $id, string $operation, string $destination, ?int $actor = null): object
    {
        abort_unless(in_array($operation, ['telegram.message', 'telegram.send_message', 'telegram.notification', 'telegram.approval'], true), 403);
        $c = $this->connection($org, $id);
        $this->destination($c, $destination);
        abort_unless(DB::table('workflows')->where('id', $workflowId)->where('organization_id', $org)->where('status', '!=', 'archived')->exists(), 403);
        $g = DB::table('workflow_connection_grants')->where('connection_id', $id)->where('organization_id', $org)->where('workflow_id', $workflowId)->where('operation', $operation)->first();
        abort_unless($g, 403);
        $role = $this->access->role($g->authorized_by, $org);
        abort_unless($c->owner_user_id === null ? $role === 'admin' : (int) $c->owner_user_id === (int) $g->authorized_by, 403);
        if ($actor !== null) {
            $this->access->role($actor, $org);
            abort_unless($c->owner_user_id === null || (int) $c->owner_user_id === $actor, 403);
        }

        return $c;
    }

    public function credentials(string $id): array
    {
        $s = DB::table('credential_secrets')->where('connection_id', $id)->first();
        abort_unless($s, 403);

        return $this->vault->decrypt($s, $id);
    }
}
