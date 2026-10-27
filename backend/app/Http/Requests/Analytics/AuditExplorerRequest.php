<?php

namespace App\Http\Requests\Analytics;

use App\Enums\WorkflowEventType;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class AuditExplorerRequest extends AnalyticsWindowRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'event_type' => ['nullable', 'string', Rule::enum(WorkflowEventType::class)],
            'actor' => ['nullable', 'string', 'max:64'],
            'instance' => ['nullable', 'uuid'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        parent::withValidator($validator);

        $validator->after(function (Validator $validator): void {
            $actor = $this->input('actor');

            if (! is_string($actor) || $actor === '' || $actor === 'system') {
                return;
            }

            if (! \Illuminate\Support\Str::isUuid($actor)) {
                $validator->errors()->add('actor', 'The actor must be a membership id or system.');
            }
        });
    }

    public function eventType(): ?string
    {
        return $this->filled('event_type') ? $this->string('event_type')->toString() : null;
    }

    public function actorFilter(): ?string
    {
        return $this->filled('actor') ? $this->string('actor')->toString() : null;
    }

    public function instanceUuid(): ?string
    {
        return $this->filled('instance') ? $this->string('instance')->toString() : null;
    }

    public function perPage(): int
    {
        return min(100, max(1, (int) $this->input('per_page', 25)));
    }
}
