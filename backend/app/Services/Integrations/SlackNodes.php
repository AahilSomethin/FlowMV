<?php

namespace App\Services\Integrations;

use Illuminate\Support\Facades\Validator;

class SlackNodes
{
    public const TYPES = ['slack_trigger', 'slack_filter', 'slack_send_message', 'slack_notification'];

    public function normalize(string $type, array $data): array
    {
        $base = ['label', 'description'];
        $specific = match ($type) {
            'slack_trigger' => ['connection_id', 'destination', 'input_mapping'],
            'slack_filter' => ['filter_field', 'match_mode', 'match_value'],
            default => ['connection_id', 'destination', 'text', 'allowed_field_ids'],
        };
        abort_unless(array_diff(array_keys($data), array_merge($base, $specific)) === [], 422);
        $rules = match ($type) {
            'slack_trigger' => ['connection_id' => 'required|uuid', 'destination' => ['required', 'string', 'regex:/^[CG][A-Z0-9]{8,30}$/D'], 'input_mapping' => 'present|array|max:40', 'input_mapping.*' => 'required|in:text,command,channel_id,sender_id,message_id'],
            'slack_filter' => ['filter_field' => 'required|in:command,text,event_type', 'match_mode' => 'required|in:equals,contains,starts_with', 'match_value' => 'required|string|max:100'],
            default => ['connection_id' => 'required|uuid', 'destination' => ['required', 'string', 'regex:/^[CG][A-Z0-9]{8,30}$/D'], 'text' => 'required|string|max:2000', 'allowed_field_ids' => 'present|array|max:40', 'allowed_field_ids.*' => 'required|string|max:64'],
        };
        $v = Validator::make($data, $rules);
        abort_if($v->fails(), 422);
        $clean = array_intersect_key($data, array_flip($specific));
        if (isset($clean['text'])) {
            abort_unless($this->templateValid($clean['text'], $clean['allowed_field_ids']), 422);
        }

        return $clean;
    }

    public function fieldsValid(string $type, array $data, array $form): bool
    {
        $fields = collect($form['fields'])->keyBy('id');
        if ($type === 'slack_trigger') {
            foreach ($data['input_mapping'] as $target => $source) {
                $f = $fields->get($target);
                if (! $f || ! in_array($f['type'], ['text', 'textarea', 'number'], true)) {
                    return false;
                }
                if ($f['type'] === 'number') {
                    return false;
                }
            }
            foreach ($fields as $f) {
                if (($f['required'] ?? false) && ! array_key_exists($f['id'], $data['input_mapping'])) {
                    return false;
                }
            }
        }
        foreach ($data['allowed_field_ids'] ?? [] as $id) {
            if (! $fields->has($id) || $fields[$id]['type'] === 'file') {
                return false;
            }
        }

        return true;
    }

    public function templateValid(string $text, array $fields): bool
    {
        preg_match_all('/\{\{([^}]+)\}\}/u', $text, $matches);
        foreach ($matches[1] as $key) {
            if (in_array($key, ['request.uuid', 'request.status', 'approval.decision'], true)) {
                continue;
            }
            if (! str_starts_with($key, 'input.') || ! in_array(substr($key, 6), $fields, true)) {
                return false;
            }
        }
        $remaining = preg_replace('/\{\{([^}]+)\}\}/u', '', $text);

        return ! str_contains($remaining, '{{') && ! str_contains($remaining, '}}') && preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $text) !== 1;
    }
}
