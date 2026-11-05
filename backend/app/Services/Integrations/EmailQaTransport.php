<?php

namespace App\Services\Integrations;

use App\Support\DemoEnvironment;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Deterministic acceptance transport, bound to one isolated loopback database. */
class EmailQaTransport
{
    public function __construct(private CredentialVault $vault) {}

    public function assertLocal(): void
    {
        if (app()->environment('demo')) {
            app(DemoEnvironment::class)->assertOwned();

            return;
        }
        abort_unless(app()->environment('phase9c') && config('email_integrations.simulated') && config('database.default') === 'pgsql' && in_array(config('database.connections.pgsql.host'), ['127.0.0.1', 'localhost', '::1'], true) && config('database.connections.pgsql.database') === 'flowmv_phase9c_qa' && empty(config('database.connections.pgsql.url')), 503);
        $actual = DB::selectOne('select current_database() as name, host(inet_server_addr()) as address');
        abort_unless($actual->name === 'flowmv_phase9c_qa' && in_array($actual->address, ['127.0.0.1', '::1'], true), 503);
        foreach (EmailProviders::IDS as $provider) {
            abort_unless(config("email_integrations.$provider.client_id") === 'FLOWMV_QA_EMAIL_CLIENT' && config("email_integrations.$provider.client_secret") === 'FLOWMV_QA_EMAIL_SECRET', 503);
        }
    }

    private function save(string $key, array $data): void
    {
        Cache::put('email-qa:'.$key, $this->vault->encrypt($data, 'email-qa:'.$key), app()->environment('demo') ? 15552000 : 86400);
    }

    private function read(string $key): array
    {
        $data = Cache::get('email-qa:'.$key);

        return is_array($data) ? $this->vault->decrypt((object) $data, 'email-qa:'.$key) : [];
    }

    public function connect(Request $request, int $org, array $data, string $address): string
    {
        $this->assertLocal();
        $address = app(EmailProviders::class)->address($address);
        abort_unless(str_ends_with($address, '@example.com'), 422);
        $result = app(EmailOAuth::class)->start($request, $org, $data);
        parse_str(parse_url($result['authorization_url'], PHP_URL_QUERY), $query);
        $code = 'FLOWMV_QA_EMAIL_'.Str::random(40);
        $this->save('code:'.hash('sha256', $code), ['address' => $address, 'provider' => $data['provider'], 'scope' => $query['scope'], 'challenge' => $query['code_challenge'], 'redirect' => $query['redirect_uri']]);

        return app(EmailOAuth::class)->finish($request, $org, $data['provider'], $query['state'], $code);
    }

    public function attach(EmailHttp $http): void
    {
        $this->assertLocal();
        $http->http->preventStrayRequests();
        $http->http->fake(function ($request) {
            $path = parse_url($request->url(), PHP_URL_PATH);
            if (str_ends_with($path, '/token')) {
                $refresh = $request['grant_type'] === 'refresh_token';
                $key = ($refresh ? 'refresh:' : 'code:').hash('sha256', (string) $request[$refresh ? 'refresh_token' : 'code']);
                $grant = $this->read($key);
                if (! $grant || (! $refresh && (! hash_equals($grant['challenge'], rtrim(strtr(base64_encode(hash('sha256', (string) $request['code_verifier'], true)), '+/', '-_'), '=')) || $grant['redirect'] !== $request['redirect_uri']))) {
                    return Factory::response([], 400);
                }
                Cache::forget('email-qa:'.$key);
                $access = 'FLOWMV_QA_EMAIL_'.Str::random(40);
                $refreshToken = 'FLOWMV_QA_EMAIL_'.Str::random(40);
                $this->save('token:'.hash('sha256', $access), $grant);
                $this->save('refresh:'.hash('sha256', $refreshToken), $grant);

                return Factory::response(['access_token' => $access, 'refresh_token' => $refreshToken, 'token_type' => 'Bearer', 'expires_in' => 3600, 'scope' => $grant['scope']]);
            }
            $token = preg_replace('/^Bearer /', '', $request->header('Authorization')[0] ?? '');
            $grant = $this->read('token:'.hash('sha256', $token));
            if (! $grant || ! str_starts_with($token, 'FLOWMV_QA_EMAIL_')) {
                return Factory::response([], 401);
            }
            $address = $grant['address'];
            $identity = ['id' => 'qa-'.hash('sha256', $address), 'mail' => $address];
            $reply = fn ($body, $status = 200) => Factory::response($body, $status);
            if (str_ends_with($path, '/userinfo')) {
                return $reply(['sub' => $identity['id'], 'email' => $address, 'email_verified' => true]);
            }
            if ($path === '/v1.0/me') {
                return $reply($identity);
            }
            $messages = $this->read('mail:'.$address)['messages'] ?? [];
            if (str_ends_with($path, '/watch')) {
                return $reply(['historyId' => (string) count($messages), 'expiration' => now()->addDays(6)->getTimestampMs()]);
            }
            if (str_ends_with($path, '/profile')) {
                return $reply(['historyId' => (string) count($messages)]);
            }
            if (str_contains($path, '/subscriptions')) {
                return $reply(['id' => 'qa-sub-'.substr(hash('sha256', $address), 0, 20), 'expirationDateTime' => now()->addDay()->toIso8601String()]);
            }
            if (str_ends_with($path, '/sendMail')) {
                return $reply([], 202);
            }
            if (str_ends_with($path, '/messages/send')) {
                return $reply(['id' => 'qa-sent-'.Str::random(12)]);
            }
            if (str_ends_with($path, '/history')) {
                return $reply(['historyId' => (string) count($messages), 'history' => array_map(fn ($m) => ['messagesAdded' => [['message' => ['id' => $m['id']]]]], $messages)]);
            }
            if (str_ends_with($path, '/delta')) {
                return $reply(['value' => array_map(fn ($m) => ['id' => $m['id'], 'receivedDateTime' => $m['receivedDateTime']], $messages), '@odata.deltaLink' => 'https://graph.microsoft.com/v1.0/me/mailFolders/inbox/messages/delta?$deltatoken=qa']);
            }
            if (str_ends_with($path, '/messages')) {
                return $reply(['messages' => []]);
            }
            foreach ($messages as $m) {
                if (str_ends_with($path, '/messages/'.$m['id'])) {
                    $safe = $m;
                    unset($safe['qa_document']);

                    return $reply($safe);
                }
                if (str_ends_with($path, '/messages/'.$m['id'].'/attachments')) {
                    return $reply(['value' => [['@odata.type' => '#microsoft.graph.fileAttachment', 'id' => 'qa-doc', 'name' => 'application.docx', 'size' => strlen(base64_decode($m['qa_document'])), 'isInline' => false]]]);
                }
                if (str_ends_with($path, '/attachments/qa-doc')) {
                    return $reply(['contentBytes' => $m['qa_document']]);
                }
            }

            return $reply([], 404);
        });
    }

    public function emit(int $org, string $id, string $sender, string $scenario): void
    {
        $this->assertLocal();
        $c = app(EmailAuthorization::class)->connection($org, $id, 'read');
        abort_unless($c->provider_id === 'outlook', 422, 'Use Outlook for browser event simulation. Gmail signed push is covered by automated tests.');
        $sender = app(EmailProviders::class)->address($sender);
        abort_unless(str_ends_with($sender, '@example.com') && in_array($scenario, ['complete', 'missing', 'invalid', 'duplicate', 'blocked'], true), 422);
        $box = DB::table('email_mailboxes')->where('connection_id', $id)->first();
        $messages = $this->read('mail:'.$box->address)['messages'] ?? [];
        if ($scenario !== 'duplicate') {
            $lines = ['Employee name: Synthetic Applicant', 'Start date: 2026-11-01', 'End date: '.($scenario === 'invalid' ? '2026-10-01' : '2026-11-05')];
            if ($scenario !== 'missing') {
                $lines[] = 'Employee ID: QA-900';
            }
            $tmp = tempnam(sys_get_temp_dir(), 'email-qa-');
            try {
                $zip = new \ZipArchive;
                $zip->open($tmp, \ZipArchive::OVERWRITE);
                $zip->addFromString('[Content_Types].xml', '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/></Types>');
                $zip->addFromString('_rels/.rels', '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/></Relationships>');
                $zip->addFromString('word/document.xml', '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body>'.implode('', array_map(fn ($line) => '<w:p><w:r><w:t>'.htmlspecialchars($line, ENT_XML1).'</w:t></w:r></w:p>', $lines)).'</w:body></w:document>');
                if ($scenario === 'blocked') {
                    $zip->addFromString('FLOWMV_QA_MALWARE_MARKER', 'synthetic scanner rejection');
                }
                $zip->close();
                $doc = base64_encode(file_get_contents($tmp));
            } finally {
                @unlink($tmp);
            }
            $mid = 'qa-'.Str::random(20);
            $messages[] = ['id' => $mid, 'receivedDateTime' => now()->toIso8601String(), 'internetMessageId' => '<'.$mid.'@example.com>', 'conversationId' => $mid, 'from' => ['emailAddress' => ['address' => $sender]], 'toRecipients' => [['emailAddress' => ['address' => $box->address]]], 'subject' => 'Application QA', 'body' => ['contentType' => 'text', 'content' => 'Synthetic application'], 'hasAttachments' => true, 'internetMessageHeaders' => [['name' => 'Auto-Submitted', 'value' => 'no']], 'qa_document' => $doc];
            $this->save('mail:'.$box->address, ['messages' => array_slice($messages, -50)]);
        }
        abort_unless(count($messages) > 0, 409);
        $sync = DB::table('email_sync_state')->where('connection_id', $id)->first();
        abort_unless($sync->subscription_id, 409, 'Run mailbox synchronization first.');
        $secret = $this->vault->decrypt($sync, 'email-sync:'.$id)['subscription_secret'];
        app(EmailIngress::class)->outlook(['value' => [['subscriptionId' => $sync->subscription_id, 'clientState' => $secret, 'changeType' => 'created', 'resourceData' => ['id' => end($messages)['id']]]]]);
    }
}
