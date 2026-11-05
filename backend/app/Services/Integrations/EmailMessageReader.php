<?php

namespace App\Services\Integrations;

use App\Exceptions\EmailProviderFailure;
use App\Jobs\ProcessEmailAttachment;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Mime\Address;

class EmailMessageReader
{
    public function __construct(private EmailAuthorization $authorization, private EmailHttp $http, private EmailTokens $tokens, private CredentialVault $vault, private EmailProviders $providers, private DocumentPipeline $documents, private ConnectionManager $audit) {}

    public function process(string $workId): void
    {
        DB::transaction(function () use ($workId) {
            $row = DB::table('email_fetch_queue')->where('id', $workId)->lockForUpdate()->first();
            if (! $row || $row->status !== 'pending' || Carbon::parse($row->available_at)->isFuture()) {
                return;
            }
            try {
                $c = DB::table('connections')->where('id', $row->connection_id)->first();
                abort_unless($c, 403);
                $this->authorization->connection($c->organization_id, $c->id, 'read');
                $mailbox = DB::table('email_mailboxes')->where('connection_id', $c->id)->first();
                $token = $this->tokens->get($c->organization_id, $c->id);
                $ref = $this->vault->decrypt($row, 'email-fetch:'.$row->id)['provider_message_id'];
                $data = $this->read($c->provider_id, $ref, $token);
                $received = Carbon::parse($data['received_at']);
                if ($received->lt(Carbon::parse($mailbox->connected_at)) || $received->gt(now()->addMinute()) || $data['automated']) {
                    $this->done('email_fetch_queue', $row->id, 'ignored');

                    return;
                }
                if (DB::table('email_messages')->where('connection_id', $c->id)->where('provider_id_hash', $row->provider_id_hash)->exists()) {
                    $this->done('email_fetch_queue', $row->id);

                    return;
                }
                $user = DB::table('users as u')->join('organization_memberships as m', 'm.user_id', '=', 'u.id')->where('m.organization_id', $c->organization_id)->whereNull('m.revoked_at')->whereNotNull('u.email_verified_at')->whereRaw('lower(u.email) = ?', [$data['sender']])->value('u.id');
                if ($c->owner_user_id !== null && (int) $c->owner_user_id !== (int) $user) {
                    $user = null;
                }
                $id = (string) Str::uuid();
                $attachments = $data['attachments'];
                unset($data['attachments']);
                DB::table('email_messages')->insert(['id' => $id, 'connection_id' => $c->id, 'organization_id' => $c->organization_id, 'provider_id_hash' => $row->provider_id_hash, ...$this->vault->encrypt($data, 'email-message:'.$id), 'sender_user_id' => $user, 'status' => $user ? 'pending' : 'unlinked', 'received_at' => $received, 'expires_at' => now()->addDays((int) config('email_integrations.documents.retention_days'))]);
                foreach ($attachments as $index => $attachment) {
                    $attachmentId = (string) Str::uuid();
                    DB::table('email_attachment_fetches')->insert(['id' => $attachmentId, 'message_id' => $id, ...$this->vault->encrypt([...$attachment, 'provider_message_id' => $ref], 'email-attachment:'.$attachmentId), 'status' => 'pending', 'available_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
                    ProcessEmailAttachment::dispatch($attachmentId)->afterCommit();
                }
                $this->done('email_fetch_queue', $row->id);
                $this->audit->audit($c->organization_id, $c->id, null, 'email.message.received');
                if ($attachments === []) {
                    app(EmailWorkflow::class)->ingest($id);
                }
            } catch (EmailProviderFailure $e) {
                $this->retry('email_fetch_queue', $row, $e);
            } catch (\Throwable) {
                $this->done('email_fetch_queue', $row->id, 'blocked', 'authorization_or_message_unavailable');
            }
        });
    }

    public function attachment(string $id): void
    {
        DB::transaction(function () use ($id) {
            $row = DB::table('email_attachment_fetches')->where('id', $id)->lockForUpdate()->first();
            if (! $row || $row->status !== 'pending' || Carbon::parse($row->available_at)->isFuture()) {
                return;
            }
            $message = DB::table('email_messages')->where('id', $row->message_id)->first();
            try {
                abort_unless($message && Carbon::parse($message->expires_at)->isFuture(), 403);
                $c = $this->authorization->connection($message->organization_id, $message->connection_id, 'read');
                $ref = $this->vault->decrypt($row, 'email-attachment:'.$id);
                if (isset($ref['inline_data'])) {
                    $encoded = $ref['inline_data'];
                } else {
                    $token = $this->tokens->get($c->organization_id, $c->id);
                    $path = ($c->provider_id === 'gmail' ? '/messages/' : '/me/messages/').rawurlencode($ref['provider_message_id']).'/attachments/'.rawurlencode($ref['attachment_id']);
                    $body = $this->http->api($c->provider_id, 'GET', $path, $token);
                    $encoded = $body[$c->provider_id === 'gmail' ? 'data' : 'contentBytes'] ?? null;
                }
                abort_unless(is_string($encoded) && strlen($encoded) <= (int) config('email_integrations.documents.max_bytes') * 1.4, 422);
                $bytes = base64_decode(strtr($encoded, '-_', '+/'), true);
                abort_unless(is_string($bytes), 422);
                $document = $this->documents->store($message->organization_id, $message->sender_user_id, $ref['name'], $bytes, $message->id, $id);
                $result = $this->documents->inspect($message->organization_id, $document);
                $this->done('email_attachment_fetches', $id, $result['status'] === 'extracted' ? 'done' : 'blocked', $result['error_code'] ?? null);
            } catch (EmailProviderFailure $e) {
                $this->retry('email_attachment_fetches', $row, $e);
            } catch (\Throwable) {
                $this->done('email_attachment_fetches', $id, 'blocked', 'attachment_unavailable');
            }
            if ($message && ! DB::table('email_attachment_fetches')->where('message_id', $message->id)->whereIn('status', ['pending', 'processing'])->exists()) {
                app(EmailWorkflow::class)->ingest($message->id);
            }
        });
    }

    private function read(string $provider, string $id, #[\SensitiveParameter] string $token): array
    {
        $m = $this->http->api($provider, 'GET', ($provider === 'gmail' ? '/messages/' : '/me/messages/').rawurlencode($id), $token, $provider === 'gmail' ? ['format' => 'full'] : ['$select' => 'id,conversationId,internetMessageId,receivedDateTime,from,toRecipients,replyTo,subject,body,hasAttachments,internetMessageHeaders']);
        $headers = [];
        foreach (($provider === 'gmail' ? ($m['payload']['headers'] ?? []) : ($m['internetMessageHeaders'] ?? [])) as $header) {
            $name = strtolower((string) ($header['name'] ?? ''));
            $value = (string) ($header['value'] ?? '');
            abort_if(strlen($name) > 100 || strlen($value) > 10000, 422);
            if (isset($headers[$name]) && in_array($name, ['from', 'message-id', 'reply-to'], true)) {
                abort(422);
            }
            $headers[$name] = $value;
        }
        $sender = $provider === 'gmail' ? $this->address($headers['from'] ?? '') : $this->providers->address($m['from']['emailAddress']['address'] ?? '');
        $recipients = [];
        if ($provider === 'gmail') {
            foreach (explode(',', $headers['to'] ?? '') as $part) {
                try {
                    $recipients[] = $this->address($part);
                } catch (\Throwable) {
                }
            }
        } else {
            foreach ($m['toRecipients'] ?? [] as $recipient) {
                $recipients[] = $this->providers->address($recipient['emailAddress']['address'] ?? '');
            }
        }
        abort_if(count($recipients) > 50, 422);
        $subject = $provider === 'gmail' ? ($headers['subject'] ?? '') : ($m['subject'] ?? '');
        abort_unless(is_string($subject) && mb_strlen($subject) <= 500 && ! preg_match('/[\x00-\x1f]/', $subject), 422);
        $attachments = [];
        $text = '';
        $issue = null;
        if ($provider === 'gmail') {
            $parts = [$m['payload'] ?? []];
            $count = 0;
            while ($parts !== []) {
                $part = array_shift($parts);
                abort_if(++$count > 50, 422);
                foreach ($part['parts'] ?? [] as $nested) {
                    $parts[] = $nested;
                }
                $name = $part['filename'] ?? '';
                if ($name !== '') {
                    if (! in_array(strtolower(pathinfo($name, PATHINFO_EXTENSION)), ['pdf', 'docx'], true)) {
                        $issue = 'unsupported_attachment';

                        continue;
                    }
                    $ref = ['name' => $name];
                    if (isset($part['body']['attachmentId'])) {
                        $ref['attachment_id'] = $part['body']['attachmentId'];
                    } elseif (isset($part['body']['data'])) {
                        $ref['inline_data'] = $part['body']['data'];
                    } else {
                        $issue = 'attachment_unavailable';

                        continue;
                    }
                    $attachments[] = $ref;
                } elseif (($part['mimeType'] ?? '') === 'text/plain' && isset($part['body']['data'])) {
                    $encoded = $part['body']['data'];
                    abort_if(strlen($encoded) > 280000, 422);
                    $decoded = base64_decode(strtr($encoded, '-_', '+/'), true);
                    abort_unless(is_string($decoded) && mb_check_encoding($decoded, 'UTF-8'), 422);
                    $text .= $decoded."\n";
                }
                abort_if(count($attachments) > (int) config('email_integrations.documents.max_count') || strlen($text) > 200000, 422);
            }
            abort_unless(ctype_digit((string) ($m['internalDate'] ?? '')), 422);
            $date = Carbon::createFromTimestampMs((int) $m['internalDate'])->toIso8601String();
            $reply = isset($headers['reply-to']) ? $this->address($headers['reply-to']) : $sender;
        } else {
            $text = ($m['body']['contentType'] ?? '') === 'text' ? ($m['body']['content'] ?? '') : '';
            abort_unless(is_string($text) && strlen($text) <= 200000, 422);
            $date = $m['receivedDateTime'] ?? '';
            abort_unless(is_string($date) && preg_match('/^\d{4}-\d{2}-\d{2}T[0-9:.]+(?:Z|[+-]\d{2}:\d{2})$/D', $date), 422);
            $reply = $sender;
            if (! empty($m['replyTo'])) {
                abort_unless(count($m['replyTo']) === 1, 422);
                $reply = $this->providers->address($m['replyTo'][0]['emailAddress']['address'] ?? '');
            }
            if ($m['hasAttachments'] ?? false) {
                $files = $this->http->api('outlook', 'GET', '/me/messages/'.rawurlencode($id).'/attachments', $token, ['$top' => 6, '$select' => 'id,name,size,contentType,isInline']);
                abort_if(isset($files['@odata.nextLink']) || count($files['value'] ?? []) > (int) config('email_integrations.documents.max_count'), 422);
                foreach ($files['value'] ?? [] as $file) {
                    if (($file['@odata.type'] ?? '') !== '#microsoft.graph.fileAttachment' || ! in_array(strtolower(pathinfo($file['name'] ?? '', PATHINFO_EXTENSION)), ['pdf', 'docx'], true)) {
                        $issue = 'unsupported_attachment';

                        continue;
                    }
                    abort_if(($file['size'] ?? PHP_INT_MAX) > (int) config('email_integrations.documents.max_bytes'), 422);
                    $attachments[] = ['name' => $file['name'], 'attachment_id' => $file['id']];
                }
            }
        }
        foreach ($attachments as $ref) {
            abort_unless(is_string($ref['name']) && mb_strlen($ref['name']) <= 120 && (! isset($ref['attachment_id']) || (is_string($ref['attachment_id']) && preg_match('/^[A-Za-z0-9_=-]{1,1024}$/D', $ref['attachment_id']))), 422);
        }
        $automated = isset($headers['x-flowmv-automation']) || (isset($headers['auto-submitted']) && strtolower(trim($headers['auto-submitted'])) !== 'no') || in_array(strtolower($headers['precedence'] ?? ''), ['bulk', 'list', 'junk'], true);

        return ['provider_message_id' => $id, 'thread_id' => $m[$provider === 'gmail' ? 'threadId' : 'conversationId'] ?? null, 'internet_message_id' => $provider === 'gmail' ? ($headers['message-id'] ?? null) : ($m['internetMessageId'] ?? null), 'sender' => $sender, 'reply_to' => $reply, 'recipients' => $recipients, 'subject' => $subject, 'text' => trim($text), 'received_at' => $date, 'automated' => $automated, 'attachment_issue' => $issue, 'attachments' => $attachments];
    }

    private function address(string $value): string
    {
        return $this->providers->address(Address::create($value)->getAddress());
    }

    private function done(string $table, string $id, string $status = 'done', ?string $error = null): void
    {
        DB::table($table)->where('id', $id)->update(['status' => $status, 'lease_expires_at' => null, 'error_code' => $error, 'updated_at' => now()]);
    }

    private function retry(string $table, object $row, EmailProviderFailure $error): void
    {
        $attempt = $row->attempt_count + 1;
        $retry = $attempt < 5 && in_array($error->status, [0, 429, 500, 502, 503, 504], true);
        DB::table($table)->where('id', $row->id)->update(['status' => $retry ? 'pending' : 'blocked', 'attempt_count' => $attempt, 'available_at' => now()->addSeconds(max($error->retryAfter, min(1800, 30 * (2 ** min(6, $attempt))))), 'error_code' => $error->reason, 'updated_at' => now()]);
    }
}
