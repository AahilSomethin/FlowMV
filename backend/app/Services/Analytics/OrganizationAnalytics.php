<?php

namespace App\Services\Analytics;

use App\Enums\OrganizationRole;
use App\Enums\WorkflowEventType;
use App\Enums\WorkflowInstanceStatus;
use App\Models\Organization;
use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowInstance;
use App\Models\WorkflowVersion;
use App\Services\Workflows\Execution\ApprovalEligibility;
use App\Services\Workflows\Execution\NodeLabels;
use App\Services\Workflows\Execution\TaskEligibilityQuery;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Organization analytics are SQL aggregates over the same instance visibility as the request list.
 * Admins skip the JSON approval predicate. Other roles pay that predicate once per aggregate,
 * not once per request row, and median uses a window function instead of loading durations into PHP.
 */
class OrganizationAnalytics
{
    public const MINIMUM_SAMPLE = 5;

    public const MAX_GROUPS = 50;

    public function __construct(
        private readonly TaskEligibilityQuery $eligibility,
        private readonly ApprovalEligibility $roles,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function summary(
        User $user,
        Organization $organization,
        CarbonInterface $from,
        CarbonInterface $to,
        ?string $workflowUuid,
        ?string $status,
    ): array {
        $workflowId = $this->workflowId($organization, $workflowUuid);
        $role = $this->roles->role($user, $organization->id);
        $distribution = $this->statusCounts($user, $organization, $from, $to, $workflowId, $status);
        $rates = $this->statusCounts($user, $organization, $from, $to, $workflowId, null);
        $completed = (int) ($rates[WorkflowInstanceStatus::Completed->value] ?? 0);
        $rejected = (int) ($rates[WorkflowInstanceStatus::Rejected->value] ?? 0);
        $terminal = $completed + $rejected;
        $duration = DurationSql::summarize(
            $this->completedDurations($user, $organization, $from, $to, $workflowId),
            DurationSql::seconds('workflow_instances.started_at', 'workflow_instances.completed_at'),
        );
        $wait = $this->approvalWait($user, $organization, $from, $to, $workflowId);
        $revisions = $this->revisions($user, $organization, $from, $to, $workflowId);
        $workflows = $this->workflows($user, $organization, $from, $to, $workflowId, $status);
        $bottlenecks = $this->bottlenecks($user, $organization, $from, $to, $workflowId);
        $cohort = array_sum($distribution);
        $rateCohort = array_sum($rates);
        $terminalSufficient = $terminal >= self::MINIMUM_SAMPLE;

        return [
            'timezone' => 'UTC',
            'scope' => $role === OrganizationRole::Admin ? 'organization' : 'visible_requests',
            'scope_label' => $role === OrganizationRole::Admin ? 'Organization-wide' : 'Limited to requests you can view',
            'from' => $from->toDateString(),
            'to' => $to->copy()->subDay()->toDateString(),
            'minimum_sample' => self::MINIMUM_SAMPLE,
            'filters' => [
                'workflow' => $workflowUuid,
                'status' => $status,
            ],
            'cohort' => ['total' => $cohort],
            'status_distribution' => $this->distribution($distribution, $cohort),
            'volume' => $this->volume($user, $organization, $from, $to, $workflowId, $status),
            'completion' => [
                'completed' => $completed,
                'rejected' => $rejected,
                'denominator' => $terminal,
                'rate' => $terminalSufficient ? round($completed / $terminal, 4) : null,
                'sufficient' => $terminalSufficient,
            ],
            'rejection' => [
                'rejected' => $rejected,
                'denominator' => $terminal,
                'rate' => $terminalSufficient ? round($rejected / $terminal, 4) : null,
                'sufficient' => $terminalSufficient,
            ],
            'completion_duration' => $this->duration($duration),
            'approval_wait' => $wait,
            'workflows' => $workflows['rows'],
            'revisions' => [
                'cohort_size' => $rateCohort,
                'instances_revised' => $revisions['instances_revised'],
                'submissions' => $revisions['submissions'],
                'rate' => $rateCohort >= self::MINIMUM_SAMPLE
                    ? round($revisions['instances_revised'] / $rateCohort, 4)
                    : null,
                'sufficient' => $rateCohort >= self::MINIMUM_SAMPLE,
                'mean_among_revised' => $revisions['instances_revised'] > 0
                    ? round($revisions['submissions'] / $revisions['instances_revised'], 2)
                    : null,
            ],
            'task_decisions' => $this->taskDecisions($user, $organization, $from, $to, $workflowId),
            'bottlenecks' => $bottlenecks['rows'],
            'truncated' => [
                'workflows' => $workflows['truncated'],
                'bottlenecks' => $bottlenecks['truncated'],
            ],
        ];
    }

    /**
     * @return array{rows: list<array<string, mixed>>, truncated: bool}
     */
    public function workflowExport(
        User $user,
        Organization $organization,
        CarbonInterface $from,
        CarbonInterface $to,
        ?string $workflowUuid,
        ?string $status,
        int $cap,
    ): array {
        $summary = $this->summary($user, $organization, $from, $to, $workflowUuid, $status);
        $rows = array_slice($summary['workflows'], 0, $cap);

        return [
            'rows' => $rows,
            'truncated' => count($summary['workflows']) > $cap || $summary['truncated']['workflows'] === true,
        ];
    }

    /**
     * @return array<string, int>
     */
    private function statusCounts(
        User $user,
        Organization $organization,
        CarbonInterface $from,
        CarbonInterface $to,
        ?int $workflowId,
        ?string $status,
    ): array {
        $rows = $this->instances($user, $organization, $from, $to, $workflowId, $status)
            ->toBase()
            ->selectRaw('workflow_instances.status as status, count(*) as aggregate')
            ->groupBy('workflow_instances.status')
            ->get();
        $counts = [];

        foreach ($rows as $row) {
            $counts[(string) $row->status] = (int) $row->aggregate;
        }

        return $counts;
    }

    /**
     * @param  array<string, int>  $counts
     * @return list<array{status: string, count: int, share: float|null}>
     */
    private function distribution(array $counts, int $total): array
    {
        $rows = [];

        foreach (WorkflowInstanceStatus::cases() as $status) {
            $count = $counts[$status->value] ?? 0;
            $rows[] = [
                'status' => $status->value,
                'count' => $count,
                'share' => $total > 0 ? round($count / $total, 4) : null,
            ];
        }

        return $rows;
    }

    /**
     * @return list<array{date: string, count: int}>
     */
    private function volume(
        User $user,
        Organization $organization,
        CarbonInterface $from,
        CarbonInterface $to,
        ?int $workflowId,
        ?string $status,
    ): array {
        $bucket = DurationSql::utcDate('workflow_instances.started_at');
        $rows = $this->instances($user, $organization, $from, $to, $workflowId, $status)
            ->toBase()
            ->selectRaw($bucket.' as bucket, count(*) as aggregate')
            ->groupByRaw($bucket)
            ->get();
        $counts = [];

        foreach ($rows as $row) {
            $counts[substr((string) $row->bucket, 0, 10)] = (int) $row->aggregate;
        }

        $volume = [];
        $cursor = $from->copy()->startOfDay();
        $end = $to->copy()->startOfDay();

        while ($cursor < $end) {
            $date = $cursor->toDateString();
            $volume[] = ['date' => $date, 'count' => $counts[$date] ?? 0];
            $cursor = $cursor->addDay();
        }

        return $volume;
    }

    /**
     * @param  array{sample_size: int, average_seconds: int|null, median_seconds: int|null}  $duration
     * @return array{sample_size: int, average_seconds: int|null, median_seconds: int|null, sufficient: bool}
     */
    private function duration(array $duration): array
    {
        $sufficient = $duration['sample_size'] >= self::MINIMUM_SAMPLE;

        return [
            'sample_size' => $duration['sample_size'],
            'average_seconds' => $sufficient ? $duration['average_seconds'] : null,
            'median_seconds' => $sufficient ? $duration['median_seconds'] : null,
            'sufficient' => $sufficient,
        ];
    }

    /**
     * @return array{sample_size: int, average_seconds: int|null, median_seconds: int|null, sufficient: bool, open_count: int, oldest_open_assigned_at: mixed}
     */
    private function approvalWait(
        User $user,
        Organization $organization,
        CarbonInterface $from,
        CarbonInterface $to,
        ?int $workflowId,
    ): array {
        $seconds = DurationSql::seconds('workflow_tasks.assigned_at', 'workflow_tasks.completed_at');
        $query = $this->tasks($user, $organization, $workflowId)
            ->where('workflow_tasks.task_type', 'approval')
            ->where('workflow_tasks.status', 'completed')
            ->where('workflow_tasks.assigned_at', '>=', $from)
            ->where('workflow_tasks.assigned_at', '<', $to)
            ->whereColumn('workflow_tasks.completed_at', '>=', 'workflow_tasks.assigned_at');
        $duration = DurationSql::summarize($query, $seconds);
        $open = $this->tasks($user, $organization, $workflowId)
            ->where('workflow_tasks.task_type', 'approval')
            ->where('workflow_tasks.status', 'open')
            ->selectRaw('count(*) as aggregate, min(workflow_tasks.assigned_at) as oldest')
            ->first();

        return [
            ...$this->duration($duration),
            'open_count' => (int) ($open?->getAttribute('aggregate') ?? 0),
            'oldest_open_assigned_at' => $open?->getAttribute('oldest'),
        ];
    }

    /**
     * @return array{submissions: int, instances_revised: int}
     */
    private function revisions(
        User $user,
        Organization $organization,
        CarbonInterface $from,
        CarbonInterface $to,
        ?int $workflowId,
    ): array {
        $row = DB::query()
            ->from('workflow_events')
            ->where('workflow_events.organization_id', $organization->id)
            ->where('workflow_events.event_type', WorkflowEventType::RevisionSubmitted->value)
            ->whereIn('workflow_events.workflow_instance_id', $this->instances($user, $organization, $from, $to, $workflowId, null)->select('workflow_instances.id'))
            ->selectRaw('count(*) as submissions, count(distinct workflow_events.workflow_instance_id) as instances_revised')
            ->first();

        return [
            'submissions' => (int) ($row->submissions ?? 0),
            'instances_revised' => (int) ($row->instances_revised ?? 0),
        ];
    }

    /**
     * @return array{approved: int, rejected: int}
     */
    private function taskDecisions(
        User $user,
        Organization $organization,
        CarbonInterface $from,
        CarbonInterface $to,
        ?int $workflowId,
    ): array {
        $row = $this->tasks($user, $organization, $workflowId)
            ->where('workflow_tasks.task_type', 'approval')
            ->where('workflow_tasks.status', 'completed')
            ->where('workflow_tasks.completed_at', '>=', $from)
            ->where('workflow_tasks.completed_at', '<', $to)
            ->selectRaw("sum(case when workflow_tasks.decision = 'approved' then 1 else 0 end) as approved, sum(case when workflow_tasks.decision = 'rejected' then 1 else 0 end) as rejected")
            ->first();

        return [
            'approved' => (int) ($row?->getAttribute('approved') ?? 0),
            'rejected' => (int) ($row?->getAttribute('rejected') ?? 0),
        ];
    }

    /**
     * @return array{rows: list<array<string, mixed>>, truncated: bool}
     */
    private function workflows(
        User $user,
        Organization $organization,
        CarbonInterface $from,
        CarbonInterface $to,
        ?int $workflowId,
        ?string $status,
    ): array {
        $rows = $this->instances($user, $organization, $from, $to, $workflowId, $status)
            ->join('workflows', 'workflows.id', '=', 'workflow_instances.workflow_id')
            ->selectRaw("workflows.id as workflow_key, workflows.uuid as workflow_uuid, workflows.name as workflow_name, count(*) as started, sum(case when workflow_instances.status = 'completed' then 1 else 0 end) as completed, sum(case when workflow_instances.status = 'rejected' then 1 else 0 end) as rejected, sum(case when workflow_instances.status in ('running', 'awaiting_approval', 'awaiting_revision') then 1 else 0 end) as in_progress, sum(case when workflow_instances.status = 'failed' then 1 else 0 end) as failed")
            ->groupBy('workflows.id', 'workflows.uuid', 'workflows.name')
            ->orderBy('workflows.name')
            ->limit(self::MAX_GROUPS + 1)
            ->get();
        $truncated = $rows->count() > self::MAX_GROUPS;
        $rows = $rows->take(self::MAX_GROUPS);
        $medians = [];

        foreach (DurationSql::grouped(
            $this->completedDurations($user, $organization, $from, $to, $workflowId),
            DurationSql::seconds('workflow_instances.started_at', 'workflow_instances.completed_at'),
            ['workflow_instances.workflow_id'],
        ) as $median) {
            $medians[(int) $median['workflow_id']] = $median;
        }

        $rates = $this->instances($user, $organization, $from, $to, $workflowId, null)
            ->selectRaw("workflow_instances.workflow_id as workflow_key, sum(case when workflow_instances.status = 'completed' then 1 else 0 end) as completed, sum(case when workflow_instances.status = 'rejected' then 1 else 0 end) as rejected")
            ->groupBy('workflow_instances.workflow_id')
            ->get()
            ->keyBy(fn ($row) => (int) $row->getAttribute('workflow_key'));

        $mapped = [];

        foreach ($rows as $row) {
            $key = (int) $row->getAttribute('workflow_key');
            $rate = $rates->get($key);
            $terminalCompleted = (int) ($rate?->getAttribute('completed') ?? 0);
            $terminalRejected = (int) ($rate?->getAttribute('rejected') ?? 0);
            $terminal = $terminalCompleted + $terminalRejected;
            $median = $medians[$key] ?? null;
            $sufficient = $terminal >= self::MINIMUM_SAMPLE;
            $durationSufficient = ($median['sample_size'] ?? 0) >= self::MINIMUM_SAMPLE;
            $mapped[] = [
                'uuid' => (string) $row->getAttribute('workflow_uuid'),
                'name' => (string) $row->getAttribute('workflow_name'),
                'started' => (int) $row->getAttribute('started'),
                'completed' => (int) $row->getAttribute('completed'),
                'rejected' => (int) $row->getAttribute('rejected'),
                'in_progress' => (int) $row->getAttribute('in_progress'),
                'failed' => (int) $row->getAttribute('failed'),
                'terminal_completed' => $terminalCompleted,
                'terminal_denominator' => $terminal,
                'completion_rate' => $sufficient ? round($terminalCompleted / $terminal, 4) : null,
                'completion_sufficient' => $sufficient,
                'median_completion_seconds' => $durationSufficient ? $median['median_seconds'] : null,
            ];
        }

        return ['rows' => $mapped, 'truncated' => $truncated];
    }

    /**
     * @return array{rows: list<array<string, mixed>>, truncated: bool}
     */
    private function bottlenecks(
        User $user,
        Organization $organization,
        CarbonInterface $from,
        CarbonInterface $to,
        ?int $workflowId,
    ): array {
        $seconds = DurationSql::seconds('workflow_tasks.assigned_at', 'workflow_tasks.completed_at');
        $base = $this->tasks($user, $organization, $workflowId)
            ->join('workflow_instances as bottleneck_instances', 'bottleneck_instances.id', '=', 'workflow_tasks.workflow_instance_id')
            ->join('workflows', 'workflows.id', '=', 'bottleneck_instances.workflow_id')
            ->where('workflow_tasks.task_type', 'approval')
            ->where('workflow_tasks.status', 'completed')
            ->where('workflow_tasks.assigned_at', '>=', $from)
            ->where('workflow_tasks.assigned_at', '<', $to)
            ->whereColumn('workflow_tasks.completed_at', '>=', 'workflow_tasks.assigned_at');
        $groups = (clone $base)
            ->selectRaw('workflows.uuid as workflow_uuid, workflows.name as workflow_name, bottleneck_instances.workflow_version_id as workflow_version_id, workflow_tasks.node_id as node_id, count(*) as decisions, avg('.$seconds.') as average_seconds')
            ->groupBy('workflows.uuid', 'workflows.name', 'bottleneck_instances.workflow_version_id', 'workflow_tasks.node_id')
            ->get();
        $medians = [];

        foreach (DurationSql::grouped(
            $base,
            $seconds,
            ['bottleneck_instances.workflow_version_id', 'workflow_tasks.node_id'],
        ) as $median) {
            $medians[$median['workflow_version_id'].'|'.$median['node_id']] = $median;
        }

        $open = $this->tasks($user, $organization, $workflowId)
            ->join('workflow_instances as open_instances', 'open_instances.id', '=', 'workflow_tasks.workflow_instance_id')
            ->where('workflow_tasks.task_type', 'approval')
            ->where('workflow_tasks.status', 'open')
            ->selectRaw('open_instances.workflow_version_id as workflow_version_id, workflow_tasks.node_id as node_id, count(*) as open_count')
            ->groupBy('open_instances.workflow_version_id', 'workflow_tasks.node_id')
            ->get();
        $openCounts = [];

        foreach ($open as $row) {
            $openCounts[$row->getAttribute('workflow_version_id').'|'.$row->getAttribute('node_id')] = (int) $row->getAttribute('open_count');
        }

        $versionIds = $groups->pluck('workflow_version_id')->unique()->filter()->all();
        $labels = [];

        foreach (WorkflowVersion::query()->whereIn('id', $versionIds)->get() as $version) {
            $labels[$version->id] = NodeLabels::forVersion($version);
        }

        $rows = [];

        foreach ($groups as $group) {
            $key = $group->getAttribute('workflow_version_id').'|'.$group->getAttribute('node_id');
            $median = $medians[$key] ?? null;
            $sample = (int) ($median['sample_size'] ?? $group->getAttribute('decisions'));
            $sufficient = $sample >= self::MINIMUM_SAMPLE;
            $nodeId = (string) $group->getAttribute('node_id');
            $rows[] = [
                'workflow_uuid' => (string) $group->getAttribute('workflow_uuid'),
                'workflow_name' => (string) $group->getAttribute('workflow_name'),
                'node_id' => $nodeId,
                'node_label' => NodeLabels::text($labels[(int) $group->getAttribute('workflow_version_id')] ?? [], $nodeId),
                'decisions' => (int) $group->getAttribute('decisions'),
                'average_seconds' => $sufficient ? (int) round((float) $group->getAttribute('average_seconds')) : null,
                'median_seconds' => $sufficient ? ($median['median_seconds'] ?? null) : null,
                'sufficient' => $sufficient,
                'open_count' => $openCounts[$key] ?? 0,
            ];
        }

        usort($rows, function (array $left, array $right): int {
            return ($right['median_seconds'] ?? -1) <=> ($left['median_seconds'] ?? -1);
        });

        return [
            'rows' => array_slice($rows, 0, self::MAX_GROUPS),
            'truncated' => count($rows) > self::MAX_GROUPS,
        ];
    }

    /**
     * @return Builder<WorkflowInstance>
     */
    private function instances(
        User $user,
        Organization $organization,
        CarbonInterface $from,
        CarbonInterface $to,
        ?int $workflowId,
        ?string $status,
    ): Builder {
        $query = WorkflowInstance::query()
            ->where('workflow_instances.organization_id', $organization->id)
            ->where('workflow_instances.started_at', '>=', $from)
            ->where('workflow_instances.started_at', '<', $to);

        if ($workflowId !== null) {
            $query->where('workflow_instances.workflow_id', $workflowId);
        }

        if ($status !== null) {
            $query->where('workflow_instances.status', $status);
        }

        $this->eligibility->constrainInstances($query, $user, $organization);

        return $query;
    }

    /**
     * @return Builder<WorkflowInstance>
     */
    private function completedDurations(
        User $user,
        Organization $organization,
        CarbonInterface $from,
        CarbonInterface $to,
        ?int $workflowId,
    ): Builder {
        return $this->instances($user, $organization, $from, $to, $workflowId, WorkflowInstanceStatus::Completed->value)
            ->whereNotNull('workflow_instances.completed_at')
            ->whereColumn('workflow_instances.completed_at', '>=', 'workflow_instances.started_at');
    }

    /**
     * Visible instances for task metrics. The date window is applied to the task, not the request start.
     *
     * @return Builder<\App\Models\WorkflowTask>
     */
    private function tasks(User $user, Organization $organization, ?int $workflowId): Builder
    {
        $visible = WorkflowInstance::query()
            ->select('workflow_instances.id')
            ->where('workflow_instances.organization_id', $organization->id);

        if ($workflowId !== null) {
            $visible->where('workflow_instances.workflow_id', $workflowId);
        }

        $this->eligibility->constrainInstances($visible, $user, $organization);

        return \App\Models\WorkflowTask::query()
            ->where('workflow_tasks.organization_id', $organization->id)
            ->whereIn('workflow_tasks.workflow_instance_id', $visible);
    }

    private function workflowId(Organization $organization, ?string $workflowUuid): ?int
    {
        if ($workflowUuid === null) {
            return null;
        }

        $id = Workflow::query()
            ->where('organization_id', $organization->id)
            ->where('uuid', $workflowUuid)
            ->value('id');

        return $id === null ? 0 : (int) $id;
    }
}
