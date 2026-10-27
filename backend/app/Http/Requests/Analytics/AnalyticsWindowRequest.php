<?php

namespace App\Http\Requests\Analytics;

use App\Enums\WorkflowInstanceStatus;
use App\Http\Requests\WorkflowInstances\ExecutionRequest;
use Carbon\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class AnalyticsWindowRequest extends ExecutionRequest
{
    public function authorize(): bool
    {
        $this->organization();

        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'from' => ['nullable', 'date_format:Y-m-d', 'required_with:to'],
            'to' => ['nullable', 'date_format:Y-m-d', 'required_with:from'],
            'workflow' => [
                'nullable',
                'uuid',
                Rule::exists('workflows', 'uuid')->where('organization_id', $this->organization()->id),
            ],
            'status' => ['nullable', 'string', Rule::enum(WorkflowInstanceStatus::class)],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty() || ! $this->filled('from') || ! $this->filled('to')) {
                return;
            }

            $from = Carbon::createFromFormat('Y-m-d', $this->string('from')->toString(), 'UTC')->startOfDay();
            $to = Carbon::createFromFormat('Y-m-d', $this->string('to')->toString(), 'UTC')->startOfDay();

            if ($to->lt($from)) {
                $validator->errors()->add('to', 'The end date must be on or after the start date.');
            }

            if ($from->diffInDays($to) > 365) {
                $validator->errors()->add('to', 'The date range cannot exceed 366 days.');
            }
        });
    }

    public function windowStart(): Carbon
    {
        if ($this->filled('from')) {
            return Carbon::createFromFormat('Y-m-d', $this->string('from')->toString(), 'UTC')->startOfDay();
        }

        return now()->utc()->startOfDay()->subDays(29);
    }

    public function windowEnd(): Carbon
    {
        $end = $this->filled('to')
            ? Carbon::createFromFormat('Y-m-d', $this->string('to')->toString(), 'UTC')->startOfDay()
            : now()->utc()->startOfDay();

        return $end->addDay();
    }

    public function workflowUuid(): ?string
    {
        return $this->filled('workflow') ? $this->string('workflow')->toString() : null;
    }

    public function statusFilter(): ?string
    {
        return $this->filled('status') ? $this->string('status')->toString() : null;
    }
}
