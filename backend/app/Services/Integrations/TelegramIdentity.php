<?php

namespace App\Services\Integrations;

use Illuminate\Support\Facades\DB;

class TelegramIdentity
{
    public function __construct(private ConnectionAccess $access, private TelegramAuthorization $authorization, private ConnectionManager $audit) {}

    public function begin(int $user, int $org, string $id): string
    {
        return DB::transaction(function () use ($user, $org, $id) {
            $this->access->role($user, $org);
            $c = $this->authorization->connection($org, $id);
            abort_unless($c->owner_user_id === null || (int) $c->owner_user_id === $user, 403);
            $challenge = bin2hex(random_bytes(32));
            DB::table('telegram_identity_links')->updateOrInsert(['connection_id' => $id, 'user_id' => $user], ['challenge_hash' => hash('sha256', $challenge), 'expires_at' => now()->addMinutes(10), 'telegram_user_id' => null, 'confirmed' => false]);
            $this->audit->audit($org, $id, $user, 'telegram.identity.started');

            return '/flowmv_link '.$challenge;
        });
    }

    public function receive(object $connection, string $telegramId, #[\SensitiveParameter] string $text, string $chatId, string $type): bool
    {
        if (! str_starts_with($text, '/flowmv_link ')) {
            return false;
        }
        if ($type !== 'private' || $chatId !== $telegramId || preg_match('/^\/flowmv_link ([a-f0-9]{64})$/D', $text, $match) !== 1) {
            return true;
        }
        $link = DB::table('telegram_identity_links')->where('connection_id', $connection->id)->where('challenge_hash', hash('sha256', $match[1]))->where('expires_at', '>', now())->where('confirmed', false)->lockForUpdate()->first();
        if ($link) {
            $this->access->role($link->user_id, $connection->organization_id);
            DB::table('telegram_identity_links')->where('id', $link->id)->update(['telegram_user_id' => $telegramId, 'challenge_hash' => null]);
            $this->audit->audit($connection->organization_id, $connection->id, $link->user_id, 'telegram.identity.received');
        }

        return true;
    }

    public function confirm(int $user, int $org, string $id): void
    {
        DB::transaction(function () use ($user, $org, $id) {
            $this->access->role($user, $org);
            $this->authorization->connection($org, $id);
            $link = DB::table('telegram_identity_links')->where('connection_id', $id)->where('user_id', $user)->whereNotNull('telegram_user_id')->whereNull('challenge_hash')->where('expires_at', '>', now())->lockForUpdate()->first();
            abort_unless($link, 409);
            DB::table('telegram_identity_links')->where('id', $link->id)->update(['confirmed' => true, 'expires_at' => null]);
            $this->audit->audit($org, $id, $user, 'telegram.identity.confirmed');
        });
    }
}
