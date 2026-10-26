<?php

namespace Database\Factories;

use App\Enums\WorkflowStatus;
use App\Models\Organization;
use App\Models\User;
use App\Models\Workflow;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Workflow>
 */
class WorkflowFactory extends Factory
{
    protected $model = Workflow::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'name' => 'Purchase approval',
            'description' => null,
            'status' => WorkflowStatus::Draft,
            'created_by' => User::factory(),
        ];
    }
}
