<?php

namespace App\Services\Integrations;

use App\Exceptions\EmailProviderFailure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;

class EmailHttp
{
    public function __construct(public Factory $http, private EmailProviders $providers) {}

    public function token(string $provider, #[\SensitiveParameter] array $form): array
    {
        $form['client_id'] = config("email_integrations.$provider.client_id");
        $form['client_secret'] = config("email_integrations.$provider.client_secret");
        abort_unless(is_string($form['client_id']) && $form['client_id'] !== '' && is_string($form['client_secret']) && $form['client_secret'] !== '', 503);

        return $this->request('POST', $this->providers->definition($provider)['token'], null, $form, true);
    }

    public function api(string $provider, string $method, string $path, #[\SensitiveParameter] string $token, array $data = []): array
    {
        abort_unless(in_array($method, ['GET', 'POST', 'PATCH', 'DELETE'], true), 422);
        $prefix = $this->providers->definition($provider)['api'];
        abort_unless(str_starts_with($path, '/') && ! str_contains($path, '..') && ! str_contains($path, '#') && ! str_contains($path, '\\') && strlen($path) < 16000 && ! preg_match('/[\r\n]/', $path), 422);
        $allowed = $provider === 'gmail'
            ? '#^/(?:profile|watch|stop|history|messages(?:/[A-Za-z0-9_-]+(?:/attachments/[A-Za-z0-9_-]+)?)?|messages/send)(?:\?|$)#D'
            : '#^/(?:me(?:/(?:messages(?:/[A-Za-z0-9_=%.-]+(?:/attachments(?:/[A-Za-z0-9_=%.-]+)?|/reply|/send)?)?|mailFolders/inbox/messages/delta|sendMail))?|subscriptions(?:/[A-Za-z0-9_-]+)?)(?:\?|$)#Di';
        abort_unless(preg_match($allowed, $path), 422);

        return $this->request($method, $prefix.$path, $token, $data);
    }

    public function graphContinuation(string $url): string
    {
        $p = parse_url($url);
        abort_unless($p && ($p['scheme'] ?? '') === 'https' && ($p['host'] ?? '') === 'graph.microsoft.com' && ! isset($p['port']) && ! isset($p['user']) && ! isset($p['pass']) && ! isset($p['fragment']) && ($p['path'] ?? '') === '/v1.0/me/mailFolders/inbox/messages/delta', 422);

        return substr($p['path'], strlen('/v1.0')).(isset($p['query']) ? '?'.$p['query'] : '');
    }

    public function identity(string $provider, #[\SensitiveParameter] string $token): array
    {
        if ($provider === 'gmail') {
            $result = $this->request('GET', 'https://openidconnect.googleapis.com/v1/userinfo', $token, []);
            abort_unless(is_string($result['sub'] ?? null) && ($result['email_verified'] ?? false) === true, 403);

            return ['id' => $result['sub'], 'address' => $this->providers->address($result['email'] ?? '')];
        }
        $result = $this->api($provider, 'GET', '/me', $token);
        abort_unless(is_string($result['id'] ?? null) && strlen($result['id']) <= 255, 403);

        return ['id' => $result['id'], 'address' => $this->providers->address($result['mail'] ?? $result['userPrincipalName'] ?? '')];
    }

    public function googleCertificates(): array
    {
        return $this->request('GET', 'https://www.googleapis.com/oauth2/v1/certs', null, []);
    }

    public function sendMime(string $provider, #[\SensitiveParameter] string $token, #[\SensitiveParameter] string $raw, ?string $thread = null): void
    {
        abort_if(strlen($raw) > 50000, 422);
        if ($provider === 'gmail') {
            $data = ['raw' => rtrim(strtr(base64_encode($raw), '+/', '-_'), '=')];
            if ($thread !== null) {
                abort_unless(preg_match('/^[A-Za-z0-9_-]{1,256}$/D', $thread), 422);
                $data['threadId'] = $thread;
            }
            $response = $this->request('POST', $this->providers->definition('gmail')['api'].'/messages/send', $token, $data, false, null, 200, true);
            if (! is_string($response['id'] ?? null) || $response['id'] === '') {
                throw new EmailProviderFailure('delivery_uncertain', 0);
            }
        } else {
            $this->request('POST', 'https://graph.microsoft.com/v1.0/me/sendMail', $token, [], false, base64_encode($raw), 202, true);
        }
    }

    private function request(string $method, string $url, #[\SensitiveParameter] ?string $token, #[\SensitiveParameter] array $data, bool $form = false, #[\SensitiveParameter] ?string $rawBody = null, ?int $expectedStatus = null, bool $delivery = false): array
    {
        try {
            $request = $this->http->acceptJson()->connectTimeout(3)->timeout(12)->withOptions(['allow_redirects' => false, 'verify' => true, 'debug' => false, 'progress' => function ($total, $downloaded) {
                if ($downloaded > 16 * 1024 * 1024) {
                    throw new \RuntimeException('Provider response too large.');
                }
            }, 'on_headers' => function ($response) {
                if ((int) $response->getHeaderLine('Content-Length') > 16 * 1024 * 1024) {
                    throw new \RuntimeException('Provider response too large.');
                }
            }]);
            if ($token !== null) {
                $request = $request->withToken($token)->withHeaders(['Prefer' => 'IdType="ImmutableId", outlook.body-content-type="text"']);
            }
            if ($form) {
                $request = $request->asForm();
            }
            $response = $rawBody === null ? $request->send($method, $url, [$method === 'GET' ? 'query' : ($form ? 'form_params' : 'json') => $data]) : $request->withBody($rawBody, 'text/plain')->send($method, $url);
            if (! $response->successful()) {
                throw new EmailProviderFailure(match ($response->status()) {
                    401 => 'reauthorization_required',403 => 'provider_permission_denied',404 => 'provider_not_found',410 => 'cursor_expired',429 => 'rate_limited',default => 'provider_unavailable'
                }, $response->status(), min(3600, max(5, (int) $response->header('Retry-After'))));
            }
            if ($expectedStatus !== null && $response->status() !== $expectedStatus) {
                throw new EmailProviderFailure('delivery_uncertain', 0);
            }
            abort_if(strlen($response->body()) > 16 * 1024 * 1024, 503);
            $body = $response->body() === '' ? [] : $response->json();
            abort_unless(is_array($body), 503);

            return $body;
        } catch (EmailProviderFailure $e) {
            throw $e;
        } catch (ConnectionException) {
            throw new EmailProviderFailure('transport_uncertain', 0, 30);
        } catch (\Throwable) {
            throw new EmailProviderFailure($delivery ? 'delivery_uncertain' : 'provider_unavailable', $delivery ? 0 : 503, 30);
        }
    }
}
