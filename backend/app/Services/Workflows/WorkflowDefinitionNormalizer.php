<?php

namespace App\Services\Workflows;

use App\Enums\OrganizationRole;
use App\Enums\WorkflowEndResult;
use App\Enums\WorkflowNodeType;

class WorkflowDefinitionNormalizer
{
    public const MAX_NODES = 80;

    public const MAX_EDGES = 160;

    public const MAX_FIELDS = 40;

    public const MAX_RULES = 20;

    public const MAX_OPTIONS = 50;

    public const MAX_BYTES = 200_000;

    /**
     * @var list<string>
     */
    private const NODE_TYPES = ['start', 'approval', 'condition', 'revision', 'end'];

    /**
     * @var list<string>
     */
    private const FIELD_TYPES = ['text', 'textarea', 'number', 'date', 'select', 'checkbox', 'file'];

    /**
     * @var list<string>
     */
    private const OPERATORS = [
        'equals',
        'not_equals',
        'greater_than',
        'greater_than_or_equal',
        'less_than',
        'less_than_or_equal',
        'is_blank',
        'is_not_blank',
        'is_true',
        'is_false',
    ];

    /**
     * @var array<string, list<string>>
     */
    private const SOURCE_HANDLES = [
        'start' => ['out'],
        'approval' => ['approved', 'rejected'],
        'condition' => ['true', 'false'],
        'revision' => ['return', 'exhausted'],
        'end' => [],
    ];

    public function __construct(private readonly WorkflowDefinitionHasher $hasher) {}

    /**
     * @param  mixed  $graph
     * @param  mixed  $form
     */
    public function normalize(mixed $graph, mixed $form): NormalizedWorkflowDefinition
    {
        $violations = [];

        $encoded = json_encode(['graph' => $graph, 'form' => $form]);

        if (! is_string($encoded) || strlen($encoded) > self::MAX_BYTES) {
            $violations[] = $this->violation('payload_too_large', 'The workflow definition is larger than 200 KB.');
        }

        $nodes = [];
        $edges = [];
        $fields = [];

        if (! is_array($graph) || ! array_is_list($graph['nodes'] ?? null) || ! array_is_list($graph['edges'] ?? null)) {
            $violations[] = $this->violation('invalid_shape', 'A workflow graph needs nodes and edges arrays.');
        } else {
            if (count($graph['nodes']) > self::MAX_NODES) {
                $violations[] = $this->violation('too_many_nodes', 'A workflow can contain at most 80 nodes.');
            }

            if (count($graph['edges']) > self::MAX_EDGES) {
                $violations[] = $this->violation('too_many_edges', 'A workflow can contain at most 160 connections.');
            }

            $seenNodes = [];

            foreach ($graph['nodes'] as $node) {
                $normalized = $this->node($node, $violations);

                if ($normalized === null) {
                    continue;
                }

                if (isset($seenNodes[$normalized['id']])) {
                    $violations[] = $this->violation('duplicate_node_id', 'Each node needs its own identifier.', $normalized['id']);
                    continue;
                }

                $seenNodes[$normalized['id']] = true;
                $nodes[] = $normalized;
            }

            $seenEdges = [];

            foreach ($graph['edges'] as $edge) {
                $normalized = $this->edge($edge, $violations);

                if ($normalized === null) {
                    continue;
                }

                if (isset($seenEdges[$normalized['id']])) {
                    $violations[] = $this->violation('duplicate_edge_id', 'Each connection needs its own identifier.', null, $normalized['id']);
                    continue;
                }

                $seenEdges[$normalized['id']] = true;
                $edges[] = $normalized;
            }
        }

        if (! is_array($form) || ! array_is_list($form['fields'] ?? null)) {
            $violations[] = $this->violation('invalid_shape', 'A form definition needs a fields array.');
        } else {
            if (count($form['fields']) > self::MAX_FIELDS) {
                $violations[] = $this->violation('too_many_fields', 'A form can contain at most 40 fields.');
            }

            $seenFields = [];

            foreach ($form['fields'] as $field) {
                $normalized = $this->field($field, $violations);

                if ($normalized === null) {
                    continue;
                }

                if (isset($seenFields[$normalized['id']])) {
                    $violations[] = $this->violation('duplicate_field_id', 'Each form field needs its own identifier.', null, null, $normalized['id']);
                    continue;
                }

                $seenFields[$normalized['id']] = true;
                $fields[] = $normalized;
            }
        }

        $normalizedGraph = ['nodes' => $nodes, 'edges' => $edges];
        $normalizedForm = ['fields' => $fields];

        return new NormalizedWorkflowDefinition(
            $normalizedGraph,
            $normalizedForm,
            $this->unique($violations),
            $this->hasher->hash($normalizedGraph, $normalizedForm),
        );
    }

    /**
     * @param  list<array{code: string, message: string, node_id: ?string, edge_id: ?string, field_id: ?string}>  $violations
     * @return array<string, mixed>|null
     */
    private function node(mixed $node, array &$violations): ?array
    {
        if (! is_array($node)) {
            $violations[] = $this->violation('invalid_shape', 'A node must be an object.');

            return null;
        }

        $id = $node['id'] ?? null;

        if (! is_string($id) || ! str($id)->isUuid()) {
            $violations[] = $this->violation('invalid_id', 'Each node id must be a UUID.');

            return null;
        }

        $type = $node['type'] ?? null;

        if (! is_string($type) || ! in_array($type, self::NODE_TYPES, true)) {
            $violations[] = $this->violation('invalid_node_type', 'Use a supported node type.', $id);

            return null;
        }

        $position = $node['position'] ?? null;

        if (! is_array($position) || ! is_numeric($position['x'] ?? null) || ! is_numeric($position['y'] ?? null)) {
            $violations[] = $this->violation('invalid_position', 'Each node needs a numeric position.', $id);

            return null;
        }

        $x = round((float) $position['x'], 2);
        $y = round((float) $position['y'], 2);

        if (abs($x) > 100_000 || abs($y) > 100_000) {
            $violations[] = $this->violation('invalid_position', 'A node position is outside the canvas bounds.', $id);

            return null;
        }

        $data = is_array($node['data'] ?? null) ? $node['data'] : [];
        $label = $this->text($data['label'] ?? '', 120, false, $violations, 'unsafe_content', 'A node label contains unsupported characters.', $id);
        $description = $this->text($data['description'] ?? '', 2000, true, $violations, 'unsafe_content', 'A node description contains unsupported characters.', $id);

        $normalized = [
            'label' => $label ?? '',
            'description' => $description ?? '',
        ];

        if ($type === WorkflowNodeType::Approval->value) {
            $roles = $data['assignee_roles'] ?? [];

            if (! is_array($roles) || ! array_is_list($roles)) {
                $violations[] = $this->violation('approval_assignee', 'Approval assignees must be a list of roles.', $id);
                $roles = [];
            }

            $clean = [];

            foreach ($roles as $role) {
                if (! is_string($role) || OrganizationRole::tryFrom($role) === null) {
                    $violations[] = $this->violation('approval_assignee', 'Approval assignees must be admin, manager, or employee.', $id);
                    continue;
                }

                $clean[$role] = $role;
            }

            $normalized['assignee_roles'] = array_values($clean);
        }

        if ($type === WorkflowNodeType::Condition->value) {
            $match = $data['match'] ?? 'all';
            $normalized['match'] = $match === 'any' ? 'any' : 'all';

            if (is_string($match) && ! in_array($match, ['all', 'any'], true)) {
                $violations[] = $this->violation('invalid_shape', 'A condition matches all or any of its rules.', $id);
            }

            $rules = $data['rules'] ?? [];
            $normalized['rules'] = [];

            if (! is_array($rules) || ! array_is_list($rules)) {
                $violations[] = $this->violation('invalid_shape', 'Condition rules must be a list.', $id);
            } else {
                if (count($rules) > self::MAX_RULES) {
                    $violations[] = $this->violation('too_many_rules', 'A condition can contain at most 20 rules.', $id);
                }

                foreach ($rules as $rule) {
                    $normalizedRule = $this->rule($rule, $violations, $id);

                    if ($normalizedRule !== null) {
                        $normalized['rules'][] = $normalizedRule;
                    }
                }
            }
        }

        if ($type === WorkflowNodeType::Revision->value) {
            $max = $data['max_returns'] ?? null;
            $normalized['max_returns'] = null;
            $normalized['return_to_node_id'] = null;

            if ($max !== null && $max !== '') {
                if (! is_int($max) && ! (is_string($max) && ctype_digit($max))) {
                    $violations[] = $this->violation('revision_bound', 'Revision attempts must be a whole number from 1 to 10.', $id);
                } else {
                    $max = (int) $max;

                    if ($max < 1 || $max > 10) {
                        $violations[] = $this->violation('revision_bound', 'Revision attempts must be from 1 to 10.', $id);
                    } else {
                        $normalized['max_returns'] = $max;
                    }
                }
            }

            $target = $data['return_to_node_id'] ?? null;

            if ($target !== null && $target !== '') {
                if (! is_string($target) || ! str($target)->isUuid()) {
                    $violations[] = $this->violation('revision_target', 'A revision return target must be a node id.', $id);
                } else {
                    $normalized['return_to_node_id'] = $target;
                }
            }
        }

        if ($type === WorkflowNodeType::End->value) {
            $result = $data['result'] ?? null;
            $normalized['result'] = null;

            if ($result !== null && $result !== '') {
                if (! is_string($result) || WorkflowEndResult::tryFrom($result) === null) {
                    $violations[] = $this->violation('end_result', 'An End node result must be completed or rejected.', $id);
                } else {
                    $normalized['result'] = $result;
                }
            }
        }

        return [
            'id' => $id,
            'type' => $type,
            'position' => ['x' => $x, 'y' => $y],
            'data' => $normalized,
        ];
    }

    /**
     * @param  list<array{code: string, message: string, node_id: ?string, edge_id: ?string, field_id: ?string}>  $violations
     * @return array<string, mixed>|null
     */
    private function edge(mixed $edge, array &$violations): ?array
    {
        if (! is_array($edge)) {
            $violations[] = $this->violation('invalid_shape', 'A connection must be an object.');

            return null;
        }

        $id = $edge['id'] ?? null;
        $source = $edge['source'] ?? null;
        $target = $edge['target'] ?? null;
        $sourceHandle = $edge['source_handle'] ?? null;
        $targetHandle = $edge['target_handle'] ?? 'in';

        if (! is_string($id) || ! str($id)->isUuid()) {
            $violations[] = $this->violation('invalid_id', 'Each connection id must be a UUID.');

            return null;
        }

        if (! is_string($source) || ! str($source)->isUuid() || ! is_string($target) || ! str($target)->isUuid()) {
            $violations[] = $this->violation('invalid_edge_endpoint', 'A connection needs a source and a target.', null, $id);

            return null;
        }

        $allowed = array_merge(...array_values(self::SOURCE_HANDLES));

        if (! is_string($sourceHandle) || ! in_array($sourceHandle, $allowed, true)) {
            $violations[] = $this->violation('invalid_handle', 'A connection uses an unsupported outcome.', null, $id);

            return null;
        }

        if ($targetHandle !== 'in') {
            $violations[] = $this->violation('invalid_handle', 'Connections must enter a node through its incoming handle.', null, $id);

            return null;
        }

        return [
            'id' => $id,
            'source' => $source,
            'target' => $target,
            'source_handle' => $sourceHandle,
            'target_handle' => 'in',
        ];
    }

    /**
     * @param  list<array{code: string, message: string, node_id: ?string, edge_id: ?string, field_id: ?string}>  $violations
     * @return array<string, mixed>|null
     */
    private function rule(mixed $rule, array &$violations, string $nodeId): ?array
    {
        if (! is_array($rule)) {
            $violations[] = $this->violation('invalid_shape', 'A condition rule must be an object.', $nodeId);

            return null;
        }

        $id = $rule['id'] ?? null;
        $fieldId = $rule['field_id'] ?? null;
        $operator = $rule['operator'] ?? null;

        if (! is_string($id) || ! str($id)->isUuid()) {
            $violations[] = $this->violation('invalid_id', 'Each condition rule needs a UUID.', $nodeId);

            return null;
        }

        if (! is_string($fieldId) || preg_match('/^[a-z][a-z0-9_]{0,63}$/', $fieldId) !== 1) {
            $violations[] = $this->violation('unknown_field', 'A condition rule must name a form field.', $nodeId, null, is_string($fieldId) ? $fieldId : null);

            return null;
        }

        if (! is_string($operator) || ! in_array($operator, self::OPERATORS, true)) {
            $violations[] = $this->violation('operator_mismatch', 'A condition uses an unsupported operator.', $nodeId, null, $fieldId);

            return null;
        }

        $value = $rule['value'] ?? null;

        if (is_array($value)) {
            $violations[] = $this->violation('unsafe_content', 'A condition value must be plain text, a number, or empty.', $nodeId, null, $fieldId);

            return null;
        }

        if (is_string($value)) {
            $value = $this->text($value, 500, false, $violations, 'unsafe_content', 'A condition value contains unsupported characters.', $nodeId, null, $fieldId);
        }

        return [
            'id' => $id,
            'field_id' => $fieldId,
            'operator' => $operator,
            'value' => $value,
        ];
    }

    /**
     * @param  list<array{code: string, message: string, node_id: ?string, edge_id: ?string, field_id: ?string}>  $violations
     * @return array<string, mixed>|null
     */
    private function field(mixed $field, array &$violations): ?array
    {
        if (! is_array($field)) {
            $violations[] = $this->violation('invalid_shape', 'A form field must be an object.');

            return null;
        }

        $id = $field['id'] ?? null;
        $type = $field['type'] ?? null;

        if (! is_string($id) || preg_match('/^[a-z][a-z0-9_]{0,63}$/', $id) !== 1) {
            $violations[] = $this->violation('invalid_field', 'A field id must start with a letter and use lowercase letters, numbers, or underscores.', null, null, is_string($id) ? $id : null);

            return null;
        }

        if (! is_string($type) || ! in_array($type, self::FIELD_TYPES, true)) {
            $violations[] = $this->violation('invalid_field', 'Use a supported field type.', null, null, $id);

            return null;
        }

        $label = $this->text($field['label'] ?? '', 120, false, $violations, 'unsafe_content', 'A field label contains unsupported characters.', null, null, $id);
        $description = $this->text($field['description'] ?? '', 2000, true, $violations, 'unsafe_content', 'A field description contains unsupported characters.', null, null, $id);
        $required = (bool) ($field['required'] ?? false);
        $validation = is_array($field['validation'] ?? null) ? $field['validation'] : [];
        $options = [];

        if ($type === 'select') {
            $rawOptions = $field['options'] ?? [];

            if (! is_array($rawOptions) || ! array_is_list($rawOptions)) {
                $violations[] = $this->violation('invalid_field', 'Select options must be a list.', null, null, $id);
            } elseif (count($rawOptions) > self::MAX_OPTIONS) {
                $violations[] = $this->violation('too_many_options', 'A select field can contain at most 50 options.', null, null, $id);
            } else {
                $seen = [];

                foreach ($rawOptions as $option) {
                    if (! is_array($option)) {
                        $violations[] = $this->violation('invalid_field', 'A select option must be an object.', null, null, $id);
                        continue;
                    }

                    $value = $this->text($option['value'] ?? '', 64, false, $violations, 'unsafe_content', 'An option value contains unsupported characters.', null, null, $id);
                    $optionLabel = $this->text($option['label'] ?? '', 120, false, $violations, 'unsafe_content', 'An option label contains unsupported characters.', null, null, $id);

                    if ($value === null || $value === '' || $optionLabel === null) {
                        $violations[] = $this->violation('invalid_field', 'Each select option needs a value and a label.', null, null, $id);
                        continue;
                    }

                    if (isset($seen[$value])) {
                        $violations[] = $this->violation('duplicate_option', 'Select option values must be unique.', null, null, $id);
                        continue;
                    }

                    $seen[$value] = true;
                    $options[] = ['value' => $value, 'label' => $optionLabel];
                }
            }
        }

        $normalizedValidation = $this->constraints($type, $validation, $violations, $id);
        $default = $this->defaultValue($type, $field['default_value'] ?? null, $normalizedValidation, $options, $violations, $id);

        return [
            'id' => $id,
            'type' => $type,
            'label' => $label ?? '',
            'description' => $description ?? '',
            'required' => $required,
            'default_value' => $default,
            'validation' => $normalizedValidation,
            'options' => $type === 'select' ? $options : [],
        ];
    }

    /**
     * @param  array<string, mixed>  $validation
     * @param  list<array{code: string, message: string, node_id: ?string, edge_id: ?string, field_id: ?string}>  $violations
     * @return array<string, mixed>
     */
    private function constraints(string $type, array $validation, array &$violations, string $fieldId): array
    {
        if (in_array($type, ['text', 'textarea'], true)) {
            $min = $this->whole($validation['min_length'] ?? null, 0, $type === 'textarea' ? 5000 : 500, $violations, $fieldId, 'Minimum length');
            $max = $this->whole($validation['max_length'] ?? null, 1, $type === 'textarea' ? 5000 : 500, $violations, $fieldId, 'Maximum length');

            if ($min !== null && $max !== null && $min > $max) {
                $violations[] = $this->violation('invalid_field', 'Minimum length cannot exceed maximum length.', null, null, $fieldId);
            }

            return array_filter([
                'min_length' => $min,
                'max_length' => $max,
            ], fn ($value) => $value !== null);
        }

        if ($type === 'number') {
            $min = $this->number($validation['min'] ?? null, $violations, $fieldId, 'Minimum');
            $max = $this->number($validation['max'] ?? null, $violations, $fieldId, 'Maximum');

            if ($min !== null && $max !== null && $min > $max) {
                $violations[] = $this->violation('invalid_field', 'Minimum cannot exceed maximum.', null, null, $fieldId);
            }

            return array_filter([
                'min' => $min,
                'max' => $max,
            ], fn ($value) => $value !== null);
        }

        if ($type === 'date') {
            $min = $this->date($validation['min'] ?? null, $violations, $fieldId, 'Minimum date');
            $max = $this->date($validation['max'] ?? null, $violations, $fieldId, 'Maximum date');

            if ($min !== null && $max !== null && $min > $max) {
                $violations[] = $this->violation('invalid_field', 'Minimum date cannot be after the maximum date.', null, null, $fieldId);
            }

            return array_filter([
                'min' => $min,
                'max' => $max,
            ], fn ($value) => $value !== null);
        }

        if ($type === 'file') {
            $extensions = [];
            $raw = $validation['extensions'] ?? [];

            if ($raw !== null && $raw !== []) {
                if (! is_array($raw) || ! array_is_list($raw)) {
                    $violations[] = $this->violation('invalid_field', 'File extensions must be a list.', null, null, $fieldId);
                } else {
                    foreach ($raw as $extension) {
                        if (! is_string($extension) || preg_match('/^[a-z0-9]{1,8}$/', $extension) !== 1) {
                            $violations[] = $this->violation('invalid_field', 'File extensions must be short lowercase letters or numbers.', null, null, $fieldId);
                            continue;
                        }

                        $extensions[$extension] = $extension;
                    }
                }
            }

            $max = $this->whole($validation['max_size_mb'] ?? null, 1, 25, $violations, $fieldId, 'Maximum file size');

            return array_filter([
                'extensions' => array_values($extensions),
                'max_size_mb' => $max,
            ], fn ($value) => $value !== null && $value !== []);
        }

        return [];
    }

    /**
     * @param  array<string, mixed>  $validation
     * @param  list<array{value: string, label: string}>  $options
     * @param  list<array{code: string, message: string, node_id: ?string, edge_id: ?string, field_id: ?string}>  $violations
     */
    private function defaultValue(string $type, mixed $value, array $validation, array $options, array &$violations, string $fieldId): mixed
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (in_array($type, ['text', 'textarea'], true)) {
            $text = $this->text($value, $type === 'textarea' ? 5000 : 500, $type === 'textarea', $violations, 'unsafe_content', 'A default value contains unsupported characters.', null, null, $fieldId);

            return $text === '' ? null : $text;
        }

        if ($type === 'number') {
            $number = $this->number($value, $violations, $fieldId, 'Default value');

            if ($number === null) {
                return null;
            }

            if (isset($validation['min']) && $number < $validation['min']) {
                $violations[] = $this->violation('invalid_field', 'The default value is below the minimum.', null, null, $fieldId);
            }

            if (isset($validation['max']) && $number > $validation['max']) {
                $violations[] = $this->violation('invalid_field', 'The default value is above the maximum.', null, null, $fieldId);
            }

            return $number;
        }

        if ($type === 'date') {
            return $this->date($value, $violations, $fieldId, 'Default date');
        }

        if ($type === 'checkbox') {
            if (! is_bool($value)) {
                $violations[] = $this->violation('invalid_field', 'A checkbox default must be true or false.', null, null, $fieldId);

                return null;
            }

            return $value;
        }

        if ($type === 'select') {
            $text = $this->text($value, 64, false, $violations, 'unsafe_content', 'A default option contains unsupported characters.', null, null, $fieldId);

            if ($text === null || $text === '') {
                return null;
            }

            if (! in_array($text, array_column($options, 'value'), true)) {
                $violations[] = $this->violation('invalid_field', 'The default value must be one of the select options.', null, null, $fieldId);
            }

            return $text;
        }

        $violations[] = $this->violation('invalid_field', 'File fields cannot have a default value.', null, null, $fieldId);

        return null;
    }

    /**
     * @param  list<array{code: string, message: string, node_id: ?string, edge_id: ?string, field_id: ?string}>  $violations
     */
    private function text(mixed $value, int $max, bool $multiline, array &$violations, string $code, string $message, ?string $nodeId = null, ?string $edgeId = null, ?string $fieldId = null): ?string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            if ($value === null) {
                return '';
            }

            $violations[] = $this->violation($code, $message, $nodeId, $edgeId, $fieldId);

            return null;
        }

        $text = trim(str_replace("\r\n", "\n", (string) $value));
        $pattern = $multiline ? '/[<>\x00-\x08\x0B\x0C\x0E-\x1F]/' : '/[<>\x00-\x1F]/';

        if (preg_match($pattern, $text) === 1) {
            $violations[] = $this->violation($code, $message, $nodeId, $edgeId, $fieldId);

            return null;
        }

        if (mb_strlen($text) > $max) {
            $violations[] = $this->violation('invalid_field', 'A text value is too long.', $nodeId, $edgeId, $fieldId);

            return null;
        }

        return $text;
    }

    /**
     * @param  list<array{code: string, message: string, node_id: ?string, edge_id: ?string, field_id: ?string}>  $violations
     */
    private function whole(mixed $value, int $min, int $max, array &$violations, string $fieldId, string $label): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_int($value) && ! (is_string($value) && ctype_digit($value))) {
            $violations[] = $this->violation('invalid_field', $label.' must be a whole number.', null, null, $fieldId);

            return null;
        }

        $number = (int) $value;

        if ($number < $min || $number > $max) {
            $violations[] = $this->violation('invalid_field', $label.' is outside the allowed range.', null, null, $fieldId);

            return null;
        }

        return $number;
    }

    /**
     * @param  list<array{code: string, message: string, node_id: ?string, edge_id: ?string, field_id: ?string}>  $violations
     */
    private function number(mixed $value, array &$violations, string $fieldId, string $label): int|float|null
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_string($value) && is_numeric($value)) {
            $value = str_contains($value, '.') ? (float) $value : (int) $value;
        }

        if (! is_int($value) && ! is_float($value)) {
            $violations[] = $this->violation('invalid_field', $label.' must be a number.', null, null, $fieldId);

            return null;
        }

        if (! is_finite($value) || abs($value) > 1_000_000_000) {
            $violations[] = $this->violation('invalid_field', $label.' is outside the allowed range.', null, null, $fieldId);

            return null;
        }

        return is_float($value) && floor($value) === $value ? (int) $value : $value;
    }

    /**
     * @param  list<array{code: string, message: string, node_id: ?string, edge_id: ?string, field_id: ?string}>  $violations
     */
    private function date(mixed $value, array &$violations, string $fieldId, string $label): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            $violations[] = $this->violation('invalid_field', $label.' must use YYYY-MM-DD.', null, null, $fieldId);

            return null;
        }

        $parsed = date_create_immutable($value);

        if ($parsed === false || $parsed->format('Y-m-d') !== $value) {
            $violations[] = $this->violation('invalid_field', $label.' is not a real date.', null, null, $fieldId);

            return null;
        }

        return $value;
    }

    /**
     * @param  list<array{code: string, message: string, node_id: ?string, edge_id: ?string, field_id: ?string}>  $violations
     * @return list<array{code: string, message: string, node_id: ?string, edge_id: ?string, field_id: ?string}>
     */
    private function unique(array $violations): array
    {
        $seen = [];
        $unique = [];

        foreach ($violations as $violation) {
            $key = json_encode($violation);

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $unique[] = $violation;

            if (count($unique) >= 50) {
                break;
            }
        }

        return $unique;
    }

    /**
     * @return array{code: string, message: string, node_id: ?string, edge_id: ?string, field_id: ?string}
     */
    private function violation(string $code, string $message, ?string $nodeId = null, ?string $edgeId = null, ?string $fieldId = null): array
    {
        return [
            'code' => $code,
            'message' => $message,
            'node_id' => $nodeId,
            'edge_id' => $edgeId,
            'field_id' => $fieldId,
        ];
    }
}
