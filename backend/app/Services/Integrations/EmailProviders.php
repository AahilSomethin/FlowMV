<?php

namespace App\Services\Integrations;

class EmailProviders
{
    public const IDS = ['gmail', 'outlook'];

    public function definition(string $provider): array
    {
        abort_unless(in_array($provider, self::IDS, true), 422);
        if ($provider === 'gmail') {
            return ['authorize' => 'https://accounts.google.com/o/oauth2/v2/auth', 'token' => 'https://oauth2.googleapis.com/token', 'api' => 'https://gmail.googleapis.com/gmail/v1/users/me', 'scopes' => ['read' => 'https://www.googleapis.com/auth/gmail.readonly', 'send' => 'https://www.googleapis.com/auth/gmail.send']];
        }
        $tenant = (string) config('email_integrations.outlook.tenant', 'organizations');
        abort_unless(in_array($tenant, ['organizations', 'consumers'], true) || preg_match('/^[a-f0-9-]{36}$/Di', $tenant), 503);

        return ['authorize' => "https://login.microsoftonline.com/$tenant/oauth2/v2.0/authorize", 'token' => "https://login.microsoftonline.com/$tenant/oauth2/v2.0/token", 'api' => 'https://graph.microsoft.com/v1.0', 'scopes' => ['read' => 'https://graph.microsoft.com/Mail.Read', 'send' => 'https://graph.microsoft.com/Mail.Send']];
    }

    public function capabilities(array $capabilities): array
    {
        abort_unless($capabilities !== [] && count($capabilities) <= 2 && count(array_unique($capabilities)) === count($capabilities) && array_diff($capabilities, ['read', 'send']) === [], 422);
        sort($capabilities);

        return $capabilities;
    }

    public function scopes(string $provider, array $capabilities): array
    {
        $definition = $this->definition($provider);
        $scopes = array_map(fn ($capability) => $definition['scopes'][$capability], $this->capabilities($capabilities));

        return $provider === 'outlook' ? [...$scopes, 'offline_access', 'https://graph.microsoft.com/User.Read'] : [...$scopes, 'openid', 'email'];
    }

    public function address(string $address): string
    {
        $address = strtolower(trim($address));
        abort_unless(strlen($address) <= 254 && preg_match('/^[\x21-\x7e]+$/D', $address) && filter_var($address, FILTER_VALIDATE_EMAIL), 422);

        return $address;
    }

    public function callback(string $provider): string
    {
        $url = (string) config("email_integrations.$provider.redirect_uri");
        $parts = parse_url($url);
        abort_unless($parts && ($parts['scheme'] ?? '') === 'https' && isset($parts['host']) && ! isset($parts['user']) && ! isset($parts['pass']) && ! isset($parts['query']) && ! isset($parts['fragment']), 503);

        return $url;
    }
}
