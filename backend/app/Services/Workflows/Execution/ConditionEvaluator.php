<?php

namespace App\Services\Workflows\Execution;

use App\Enums\WorkflowFailureCode;
use App\Exceptions\WorkflowEvaluationException;

class ConditionEvaluator
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
    ];

    /**
     * @param  array<string, mixed>  $node
     * @param  array<string, array<string, mixed>>  $fields
     * @param  array<string, mixed>  $data
     * @return array{outcome: bool, rules: list<array{field_id: string, operator: string, matched: bool}>}
     */
    public function evaluate(array $node, array $fields, array $data): array
    {
        $config = is_array($node['data'] ?? null) ? $node['data'] : [];
        $match = $config['match'] ?? 'all';
        $rules = $config['rules'] ?? null;

        if (! in_array($match, ['all', 'any'], true) || ! is_array($rules) || $rules === []) {
            throw new WorkflowEvaluationException(WorkflowFailureCode::InvalidGraph, 'This condition cannot be evaluated.');
        }

        $results = [];

        foreach ($rules as $rule) {
            if (! is_array($rule)) {
                throw new WorkflowEvaluationException(WorkflowFailureCode::InvalidGraph, 'This condition cannot be evaluated.');
            }

            $fieldId = $rule['field_id'] ?? null;
            $operator = $rule['operator'] ?? null;
            $field = is_string($fieldId) ? ($fields[$fieldId] ?? null) : null;

            if (! is_string($fieldId) || ! is_string($operator) || ! is_array($field)) {
                throw new WorkflowEvaluationException(WorkflowFailureCode::InvalidGraph, 'This condition references a missing field.');
            }

            $type = $field['type'] ?? null;

            if (! is_string($type) || ! in_array($operator, self::OPERATORS[$type] ?? [], true)) {
                throw new WorkflowEvaluationException(WorkflowFailureCode::UnknownOperator, 'This condition uses an unsupported operator.');
            }

            $results[] = [
                'field_id' => $fieldId,
                'operator' => $operator,
                'matched' => $this->matches($type, $operator, $data[$fieldId] ?? null, $rule['value'] ?? null, $field),
            ];
        }

        $matched = array_column($results, 'matched');
        $outcome = $match === 'any' ? in_array(true, $matched, true) : ! in_array(false, $matched, true);

        return [
            'outcome' => $outcome,
            'rules' => $results,
        ];
    }

    /**
     * @param  array<string, mixed>  $field
     */
    private function matches(string $type, string $operator, mixed $actual, mixed $expected, array $field): bool
    {
        if ($operator === 'is_blank') {
            return $this->blank($actual);
        }

        if ($operator === 'is_not_blank') {
            return ! $this->blank($actual);
        }

        if ($operator === 'is_true' || $operator === 'is_false') {
            if (! is_bool($actual) && $actual !== null) {
                throw new WorkflowEvaluationException(WorkflowFailureCode::CorruptState, 'A checkbox condition received a non-boolean value.');
            }

            return $operator === 'is_true' ? $actual === true : $actual === false;
        }

        if (in_array($type, ['text', 'textarea', 'select'], true)) {
            if ($actual !== null && ! is_string($actual)) {
                throw new WorkflowEvaluationException(WorkflowFailureCode::CorruptState, 'A text condition received a non-text value.');
            }

            if ($type === 'select') {
                $options = array_column(is_array($field['options'] ?? null) ? $field['options'] : [], 'value');

                if (! is_string($expected) || ! in_array($expected, $options, true)) {
                    throw new WorkflowEvaluationException(WorkflowFailureCode::InvalidGraph, 'A select condition compares an unknown option.');
                }
            } elseif (! is_string($expected)) {
                throw new WorkflowEvaluationException(WorkflowFailureCode::InvalidGraph, 'A text condition compares a non-text value.');
            }

            return $operator === 'equals' ? $actual === $expected : $actual !== $expected;
        }

        if ($type === 'number') {
            if (! NumericValue::isNumber($actual) || ! NumericValue::isNumber($expected)) {
                throw new WorkflowEvaluationException(
                    NumericValue::isNumber($expected) ? WorkflowFailureCode::CorruptState : WorkflowFailureCode::InvalidGraph,
                    'A number condition can only compare numbers.',
                );
            }

            $comparison = NumericValue::compare(NumericValue::normalize($actual), NumericValue::normalize($expected));

            return match ($operator) {
                'equals' => $comparison === 0,
                'not_equals' => $comparison !== 0,
                'greater_than' => $comparison > 0,
                'greater_than_or_equal' => $comparison >= 0,
                'less_than' => $comparison < 0,
                'less_than_or_equal' => $comparison <= 0,
                default => throw new WorkflowEvaluationException(WorkflowFailureCode::UnknownOperator, 'This number comparison is not supported.'),
            };
        }

        if ($type === 'date') {
            $left = CalendarDate::normalize($actual);
            $right = CalendarDate::normalize($expected);

            if ($actual !== null && $left === null) {
                throw new WorkflowEvaluationException(WorkflowFailureCode::CorruptState, 'A date condition received an invalid date.');
            }

            if ($right === null) {
                throw new WorkflowEvaluationException(WorkflowFailureCode::InvalidGraph, 'A date condition compares an invalid date.');
            }

            if ($left === null) {
                return $operator === 'not_equals';
            }

            return match ($operator) {
                'equals' => $left === $right,
                'not_equals' => $left !== $right,
                'greater_than' => $left > $right,
                'greater_than_or_equal' => $left >= $right,
                'less_than' => $left < $right,
                'less_than_or_equal' => $left <= $right,
                default => throw new WorkflowEvaluationException(WorkflowFailureCode::UnknownOperator, 'This date comparison is not supported.'),
            };
        }

        throw new WorkflowEvaluationException(WorkflowFailureCode::UnknownOperator, 'This field cannot be used in a condition.');
    }

    private function blank(mixed $value): bool
    {
        return $value === null || $value === '';
    }
}
