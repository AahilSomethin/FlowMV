<?php

namespace App\Services\Integrations;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class DocumentTemplates
{
    public function schema(array $fields): array
    {
        abort_unless(count($fields) > 0 && count($fields) <= 20 && array_is_list($fields), 422);
        $ids = [];
        $clean = [];
        foreach ($fields as $f) {
            abort_unless(is_array($f) && is_string($f['id'] ?? null) && preg_match('/^[a-z][a-z0-9_]{0,39}$/D', $f['id']) && ! isset($ids[$f['id']]) && is_string($f['label'] ?? null) && preg_match('/^[\pL\pN _.-]{1,80}$/uD', $f['label']) && in_array($f['type'] ?? '', ['text', 'identifier', 'email', 'date', 'number', 'select', 'supporting_document'], true) && is_bool($f['required'] ?? false), 422);
            $ids[$f['id']] = true;
            $field = ['id' => $f['id'], 'label' => $f['label'], 'type' => $f['type'], 'required' => $f['required'] ?? false];
            if ($f['type'] === 'select') {
                abort_unless(is_array($f['options'] ?? null) && count($f['options']) > 0 && count($f['options']) <= 20, 422);
                foreach ($f['options'] as $option) {
                    abort_unless(is_string($option) && mb_strlen($option) <= 100 && ! preg_match('/[\x00-\x1f]/', $option), 422);
                }
                $field['options'] = array_values(array_unique($f['options']));
            }
            if (isset($f['after_or_equal_to'])) {
                abort_unless($f['type'] === 'date' && is_string($f['after_or_equal_to']), 422);
                $field['after_or_equal_to'] = $f['after_or_equal_to'];
            }
            if (isset($f['required_when'])) {
                $condition = $f['required_when'];
                abort_unless(is_array($condition) && is_string($condition['field_id'] ?? null) && (is_string($condition['equals'] ?? null) || is_bool($condition['equals'] ?? null)), 422);
                $field['required_when'] = ['field_id' => $condition['field_id'], 'equals' => $condition['equals']];
            }
            $clean[] = $field;
        }
        $types = array_column($clean, 'type', 'id');
        foreach ($clean as $field) {
            if (isset($field['after_or_equal_to'])) {
                abort_unless(($types[$field['after_or_equal_to']] ?? '') === 'date' && $field['after_or_equal_to'] !== $field['id'], 422);
            }
            if (isset($field['required_when'])) {
                abort_unless(isset($types[$field['required_when']['field_id']]) && $field['required_when']['field_id'] !== $field['id'], 422);
            }
        }

        return $clean;
    }

    public function create(int $org, int $user, string $name, array $fields): string
    {
        return DB::transaction(function () use ($org, $user, $name, $fields) {
            abort_unless(app(ConnectionAccess::class)->role($user, $org) === 'admin' && preg_match('/^[\pL\pN ._-]{1,100}$/uD', $name), 403);
            $id = (string) Str::uuid();
            DB::table('document_templates')->insert(['id' => $id, 'organization_id' => $org, 'created_by' => $user, 'name' => $name, 'fields' => json_encode($this->schema($fields)), 'created_at' => now()]);
            app(ConnectionManager::class)->audit($org, null, $user, 'document.template.created');

            return $id;
        });
    }

    public function extract(array $fields, #[\SensitiveParameter] string $text): array
    {
        $fields = $this->schema($fields);
        $values = [];
        $uncertain = [];
        abort_if(strlen($text) > 100000, 422);
        foreach ($fields as $f) {
            if ($f['type'] === 'supporting_document') {
                continue;
            }
            preg_match_all('/^[\t ]*'.preg_quote($f['label'], '/').'[\t ]*:[\t ]*([^\r\n]*)$/mu', $text, $matches);
            $candidates = array_values(array_unique(array_map('trim', $matches[1])));
            if (count($candidates) > 1) {
                $uncertain[] = $f['id'];
            } elseif (count($candidates) === 1) {
                $values[$f['id']] = $candidates[0];
            }
        }

        return ['values' => $values, 'uncertain' => $uncertain];
    }

    public function validate(array $fields, #[\SensitiveParameter] array $values, array $cleanDocuments = [], array $uncertain = []): array
    {
        $fields = $this->schema($fields);
        $missing = [];
        $invalid = [];
        $clean = [];
        foreach ($fields as $f) {
            $id = $f['id'];
            $v = $values[$id] ?? null;
            $required = $f['required'] || (isset($f['required_when']) && ($values[$f['required_when']['field_id']] ?? null) === $f['required_when']['equals']);
            if ($f['type'] === 'supporting_document') {
                if ($required && count($cleanDocuments) < 2) {
                    $missing[] = $id;
                }

                continue;
            }
            if ($v === null || $v === '') {
                if ($required) {
                    $missing[] = $id;
                }

                continue;
            }
            $ok = is_scalar($v) && ! is_bool($v) && mb_strlen((string) $v) <= 1000 && ! preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f]/', (string) $v);
            if ($ok) {
                $s = trim((string) $v);
                $ok = match ($f['type']) {
                    'identifier' => (bool) preg_match('/^[A-Za-z0-9_.-]{1,64}$/D', $s),
                    'email' => (bool) filter_var($s, FILTER_VALIDATE_EMAIL),
                    'date' => $this->date($s),
                    'number' => is_numeric($s) && is_finite((float) $s),
                    'select' => in_array($s, $f['options'], true),
                    default => true,
                };
                if ($ok) {
                    $clean[$id] = $f['type'] === 'number' ? (float) $s : $s;
                }
            }
            if (! $ok) {
                $invalid[] = $id;
            }
        }
        foreach ($fields as $f) {
            if (isset($f['after_or_equal_to'],$clean[$f['id']],$clean[$f['after_or_equal_to']]) && $clean[$f['id']] < $clean[$f['after_or_equal_to']]) {
                $invalid[] = $f['id'];
            }
        }

        return ['status' => $uncertain !== [] ? 'unable_to_determine' : ($invalid !== [] ? 'invalid_information' : ($missing !== [] ? 'missing_information' : 'complete')), 'missing' => $missing, 'invalid' => array_values(array_unique($invalid)), 'uncertain' => array_values(array_intersect($uncertain, array_column($fields, 'id'))), 'values' => $clean];
    }

    private function date(string $value): bool
    {
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value)) {
            return false;
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $date && $date->format('Y-m-d') === $value;
    }
}
