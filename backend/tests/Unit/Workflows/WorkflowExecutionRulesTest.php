<?php

namespace Tests\Unit\Workflows;

use App\Exceptions\WorkflowEvaluationException;
use App\Services\Workflows\Execution\ConditionEvaluator;
use App\Services\Workflows\Execution\FormSubmissionValidator;
use Illuminate\Validation\ValidationException;
use Tests\Support\ExecutionGraphs;
use Tests\TestCase;

class WorkflowExecutionRulesTest extends TestCase
{
    public function test_form_values_keep_their_json_types(): void
    {
        $fields = [
            ExecutionGraphs::field('amount', 'number', ['validation' => ['min' => 0, 'max' => 1000]]),
            ExecutionGraphs::field('due_on', 'date', ['validation' => ['min' => '2024-01-01', 'max' => '2024-12-31']]),
            ExecutionGraphs::field('priority', 'select', ['options' => [['value' => 'low', 'label' => 'Low'], ['value' => 'high', 'label' => 'High']]]),
            ExecutionGraphs::field('urgent', 'checkbox', ['required' => true]),
            ExecutionGraphs::field('note', 'text', ['required' => false, 'validation' => ['max_length' => 20]]),
        ];

        $valid = app(FormSubmissionValidator::class)->validate($this->fields($fields), [
            'amount' => 10,
            'due_on' => '2024-02-29',
            'priority' => 'high',
            'urgent' => true,
        ]);

        $this->assertSame(10, $valid['amount']);
        $this->assertSame('2024-02-29', $valid['due_on']);
        $this->assertTrue($valid['urgent']);
        $this->assertNull($valid['note']);

        $this->assertInvalid($fields, ['amount' => '10', 'due_on' => '2024-02-29', 'priority' => 'high', 'urgent' => true], 'amount');
        $this->assertInvalid($fields, ['amount' => 10, 'due_on' => '2024-02-31', 'priority' => 'high', 'urgent' => true], 'due_on');
        $this->assertInvalid($fields, ['amount' => 10, 'due_on' => '2024-02-29', 'priority' => 'High', 'urgent' => true], 'priority');
        $this->assertInvalid($fields, ['amount' => 10, 'due_on' => '2024-02-29', 'priority' => 'high', 'urgent' => false], 'urgent');
        $this->assertInvalid($fields, ['amount' => 10, 'due_on' => '2024-02-29', 'priority' => 'high', 'urgent' => true, 'extra' => 'no'], 'data');
    }

    public function test_conditions_compare_each_field_type_without_string_coercion(): void
    {
        $evaluator = new ConditionEvaluator;
        $fields = $this->fields([
            ExecutionGraphs::field('amount', 'number'),
            ExecutionGraphs::field('due_on', 'date'),
            ExecutionGraphs::field('priority', 'select', ['options' => [['value' => 'low', 'label' => 'Low'], ['value' => 'high', 'label' => 'High']]]),
            ExecutionGraphs::field('urgent', 'checkbox', ['required' => false]),
            ExecutionGraphs::field('note', 'text', ['required' => false]),
        ]);
        $data = [
            'amount' => 10,
            'due_on' => '2024-05-02',
            'priority' => 'low',
            'urgent' => false,
            'note' => '',
        ];

        $this->assertTrue($this->outcome($evaluator, $fields, $data, 'all', [
            ExecutionGraphs::rule('amount', 'greater_than', 9),
            ExecutionGraphs::rule('amount', 'greater_than_or_equal', 10),
            ExecutionGraphs::rule('amount', 'less_than', 11),
            ExecutionGraphs::rule('amount', 'less_than_or_equal', 10),
            ExecutionGraphs::rule('amount', 'equals', 10),
            ExecutionGraphs::rule('due_on', 'greater_than', '2024-05-01'),
            ExecutionGraphs::rule('priority', 'equals', 'low'),
            ExecutionGraphs::rule('priority', 'not_equals', 'high'),
            ExecutionGraphs::rule('urgent', 'is_false'),
            ExecutionGraphs::rule('note', 'is_blank'),
        ]));

        $this->assertFalse($this->outcome($evaluator, $fields, ['amount' => 0, 'note' => 'Kept'], 'any', [
            ExecutionGraphs::rule('amount', 'greater_than', 5),
            ExecutionGraphs::rule('note', 'is_blank'),
        ]));
        $this->assertTrue($this->outcome($evaluator, $fields, ['amount' => 0, 'note' => 'Kept'], 'any', [
            ExecutionGraphs::rule('amount', 'equals', 0),
            ExecutionGraphs::rule('note', 'is_blank'),
        ]));
        $this->assertFalse($this->outcome($evaluator, $fields, ['urgent' => null], 'all', [
            ExecutionGraphs::rule('urgent', 'is_false'),
        ]));
        $this->assertFalse($this->outcome($evaluator, $fields, ['urgent' => null], 'all', [
            ExecutionGraphs::rule('urgent', 'is_true'),
        ]));
        $this->assertTrue($this->outcome($evaluator, $fields, ['note' => null], 'all', [
            ExecutionGraphs::rule('note', 'is_blank'),
        ]));

        $this->expectException(WorkflowEvaluationException::class);
        $this->outcome($evaluator, $fields, ['amount' => '10'], 'all', [
            ExecutionGraphs::rule('amount', 'greater_than', 2),
        ]);
    }

    public function test_unknown_operators_fail_closed(): void
    {
        $this->expectException(WorkflowEvaluationException::class);
        (new ConditionEvaluator)->evaluate([
            'data' => [
                'match' => 'all',
                'rules' => [ExecutionGraphs::rule('note', 'contains', 'x')],
            ],
        ], $this->fields([ExecutionGraphs::field('note', 'text', ['required' => false])]), ['note' => 'x']);
    }

    /**
     * @param  list<array<string, mixed>>  $fields
     * @param  array<string, mixed>  $data
     */
    private function assertInvalid(array $fields, array $data, string $key): void
    {
        try {
            app(FormSubmissionValidator::class)->validate($this->fields($fields), $data);
            $this->fail('Expected validation to reject the submission.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($key, $exception->errors());
        }
    }

    /**
     * @param  array<string, array<string, mixed>>  $fields
     * @param  array<string, mixed>  $data
     * @param  list<array<string, mixed>>  $rules
     */
    private function outcome(ConditionEvaluator $evaluator, array $fields, array $data, string $match, array $rules): bool
    {
        return $evaluator->evaluate([
            'data' => ['match' => $match, 'rules' => $rules],
        ], $fields, $data)['outcome'];
    }

    /**
     * @param  list<array<string, mixed>>  $fields
     * @return array<string, array<string, mixed>>
     */
    private function fields(array $fields): array
    {
        $indexed = [];

        foreach ($fields as $field) {
            $indexed[$field['id']] = $field;
        }

        return $indexed;
    }
}
