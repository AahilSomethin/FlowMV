<?php

namespace App\Services\Integrations;

use Illuminate\Encryption\Encrypter;

class CredentialVault
{
    public function encrypt(#[\SensitiveParameter] array $secret, string $connectionId): array
    {
        $version = config('integrations.active_key');

        return ['key_version' => $version, 'ciphertext' => $this->encrypter($version)->encryptString(json_encode(['connection_id' => $connectionId, 'secret' => $secret], JSON_THROW_ON_ERROR))];
    }

    public function decrypt(object $record, string $connectionId): array
    {
        try {
            $value = json_decode($this->encrypter($record->key_version)->decryptString($record->ciphertext), true, flags: JSON_THROW_ON_ERROR);
            if (($value['connection_id'] ?? null) !== $connectionId || ! is_array($value['secret'] ?? null)) {
                throw new \RuntimeException;
            }

            return $value['secret'];
        } catch (\Throwable) {
            abort(503, 'Credential unavailable.');
        }
    }

    private function encrypter(string $version): Encrypter
    {
        $encoded = config('integrations.keys.'.$version);
        $key = is_string($encoded) ? base64_decode($encoded, true) : false;
        $applicationKey = (string) config('app.key');
        $applicationKey = str_starts_with($applicationKey, 'base64:') ? base64_decode(substr($applicationKey, 7), true) : $applicationKey;
        if ($key === false || strlen($key) !== 32 || (is_string($applicationKey) && hash_equals($applicationKey, $key))) {
            abort(503, 'Credential key unavailable.');
        }

        return new Encrypter($key, 'AES-256-GCM');
    }
}
