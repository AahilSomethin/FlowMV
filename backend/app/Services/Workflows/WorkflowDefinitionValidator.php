<?php

namespace App\Services\Workflows;

use App\Services\Workflows\Nodes\NodeRegistry;

/**
 * Publication rules for a workflow definition.
 *
 * Drafts and new publishes validate against the current registry (V2).
 * Historical V1 snapshots are never revalidated under newer rules.
 *
 * Revision nodes describe a submitter-correction loop executed by Phase 4+.
 */
class WorkflowDefinitionValidator
{
    /**
     * @var array<string, list<string>>
     */
    private const OPERATORS = [
        'text' => ['equals', 'not_equals', 'is_blank', 'is_not_blank'],
        'textarea' => ['equals', 'not_equals', 'is_blank', 'is_not_blank'],
        'number' => ['equals', 'not_equals', 'greater_than', 'greater_than_or_equal', 'less_than', 'less_than_or_equal'],
        'date' => ['equals', 'not_equals', 'greater_than', 'greater_than_or_equal', 'less_than', 'less_than_or_equal'],
        'select' => ['equals', 'not_equals'],
        'checkbox' => ['is_true', 'is_false'],
        'file' => ['is_blank', 'is_not_blank'],
    ];

    /**
     * @var list<string>
     */
    private const VALUELESS = ['is_blank', 'is_not_blank', 'is_true', 'is_false'];

    public function __construct(
        private readonly WorkflowDefinitionHasher $hasher,
        private readonly NodeRegistry $registry,
    ) {}

    /**
     * @param  array{nodes: list<array<string, mixed>>, edges: list<array<string, mixed>>}  $graph
     * @param  array{fields: list<array<string, mixed>>}  $form
     * @return list<array{code: string, message: string, node_id: ?string, edge_id: ?string, field_id: ?string}>
     */
    public function validate(array $graph, array $form, ?string $publishedHash = null): array
    {
        $violations = [];
        $nodes = $graph['nodes'];
        $edges = $graph['edges'];
        $byId = [];

        foreach ($nodes as $node) {
            $byId[$node['id']] = $node;
            $this->nodeConfig($node, $form, $violations);
        }

        $this->formConfig($form, $violations);

        $starts = array_values(array_filter($nodes, fn (array $node) => $node['type'] === 'start'));
        $ends = array_values(array_filter($nodes, fn (array $node) => $node['type'] === 'end'));

        if (count($starts) !== 1) {
            $violations[] = $this->violation('start_count', 'A workflow needs exactly one Start node.');
        }

        if (count($ends) < 1) {
            $violations[] = $this->violation('end_count', 'A workflow needs at least one End node.');
        }

        $forward = [];
        $handleOwners = [];

        foreach ($edges as $edge) {
            $source = $byId[$edge['source']] ?? null;
            $target = $byId[$edge['target']] ?? null;

            if ($source === null || $target === null) {
                $violations[] = $this->violation('invalid_edge_endpoint', 'A connection points at a missing node.', null, $edge['id']);

                continue;
            }

            $allowed = $this->registry->allows(NodeRegistry::AUTHORING, $source['type'])
                ? $this->registry->sourceHandles($source['type'])
                : [];

            if (! in_array($edge['source_handle'], $allowed, true)) {
                $violations[] = $this->violation('invalid_handle', 'This connection is not an outcome of its source node.', $source['id'], $edge['id']);

                continue;
            }

            if ($target['type'] === 'start' || $edge['target_handle'] !== 'in') {
                $violations[] = $this->violation('invalid_edge_endpoint', 'Only Approval, Condition, Revision, and End nodes accept incoming connections.', $target['id'], $edge['id']);

                continue;
            }

            $handleKey = $source['id'].':'.$edge['source_handle'];

            if (isset($handleOwners[$handleKey])) {
                $violations[] = $this->violation('invalid_handle', 'Each outcome can have one connection.', $source['id'], $edge['id']);

                continue;
            }

            $handleOwners[$handleKey] = $edge;

            if ($edge['source_handle'] !== 'return') {
                $forward[$source['id']][] = ['target' => $target['id'], 'edge' => $edge];
            }
        }

        foreach ($nodes as $node) {
            if ($node['type'] === 'end') {
                continue;
            }

            foreach ($this->registry->sourceHandles($node['type']) as $handle) {
                if (! isset($handleOwners[$node['id'].':'.$handle])) {
                    $violations[] = $this->violation(
                        $node['type'] === 'approval' ? 'approval_branch' : ($node['type'] === 'condition' ? 'condition_branch' : 'dangling_path'),
                        $this->missingHandleMessage($node['type'], $handle),
                        $node['id'],
                    );
                }
            }
        }

        $this->cycles($nodes, $forward, $violations);

        $startId = $starts[0]['id'] ?? null;

        if ($startId !== null) {
            $reachable = $this->reachable($startId, $forward);

            foreach ($nodes as $node) {
                if (! isset($reachable[$node['id']])) {
                    $violations[] = $this->violation('unreachable_node', 'This node cannot be reached from Start.', $node['id']);
                }
            }
        }

        foreach ($nodes as $node) {
            if ($node['type'] !== 'revision') {
                continue;
            }

            $this->revision($node, $byId, $edges, $forward, $violations);
        }

        if ($publishedHash !== null && $this->hasher->hash($graph, $form) === $publishedHash) {
            $violations[] = $this->violation('unchanged_draft', 'Publish the draft after it changes. This draft matches the published version.');
        }

        return $this->unique($violations);
    }

    /**
     * @param  array<string, mixed>  $node
     * @param  array{fields: list<array<string, mixed>>}  $form
     * @param  list<array{code: string, message: string, node_id: ?string, edge_id: ?string, field_id: ?string}>  $violations
     */
    private function nodeConfig(array $node, array $form, array &$violations): void
    {
        $data = $node['data'];

        if (($data['label'] ?? '') === '') {
            $violations[] = $this->violation('incomplete_config', 'Give this node a label.', $node['id']);
        }

        if ($node['type'] === 'approval' && ($data['assignee_roles'] ?? []) === []) {
            $violations[] = $this->violation('approval_assignee', 'Choose at least one role that can approve.', $node['id']);
        }

        if ($node['type'] === 'end' && ! in_array($data['result'] ?? null, ['completed', 'rejected'], true)) {
            $violations[] = $this->violation('end_result', 'Choose whether this End node completes or rejects the workflow.', $node['id']);
        }

        if ($node['type'] === 'condition') {
            $rules = $data['rules'] ?? [];

            if ($rules === []) {
                $violations[] = $this->violation('incomplete_config', 'Add at least one condition rule.', $node['id']);
            }

            $fields = [];

            foreach ($form['fields'] as $field) {
                $fields[$field['id']] = $field;
            }

            foreach ($rules as $rule) {
                $field = $fields[$rule['field_id']] ?? null;

                if ($field === null) {
                    $violations[] = $this->violation('unknown_field', 'This rule references a form field that does not exist.', $node['id'], null, $rule['field_id']);

                    continue;
                }

                $allowed = self::OPERATORS[$field['type']] ?? [];

                if (! in_array($rule['operator'], $allowed, true)) {
                    $violations[] = $this->violation('operator_mismatch', 'This operator does not apply to the selected field.', $node['id'], null, $field['id']);

                    continue;
                }

                $this->ruleValue($rule, $field, $node['id'], $violations);
            }
        }

        if ($node['type'] === 'revision' && ($data['max_returns'] ?? null) === null) {
            $violations[] = $this->violation('revision_bound', 'Set the maximum number of revision attempts, from 1 to 10.', $node['id']);
        }

        if ($node['type'] === 'notification') {
            if (($data['channels'] ?? []) === []) {
                $violations[] = $this->violation('notification_channels', 'Choose at least one notification channel.', $node['id']);
            }

            $hasRecipients = ($data['recipient_roles'] ?? []) !== []
                || ($data['include_submitter'] ?? false) === true
                || ($data['include_task_participants'] ?? false) === true;

            if (! $hasRecipients) {
                $violations[] = $this->violation('notification_recipients', 'Choose at least one recipient role or participant.', $node['id']);
            }

            $fieldIds = $data['allowed_field_ids'] ?? [];
            $known = [];

            foreach ($form['fields'] as $field) {
                $known[$field['id']] = $field;
            }

            foreach ($fieldIds as $fieldId) {
                if (! isset($known[$fieldId])) {
                    $violations[] = $this->violation('unknown_field', 'This notification references a form field that does not exist.', $node['id'], null, $fieldId);

                    continue;
                }

                if (($known[$fieldId]['type'] ?? null) === 'file') {
                    $violations[] = $this->violation('notification_template', 'File fields cannot be included in notification templates.', $node['id'], null, $fieldId);
                }
            }

            if (($data['subject'] ?? '') === '') {
                $violations[] = $this->violation('incomplete_config', 'Give this notification a subject.', $node['id']);
            }
        }

        if ($node['type'] === 'assignment') {
            $mode = $data['assignee_mode'] ?? 'role';

            if ($mode === 'role' && ($data['assignee_roles'] ?? []) === []) {
                $violations[] = $this->violation('assignment_assignee', 'Choose at least one role that can complete this task.', $node['id']);
            }

            if ($mode === 'member' && ($data['assignee_user_id'] ?? null) === null) {
                $violations[] = $this->violation('assignment_assignee', 'Choose an organization member for this assignment.', $node['id']);
            }
        }

        if ($node['type'] === 'delay') {
            $mode = $data['resume_mode'] ?? 'duration';

            if ($mode === 'duration' && ($data['duration_seconds'] ?? null) === null) {
                $violations[] = $this->violation('delay_config', 'Set a delay duration between 1 second and 30 days.', $node['id']);
            }

            if ($mode === 'until' && ($data['resume_at'] ?? null) === null) {
                $violations[] = $this->violation('delay_config', 'Set a resume time for this delay.', $node['id']);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $rule
     * @param  array<string, mixed>  $field
     * @param  list<array{code: string, message: string, node_id: ?string, edge_id: ?string, field_id: ?string}>  $violations
     */
    private function ruleValue(array $rule, array $field, string $nodeId, array &$violations): void
    {
        $operator = $rule['operator'];
        $value = $rule['value'];

        if (in_array($operator, self::VALUELESS, true)) {
            if ($value !== null && $value !== '') {
                $violations[] = $this->violation('operator_mismatch', 'This operator does not take a comparison value.', $nodeId, null, $field['id']);
            }

            return;
        }

        if ($value === null || $value === '') {
            $violations[] = $this->violation('incomplete_config', 'Enter a comparison value for this rule.', $nodeId, null, $field['id']);

            return;
        }

        if (in_array($field['type'], ['text', 'textarea'], true) && ! is_string($value)) {
            $violations[] = $this->violation('operator_mismatch', 'Compare this field with text.', $nodeId, null, $field['id']);
        }

        if ($field['type'] === 'number' && ! is_int($value) && ! is_float($value)) {
            $violations[] = $this->violation('operator_mismatch', 'Compare this field with a number.', $nodeId, null, $field['id']);
        }

        if ($field['type'] === 'date' && (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1)) {
            $violations[] = $this->violation('operator_mismatch', 'Compare this field with a YYYY-MM-DD date.', $nodeId, null, $field['id']);
        }

        if ($field['type'] === 'select' && ! in_array($value, array_column($field['options'], 'value'), true)) {
            $violations[] = $this->violation('operator_mismatch', 'Compare this field with one of its options.', $nodeId, null, $field['id']);
        }
    }

    /**
     * @param  array{fields: list<array<string, mixed>>}  $form
     * @param  list<array{code: string, message: string, node_id: ?string, edge_id: ?string, field_id: ?string}>  $violations
     */
    private function formConfig(array $form, array &$violations): void
    {
        foreach ($form['fields'] as $field) {
            if (($field['label'] ?? '') === '') {
                $violations[] = $this->violation('incomplete_config', 'Give this field a label.', null, null, $field['id']);
            }

            if ($field['type'] === 'select' && ($field['options'] ?? []) === []) {
                $violations[] = $this->violation('incomplete_config', 'Add at least one option to this select field.', null, null, $field['id']);
            }
        }
    }

    /**
     * @param  list<array<string, mixed>>  $nodes
     * @param  array<string, list<array{target: string, edge: array<string, mixed>}>>  $forward
     * @param  list<array{code: string, message: string, node_id: ?string, edge_id: ?string, field_id: ?string}>  $violations
     */
    private function cycles(array $nodes, array $forward, array &$violations): void
    {
        $color = [];

        foreach ($nodes as $node) {
            $this->visit($node['id'], $forward, $color, $violations);
        }
    }

    /**
     * @param  array<string, list<array{target: string, edge: array<string, mixed>}>>  $forward
     * @param  array<string, int>  $color
     * @param  list<array{code: string, message: string, node_id: ?string, edge_id: ?string, field_id: ?string}>  $violations
     */
    private function visit(string $id, array $forward, array &$color, array &$violations): void
    {
        $state = $color[$id] ?? 0;

        if ($state === 1) {
            $violations[] = $this->violation('cycle', 'Only a revision return may loop, and it must point back at an earlier Approval.', $id);

            return;
        }

        if ($state === 2) {
            return;
        }

        $color[$id] = 1;

        foreach ($forward[$id] ?? [] as $next) {
            $this->visit($next['target'], $forward, $color, $violations);
        }

        $color[$id] = 2;
    }

    /**
     * @param  array<string, list<array{target: string, edge: array<string, mixed>}>>  $forward
     * @return array<string, bool>
     */
    private function reachable(string $startId, array $forward): array
    {
        $seen = [];
        $stack = [$startId];

        while ($stack !== []) {
            $id = array_pop($stack);

            if (isset($seen[$id])) {
                continue;
            }

            $seen[$id] = true;

            foreach ($forward[$id] ?? [] as $next) {
                $stack[] = $next['target'];
            }
        }

        return $seen;
    }

    /**
     * @param  array<string, mixed>  $node
     * @param  array<string, array<string, mixed>>  $byId
     * @param  list<array<string, mixed>>  $edges
     * @param  array<string, list<array{target: string, edge: array<string, mixed>}>>  $forward
     * @param  list<array{code: string, message: string, node_id: ?string, edge_id: ?string, field_id: ?string}>  $violations
     */
    private function revision(array $node, array $byId, array $edges, array $forward, array &$violations): void
    {
        $incoming = array_values(array_filter($edges, fn (array $edge) => $edge['target'] === $node['id'] && $edge['source_handle'] !== 'return'));

        if ($incoming === []) {
            $violations[] = $this->violation('revision_structure', 'A rejection from an Approval node must enter this Revision.', $node['id']);
        }

        foreach ($incoming as $edge) {
            $source = $byId[$edge['source']] ?? null;

            if ($source === null || $source['type'] !== 'approval' || $edge['source_handle'] !== 'rejected') {
                $violations[] = $this->violation('revision_structure', 'Only an Approval rejection can enter a Revision node.', $node['id'], $edge['id']);
            }
        }

        $returns = array_values(array_filter(
            $edges,
            fn (array $edge) => $edge['source'] === $node['id'] && $edge['source_handle'] === 'return',
        ));

        if (count($returns) !== 1) {
            $violations[] = $this->violation('revision_structure', 'A Revision node needs one return connection to an earlier Approval.', $node['id']);

            return;
        }

        $returnEdge = $returns[0];
        $target = $byId[$returnEdge['target']] ?? null;
        $configured = $node['data']['return_to_node_id'] ?? null;

        if ($target === null || $target['type'] === 'start') {
            $violations[] = $this->violation('revision_target', 'A revision cannot return to Start. Point it at an earlier Approval node.', $node['id'], $returnEdge['id']);

            return;
        }

        if ($target['type'] !== 'approval') {
            $violations[] = $this->violation('revision_target', 'A revision returns to an Approval node that comes before it.', $node['id'], $returnEdge['id']);

            return;
        }

        if ($configured !== $target['id']) {
            $violations[] = $this->violation('revision_target', 'The revision settings must name the same Approval node as the return connection.', $node['id'], $returnEdge['id']);
        }

        if (! $this->reaches($target['id'], $node['id'], $forward)) {
            $violations[] = $this->violation('revision_target', 'The return target must be an Approval node that can reach this Revision without using the return connection.', $node['id'], $returnEdge['id']);
        }

        $exhausted = array_values(array_filter(
            $edges,
            fn (array $edge) => $edge['source'] === $node['id'] && $edge['source_handle'] === 'exhausted',
        ));

        if (count($exhausted) !== 1) {
            $violations[] = $this->violation('revision_structure', 'A Revision node needs an exhausted connection for when the attempt limit is passed.', $node['id']);

            return;
        }

        $terminals = $this->terminals($exhausted[0]['target'], $forward);

        if ($terminals === []) {
            $violations[] = $this->violation('exhausted_result', 'The exhausted revision path must end at a rejected End node.', $node['id'], $exhausted[0]['id']);

            return;
        }

        foreach ($terminals as $terminalId) {
            $terminal = $byId[$terminalId] ?? null;

            if ($terminal === null || $terminal['type'] !== 'end' || ($terminal['data']['result'] ?? null) !== 'rejected') {
                $violations[] = $this->violation('exhausted_result', 'The exhausted revision path must end at a rejected End node.', $terminal['id'] ?? $node['id'], $exhausted[0]['id']);
            }
        }
    }

    /**
     * @param  array<string, list<array{target: string, edge: array<string, mixed>}>>  $forward
     */
    private function reaches(string $from, string $to, array $forward): bool
    {
        $seen = [];
        $stack = [$from];

        while ($stack !== []) {
            $id = array_pop($stack);

            if ($id === $to) {
                return true;
            }

            if (isset($seen[$id])) {
                continue;
            }

            $seen[$id] = true;

            foreach ($forward[$id] ?? [] as $next) {
                $stack[] = $next['target'];
            }
        }

        return false;
    }

    /**
     * @param  array<string, list<array{target: string, edge: array<string, mixed>}>>  $forward
     * @return list<string>
     */
    private function terminals(string $startId, array $forward): array
    {
        $terminals = [];
        $seen = [];
        $stack = [$startId];

        while ($stack !== []) {
            $id = array_pop($stack);

            if (isset($seen[$id])) {
                continue;
            }

            $seen[$id] = true;
            $next = $forward[$id] ?? [];

            if ($next === []) {
                $terminals[] = $id;

                continue;
            }

            foreach ($next as $item) {
                $stack[] = $item['target'];
            }
        }

        return $terminals;
    }

    private function missingHandleMessage(string $type, string $handle): string
    {
        return match ($type) {
            'approval' => 'Connect both the approved and rejected outcomes.',
            'condition' => 'Connect both the true and false outcomes.',
            'revision' => $handle === 'return'
                ? 'Connect the return outcome to an earlier Approval node.'
                : 'Connect the exhausted outcome to a rejected End node.',
            'start' => 'Connect Start to the next step.',
            default => 'Connect every outcome before publishing.',
        };
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
