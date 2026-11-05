<?php

namespace App\Services\Integrations;

use App\Exceptions\EmailProviderFailure;
use App\Jobs\FetchEmailMessage;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class EmailSync
{
    public function __construct(private EmailAuthorization $authorization, private EmailTokens $tokens, private CredentialVault $vault, private EmailHttp $http, private ConnectionManager $audit) {}

    public function process(string $id): void
    {
        DB::transaction(function () use ($id) {
            $c = DB::table('connections')->where('id', $id)->whereIn('provider_id', EmailProviders::IDS)->lockForUpdate()->first();
            $row = DB::table('email_sync_state')->where('connection_id', $id)->lockForUpdate()->first();
            if (! $c || ! $row || Carbon::parse($row->available_at)->isFuture()) {
                return;
            }
            try {
                $this->authorization->connection($c->organization_id, $id, 'read');
                $token = $this->tokens->get($c->organization_id, $id);
                $state = $this->vault->decrypt($row, 'email-sync:'.$id);
                $mailbox = DB::table('email_mailboxes')->where('connection_id', $id)->first();
                if (! $row->watch_expires_at || Carbon::parse($row->watch_expires_at)->lt(now()->addHours(12))) {
                    $this->renew($c, $row, $state, $token);
                }
                $ids = [];
                if ($c->provider_id === 'gmail') {
                    if (empty($state['cursor'])) {
                        if (empty($state['baseline'])) {
                            $profile = $this->http->api('gmail', 'GET', '/profile', $token);
                            abort_unless(preg_match('/^[0-9]{1,30}$/D', (string) ($profile['historyId'] ?? '')), 503);
                            $state['baseline'] = (string) $profile['historyId'];
                        }
                        $query = ['maxResults' => 50, 'labelIds' => 'INBOX', 'q' => 'after:'.Carbon::parse($mailbox->connected_at)->timestamp];
                        if (! empty($state['next'])) {
                            $query['pageToken'] = $state['next'];
                        }
                        $page = $this->http->api('gmail', 'GET', '/messages', $token, $query);
                        foreach ($page['messages'] ?? [] as $message) {
                            $ids[] = $message['id'] ?? '';
                        }
                        $state['next'] = $page['nextPageToken'] ?? null;
                        if ($state['next'] === null) {
                            $state['cursor'] = $state['baseline'];
                            unset($state['baseline']);
                        }
                    } else {
                        $query = ['startHistoryId' => $state['cursor'], 'historyTypes' => 'messageAdded', 'maxResults' => 50];
                        if (! empty($state['next'])) {
                            $query['pageToken'] = $state['next'];
                        }
                        try {
                            $page = $this->http->api('gmail', 'GET', '/history', $token, $query);
                        } catch (EmailProviderFailure $e) {
                            if ($e->status !== 404) {
                                throw $e;
                            }
                            $state['cursor'] = null;
                            $state['next'] = null;
                            $this->save($id, $state, ['requested' => true, 'available_at' => now(), 'error_code' => 'history_reconciliation']);
                            $this->audit->audit($c->organization_id, $id, null, 'email.sync.reconcile');

                            return;
                        }
                        foreach ($page['history'] ?? [] as $change) {
                            foreach ($change['messagesAdded'] ?? [] as $message) {
                                $ids[] = $message['message']['id'] ?? '';
                            }
                        }
                        $state['next'] = $page['nextPageToken'] ?? null;
                        if ($state['next'] === null) {
                            abort_unless(preg_match('/^[0-9]{1,30}$/D', (string) ($page['historyId'] ?? '')), 503);
                            $state['cursor'] = (string) $page['historyId'];
                        }
                    }
                } else {
                    $path = ! empty($state['next']) ? $this->http->graphContinuation($state['next']) : '/me/mailFolders/inbox/messages/delta?$select=id,receivedDateTime&$top=50';
                    try {
                        $page = $this->http->api('outlook', 'GET', $path, $token);
                    } catch (EmailProviderFailure $e) {
                        if (! in_array($e->status, [404, 410], true)) {
                            throw $e;
                        }
                        $state['next'] = null;
                        $state['cursor'] = null;
                        $this->save($id, $state, ['requested' => true, 'available_at' => now(), 'error_code' => 'delta_reconciliation']);

                        return;
                    }
                    foreach ($page['value'] ?? [] as $message) {
                        if (! isset($message['@removed']) && isset($message['receivedDateTime']) && Carbon::parse($message['receivedDateTime'])->gte(Carbon::parse($mailbox->connected_at))) {
                            $ids[] = $message['id'] ?? '';
                        }
                    }
                    $next = $page['@odata.nextLink'] ?? $page['@odata.deltaLink'] ?? null;
                    abort_unless(is_string($next), 503);
                    $this->http->graphContinuation($next);
                    $state['next'] = $next;
                    $state['cursor'] = isset($page['@odata.deltaLink']) ? $next : ($state['cursor'] ?? null);
                }
                abort_if(count($ids) > 2000, 503);
                foreach (array_unique($ids) as $messageId) {
                    $this->enqueue($id, $messageId);
                }
                $more = $c->provider_id === 'gmail' ? ! empty($state['next']) : isset($page['@odata.nextLink']);
                $this->save($id, $state, ['requested' => $more, 'available_at' => $more ? now() : now()->addMinutes(5), 'attempt_count' => 0, 'error_code' => null]);
                DB::table('email_mailboxes')->where('connection_id', $id)->update(['health' => 'ready', 'error_code' => null]);
                $this->audit->audit($c->organization_id, $id, null, 'email.sync.page');
            } catch (EmailProviderFailure $e) {
                $attempt = $row->attempt_count + 1;
                DB::table('email_sync_state')->where('connection_id', $id)->update(['attempt_count' => $attempt, 'error_code' => $e->reason, 'available_at' => now()->addSeconds(max($e->retryAfter, min(3600, 30 * (2 ** min(7, $attempt))))), 'updated_at' => now()]);
                DB::table('email_mailboxes')->where('connection_id', $id)->update(['error_code' => $e->reason]);
                $this->audit->audit($c->organization_id, $id, null, 'email.sync.retry');
            } catch (\Throwable) {
                DB::table('email_sync_state')->where('connection_id', $id)->update(['error_code' => 'authorization_or_sync_unavailable', 'available_at' => now()->addMinutes(5), 'updated_at' => now()]);
            }
        });
    }

    private function renew(object $c, object $row, array &$state, #[\SensitiveParameter] string $token): void
    {
        if ($c->provider_id === 'gmail') {
            $topic = config('email_integrations.gmail.topic');
            abort_unless(is_string($topic) && preg_match('#^projects/[A-Za-z0-9_-]+/topics/[A-Za-z0-9_.~-]+$#D', $topic), 503);
            $result = $this->http->api('gmail', 'POST', '/watch', $token, ['topicName' => $topic, 'labelIds' => ['INBOX'], 'labelFilterBehavior' => 'include']);
            abort_unless(ctype_digit((string) ($result['expiration'] ?? '')), 503);
            $expires = Carbon::createFromTimestampMs((int) $result['expiration']);
            abort_unless($expires->isFuture() && $expires->lt(now()->addDays(8)), 503);
            DB::table('email_sync_state')->where('connection_id', $c->id)->update(['watch_expires_at' => $expires]);
        } else {
            $origin = rtrim((string) config('email_integrations.webhook_origin'), '/');
            $parts = parse_url($origin);
            abort_unless($parts && ($parts['scheme'] ?? '') === 'https' && isset($parts['host']) && empty($parts['path']) && ! isset($parts['user']) && ! isset($parts['pass']) && ! isset($parts['query']) && ! isset($parts['fragment']), 503);
            $expires = now()->addDay()->toIso8601String();
            if ($row->subscription_id) {
                try {
                    $result = $this->http->api('outlook', 'PATCH', '/subscriptions/'.rawurlencode($row->subscription_id), $token, ['expirationDateTime' => $expires]);
                } catch (EmailProviderFailure $e) {
                    if ($e->status !== 404) {
                        throw $e;
                    } $row->subscription_id = null;
                }
            }
            if (! $row->subscription_id) {
                $secret = bin2hex(random_bytes(32));
                $state['subscription_secret'] = $secret;
                $result = $this->http->api('outlook', 'POST', '/subscriptions', $token, ['changeType' => 'created,updated', 'resource' => 'me/mailFolders/inbox/messages', 'notificationUrl' => $origin.'/api/v1/integration-webhooks/email/outlook', 'lifecycleNotificationUrl' => $origin.'/api/v1/integration-webhooks/email/outlook', 'expirationDateTime' => $expires, 'clientState' => $secret]);
            }
            abort_unless(is_string($result['id'] ?? null) && preg_match('/^[A-Za-z0-9_-]{1,255}$/D', $result['id']) && isset($result['expirationDateTime']), 503);
            $expiry = Carbon::parse($result['expirationDateTime']);
            abort_unless($expiry->isFuture() && $expiry->lt(now()->addDays(8)), 503);
            DB::table('email_sync_state')->where('connection_id', $c->id)->update(['subscription_id' => $result['id'], 'subscription_secret_hash' => hash('sha256', $state['subscription_secret']), 'watch_expires_at' => $expiry]);
        }
        DB::table('email_sync_state')->where('connection_id', $c->id)->update([...$this->vault->encrypt($state, 'email-sync:'.$c->id), 'updated_at' => now()]);
        $this->audit->audit($c->organization_id, $c->id, null, 'email.watch.renewed');
    }

    private function save(string $id, array $state, array $patch): void
    {
        DB::table('email_sync_state')->where('connection_id', $id)->update([...$this->vault->encrypt($state, 'email-sync:'.$id), ...$patch, 'updated_at' => now()]);
    }

    private function enqueue(string $connection, mixed $messageId): void
    {
        abort_unless(is_string($messageId) && preg_match('/^[A-Za-z0-9_=-]{1,512}$/D', $messageId), 503);
        $hash = hash('sha256', $messageId);
        if (DB::table('email_fetch_queue')->where('connection_id', $connection)->where('provider_id_hash', $hash)->exists()) {
            return;
        }
        $id = (string) Str::uuid();
        DB::table('email_fetch_queue')->insert(['id' => $id, 'connection_id' => $connection, 'provider_id_hash' => $hash, ...$this->vault->encrypt(['provider_message_id' => $messageId], 'email-fetch:'.$id), 'status' => 'pending', 'attempt_count' => 0, 'available_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        FetchEmailMessage::dispatch($id)->afterCommit();
    }
}
