<?php

namespace App\Services\Integrations;

use Illuminate\Support\Facades\Validator;

class SmsNodes
{
    public const TYPES = ['sms_notification', 'sms_send_message'];

    public function normalize(string $type, array $data): array
    {
        abort_unless(in_array($type, self::TYPES, true) && array_diff(array_keys($data), ['label', 'description', 'connection_id', 'sender_id', 'recipient_id', 'template_id']) === [], 422);
        $v = Validator::make($data, ['connection_id' => 'required|uuid', 'sender_id' => 'required|uuid', 'recipient_id' => 'required|uuid', 'template_id' => 'required|uuid']);
        abort_if($v->fails(), 422);

        return $v->validated();
    }

    public function template(string $text): void
    {
        abort_unless(strlen($text) > 0 && strlen($text) <= 300, 422);
        preg_match_all('/\{\{([^}]+)\}\}/', $text, $matches);
        abort_unless(array_diff($matches[1], ['request.uuid', 'request.status', 'request.link', 'approval.decision']) === [], 422);
        $plain = preg_replace('/\{\{([^}]+)\}\}/', 'X', $text);
        abort_unless(preg_match("~^[A-Za-z0-9 .,:;!?@#%&()/_+=\-'\r\n]+$~D", $plain), 422);
    }

    public function message(string $text): void
    {
        abort_unless(strlen($text) > 0 && strlen($text) <= 160 && preg_match("~^[A-Za-z0-9 .,:;!?@#%&()/_+=\-'\r\n]+$~D", $text), 422);
    }
}
