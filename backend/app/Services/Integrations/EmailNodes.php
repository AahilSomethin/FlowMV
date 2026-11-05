<?php

namespace App\Services\Integrations;

use Illuminate\Support\Facades\Validator;

class EmailNodes
{
    public const TYPES = ['email_trigger', 'email_filter', 'email_send', 'email_reply', 'attachment_inspector', 'document_validation'];

    public function normalize(string $type, array $data): array
    {
        $specific = match ($type) {
            'email_trigger' => ['connection_id', 'input_mapping', 'rules', 'match'],
            'email_filter' => ['rules', 'match'],
            'attachment_inspector' => ['require_attachments'],
            'document_validation' => ['template_id', 'document_fields', 'field_map', 'require_document'],
            'email_send' => ['connection_id', 'destination', 'subject', 'text', 'allowed_field_ids'],
            'email_reply' => ['connection_id', 'destination', 'text', 'allowed_field_ids'],
        };
        abort_unless(array_diff(array_keys($data), ['label', 'description', ...$specific]) === [], 422);
        $rules = match ($type) {
            'email_trigger' => ['connection_id' => 'required|uuid', 'input_mapping' => 'present|array|max:40', 'input_mapping.*' => ['required', 'string', 'regex:/^(?:sender|subject|text|document\.[a-z][a-z0-9_]{0,39})$/D'], 'rules' => 'present|array|max:10', 'match' => 'required|in:all,any'],
            'email_filter' => ['rules' => 'required|array|min:1|max:10', 'match' => 'required|in:all,any'],
            'attachment_inspector' => ['require_attachments' => 'required|boolean'],
            'document_validation' => ['template_id' => 'required|uuid', 'document_fields' => 'required|array|min:1|max:20', 'field_map' => 'present|array|max:20', 'field_map.*' => 'required|string|max:64', 'require_document' => 'required|boolean'],
            default => ['connection_id' => 'required|uuid', 'destination' => 'required|string|max:254', 'subject' => ($type === 'email_send' ? 'required' : 'sometimes').'|string|max:200', 'text' => 'required|string|max:4000', 'allowed_field_ids' => 'present|array|max:40', 'allowed_field_ids.*' => 'required|string|max:64'],
        };
        abort_if(Validator::make($data, $rules)->fails(), 422);
        if (isset($data['rules'])) {
            $this->rules($data['rules']);
        }
        if (isset($data['document_fields'])) {
            $data['document_fields'] = app(DocumentTemplates::class)->schema($data['document_fields']);
        }
        if (isset($data['destination'])) {
            $data['destination'] = app(EmailProviders::class)->address($data['destination']);
        }
        foreach (['subject', 'text'] as $key) {
            if (isset($data[$key])) {
                abort_unless($this->templateValid($data[$key], $data['allowed_field_ids']), 422);
            }
        }
        if (isset($data['subject'])) {
            abort_if(preg_match('/[\r\n]/', $data['subject']), 422);
        }

        return array_intersect_key($data, array_flip($specific));
    }

    public function rules(array $rules): void
    {
        abort_unless(array_is_list($rules) && count($rules) <= 10, 422);
        foreach ($rules as $rule) {
            abort_unless(is_array($rule) && array_diff(array_keys($rule), ['field', 'operator', 'value']) === [] && in_array($rule['field'] ?? '', ['sender', 'recipient', 'subject', 'text', 'has_attachments'], true) && in_array($rule['operator'] ?? '', ['equals', 'contains', 'starts_with'], true), 422);
            if ($rule['field'] === 'has_attachments') {
                abort_unless($rule['operator'] === 'equals' && is_bool($rule['value'] ?? null), 422);
            } else {
                abort_unless(is_string($rule['value'] ?? null) && mb_strlen($rule['value']) > 0 && mb_strlen($rule['value']) <= 200 && ! preg_match('/[\x00-\x1f]/', $rule['value']), 422);
            }
        }
    }

    public function fieldsValid(string $type, array $data, array $form): bool
    {
        $fields = collect($form['fields'])->keyBy('id');
        foreach ($data['allowed_field_ids'] ?? [] as $id) {
            if (! $fields->has($id) || $fields[$id]['type'] === 'file') {
                return false;
            }
        }
        if ($type === 'email_trigger') {
            foreach ($fields as $field) {
                if ($field['required'] ?? false) {
                    return false;
                }
            }
            foreach ($data['input_mapping'] as $target => $source) {
                if (! $fields->has($target) || $fields[$target]['type'] === 'file' || (! str_starts_with($source, 'document.') && ! in_array($fields[$target]['type'], ['text', 'textarea'], true))) {
                    return false;
                }
            }
        }
        if ($type === 'document_validation') {
            $schema = app(DocumentTemplates::class)->schema($data['document_fields']);
            $known = array_column($schema, 'id');
            if (array_diff(array_keys($data['field_map']), $known) !== []) {
                return false;
            }
            foreach ($schema as $f) {
                if ($f['type'] === 'supporting_document') {
                    continue;
                }
                $target = $data['field_map'][$f['id']] ?? null;
                if (! $target || ! $fields->has($target) || $fields[$target]['type'] === 'file') {
                    return false;
                }
                $expected = match ($f['type']) {
                    'date' => 'date','number' => 'number','select' => 'select',default => 'text'
                };
                if (! in_array($fields[$target]['type'], $expected === 'text' ? ['text', 'textarea'] : [$expected], true)) {
                    return false;
                }
            }
        }

        return true;
    }

    public function templateValid(string $text, array $allowed): bool
    {
        preg_match_all('/\{\{([^}]+)\}\}/u', $text, $matches);
        foreach ($matches[1] as $key) {
            if (in_array($key, ['request.uuid', 'request.status', 'request.link', 'approval.decision', 'validation.missing'], true)) {
                continue;
            }
            if (! str_starts_with($key, 'input.') || ! in_array(substr($key, 6), $allowed, true)) {
                return false;
            }
        }
        $rest = preg_replace('/\{\{([^}]+)\}\}/u', '', $text);

        return ! str_contains($rest, '{{') && ! str_contains($rest, '}}') && ! preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f]/', $text);
    }

    public function matches(array $rules, string $mode, array $message, int $attachmentCount): bool
    {
        $this->rules($rules);
        $results = [];
        foreach ($rules as $rule) {
            if ($rule['field'] === 'has_attachments') {
                $results[] = ($attachmentCount > 0) === $rule['value'];

                continue;
            }
            $values = $rule['field'] === 'recipient' ? ($message['recipients'] ?? []) : [$message[$rule['field']] ?? ''];
            $found = false;
            foreach ($values as $value) {
                $value = mb_strtolower((string) $value);
                $needle = mb_strtolower($rule['value']);
                $found = $found || match ($rule['operator']) {
                    'equals' => $value === $needle,'contains' => str_contains($value, $needle),'starts_with' => str_starts_with($value, $needle)
                };
            }
            $results[] = $found;
        }

        return $rules === [] || ($mode === 'any' ? in_array(true, $results, true) : ! in_array(false, $results, true));
    }
}
