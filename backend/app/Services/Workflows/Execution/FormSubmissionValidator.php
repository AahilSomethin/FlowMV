<?php

namespace App\Services\Workflows\Execution;

use App\Enums\WorkflowFailureCode;
use App\Exceptions\WorkflowEvaluationException;
use Illuminate\Validation\ValidationException;

class FormSubmissionValidator
{
    /**
     * @param  array<string, array<string, mixed>>  $fields
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function validate(array $fields, array $data): array
    {
        $errors = [];

        foreach ($data as $key => $value) {
            if (! is_string($key) || ! isset($fields[$key])) {
                $errors['data'][] = 'Remove values that are not part of this form.';
                break;
            }
        }

        $normalized = [];

        foreach ($fields as $id => $field) {
            $present = array_key_exists($id, $data);
            $value = $present ? $data[$id] : ($field['default_value'] ?? null);

            if ($value === '') {
                $value = null;
            }

            $type = $field['type'] ?? null;
            $fieldErrors = $this->value($id, is_string($type) ? $type : '', $field, $value);

            if ($fieldErrors !== []) {
                $errors[$id] = $fieldErrors;

                continue;
            }

            $normalized[$id] = $this->normalize($type, $value);
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $normalized;
    }

    /**
     * @param  array<string, mixed>  $field
     * @return list<string>
     */
    private function value(string $id, string $type, array $field, mixed $value): array
    {
        $required = (bool) ($field['required'] ?? false);
        $validation = is_array($field['validation'] ?? null) ? $field['validation'] : [];

        if ($type === 'checkbox') {
            if ($value === null) {
                return $required ? ['This field must be checked.'] : [];
            }

            if (! is_bool($value)) {
                return ['Choose yes or no.'];
            }

            return $required && $value !== true ? ['This field must be checked.'] : [];
        }

        if ($value === null) {
            return $required ? ['This field is required.'] : [];
        }

        if (in_array($type, ['text', 'textarea'], true)) {
            if (! is_string($value)) {
                return ['Enter text.'];
            }

            $length = mb_strlen($value);
            $min = $validation['min_length'] ?? null;
            $max = $validation['max_length'] ?? ($type === 'textarea' ? 5000 : 500);

            if (is_int($min) && $length < $min) {
                return ['Enter at least '.$min.' characters.'];
            }

            if (is_int($max) && $length > $max) {
                return ['Enter at most '.$max.' characters.'];
            }

            return [];
        }

        if ($type === 'number') {
            if (! NumericValue::isNumber($value)) {
                return ['Enter a number.'];
            }

            $number = NumericValue::normalize($value);
            $min = $validation['min'] ?? null;
            $max = $validation['max'] ?? null;

            if (NumericValue::isNumber($min) && NumericValue::compare($number, NumericValue::normalize($min)) < 0) {
                return ['Enter a number that is at least the minimum.'];
            }

            if (NumericValue::isNumber($max) && NumericValue::compare($number, NumericValue::normalize($max)) > 0) {
                return ['Enter a number that is at most the maximum.'];
            }

            return [];
        }

        if ($type === 'date') {
            $date = CalendarDate::normalize($value);

            if ($date === null) {
                return ['Enter a date as YYYY-MM-DD.'];
            }

            $min = CalendarDate::normalize($validation['min'] ?? null);
            $max = CalendarDate::normalize($validation['max'] ?? null);

            if ($min !== null && $date < $min) {
                return ['Enter a date on or after the minimum.'];
            }

            if ($max !== null && $date > $max) {
                return ['Enter a date on or before the maximum.'];
            }

            return [];
        }

        if ($type === 'file') {
            return ['File attachments cannot be submitted yet.'];
        }

        if ($type === 'select') {
            $options = array_column(is_array($field['options'] ?? null) ? $field['options'] : [], 'value');

            if (! is_string($value) || ! in_array($value, $options, true)) {
                return ['Choose one of the available options.'];
            }

            return [];
        }

        throw new WorkflowEvaluationException(WorkflowFailureCode::CorruptState, 'This form field cannot be validated.');
    }

    private function normalize(mixed $type, mixed $value): mixed
    {
        if ($type === 'number' && NumericValue::isNumber($value)) {
            return NumericValue::normalize($value);
        }

        if ($type === 'date' && is_string($value)) {
            return CalendarDate::normalize($value);
        }

        return $value;
    }
}
