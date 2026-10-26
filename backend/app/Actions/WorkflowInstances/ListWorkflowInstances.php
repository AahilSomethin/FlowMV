<?php

namespace App\Actions\WorkflowInstances;

use App\Models\Organization;
use App\Models\User;
use App\Models\WorkflowInstance;
use App\Services\Workflows\Execution\WorkflowInstanceVisibility;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class ListWorkflowInstances
{
    public function __construct(private readonly WorkflowInstanceVisibility $visibility) {}

    public function __invoke(User $user, Organization $organization, int $page): LengthAwarePaginator
    {
        $ids = $this->visibility->visibleIds($user, $organization);
        $query = WorkflowInstance::query()
            ->where('organization_id', $organization->id)
            ->with(['workflow', 'version', 'submitter'])
            ->orderByDesc('id');

        if ($ids !== null) {
            $query->whereIn('id', $ids === [] ? [-1] : $ids);
        }

        return $query->paginate(15, ['*'], 'page', max($page, 1));
    }
}
