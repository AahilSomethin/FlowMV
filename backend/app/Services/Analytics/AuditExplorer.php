<?php

namespace App\Services\Analytics;

use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use App\Models\WorkflowEvent;
use App\Models\WorkflowInstance;
use App\Models\WorkflowVersion;
use App\Services\Workflows\Execution\NodeLabels;
use App\Services\Workflows\Execution\TaskEligibilityQuery;
use App\Support\CsvCell;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

class AuditExplorer
{
    public const EXPORT_CAP = 2000;

    /**
     * Keys already stored on workflow events. Submission values are never copied through.
     *
     * @var list<string>
     */
    private const PAYLOAD_KEYS = [
        'decision',
        'task_uuid',
        'task_type',
        'changed_field_ids',
        'attempt_count',
        'max_returns',
        'match',
        'outcome',
        'failure_code',
        'recoverable',
        'result',
        'version_number',
        'workflow_version_uuid',
        'recipient_count',
        'channel_count',
        'queued_count',
        'outbox_uuid',
        'channel',
        'recipient_user_id',
        'permanent',
        'delay_uuid',
        'resume_at',
        'reason',
        'previous_user_id',
        'assignee_user_id',
    ];

    public function __construct(private readonly TaskEligibilityQuery $eligibility) {}

    /**
     * @return LengthAwarePaginator<int, WorkflowEvent>
     */
    public function page(
        User $user,
        Organization $organization,
        CarbonInterface $from,
        CarbonInterface $to,
        ?string $workflowUuid,
        ?string $eventType,
        ?string $actor,
        ?string $instanceUuid,
        int $page,
        int $perPage,
    ): LengthAwarePaginator {
        return $this->query($user, $organization, $from, $to, $workflowUuid, $eventType, $actor, $instanceUuid)
            ->paginate($perPage, ['workflow_events.*'], 'page', $page);
    }

    /**
     * @return array{rows: list<list<string>>, truncated: bool}
     */
    public function export(
        User $user,
        Organization $organization,
        CarbonInterface $from,
        CarbonInterface $to,
        ?string $workflowUuid,
        ?string $eventType,
        ?string $actor,
        ?string $instanceUuid,
    ): array {
        $events = $this->query($user, $organization, $from, $to, $workflowUuid, $eventType, $actor, $instanceUuid)
            ->limit(self::EXPORT_CAP + 1)
            ->get();
        $truncated = $events->count() > self::EXPORT_CAP;
        $presented = $this->present($events->take(self::EXPORT_CAP));
        $rows = [];

        foreach ($presented as $event) {
            $rows[] = [
                CsvCell::text($event['created_at']),
                CsvCell::text($event['event_type']),
                CsvCell::text($event['workflow_name']),
                CsvCell::text($event['instance_uuid']),
                CsvCell::text($event['node_label']),
                CsvCell::text($event['actor_name']),
                CsvCell::text($event['payload']),
            ];
        }

        return ['rows' => $rows, 'truncated' => $truncated];
    }

    /**
     * @param  iterable<WorkflowEvent>  $events
     * @return list<array<string, mixed>>
     */
    public function present(iterable $events): array
    {
        $events = collect($events);
        $versionIds = $events->map(fn (WorkflowEvent $event) => $event->instance?->workflow_version_id)->filter()->unique()->values();
        $labels = [];

        if ($versionIds->isNotEmpty()) {
            foreach (WorkflowVersion::query()->whereIn('id', $versionIds)->get() as $version) {
                $labels[$version->id] = NodeLabels::forVersion($version);
            }
        }

        return $events->map(function (WorkflowEvent $event) use ($labels): array {
            $versionId = $event->instance?->workflow_version_id;

            return [
                'uuid' => $event->uuid,
                'event_type' => $event->event_type->value,
                'created_at' => $event->created_at,
                'node_id' => $event->node_id,
                'node_label' => NodeLabels::text($labels[$versionId] ?? [], $event->node_id),
                'instance_uuid' => $event->instance?->uuid,
                'workflow_uuid' => $event->instance?->workflow?->uuid,
                'workflow_name' => $event->instance?->workflow?->name,
                'actor_name' => $event->actor?->name,
                'payload' => self::payload(is_array($event->event_payload) ? $event->event_payload : []),
            ];
        })->values()->all();
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public static function payload(array $payload): array
    {
        $safe = [];

        foreach (self::PAYLOAD_KEYS as $key) {
            if (! array_key_exists($key, $payload)) {
                continue;
            }

            $value = $payload[$key];

            if (is_array($value)) {
                $items = [];

                foreach ($value as $item) {
                    if (is_scalar($item) || $item === null) {
                        $items[] = $item;
                    }
                }

                $safe[$key] = $items;

                continue;
            }

            if (is_scalar($value) || $value === null) {
                $safe[$key] = $value;
            }
        }

        if (isset($payload['rules']) && is_array($payload['rules'])) {
            $rules = [];

            foreach ($payload['rules'] as $rule) {
                if (! is_array($rule)) {
                    continue;
                }

                $rules[] = array_intersect_key($rule, array_flip(['field_id', 'operator', 'matched']));
            }

            $safe['rules'] = $rules;
        }

        return $safe;
    }

    /**
     * @return Builder<WorkflowEvent>
     */
    private function query(
        User $user,
        Organization $organization,
        CarbonInterface $from,
        CarbonInterface $to,
        ?string $workflowUuid,
        ?string $eventType,
        ?string $actor,
        ?string $instanceUuid,
    ): Builder {
        $visible = WorkflowInstance::query()
            ->select('workflow_instances.id')
            ->where('workflow_instances.organization_id', $organization->id);

        if ($workflowUuid !== null) {
            $visible->whereIn('workflow_instances.workflow_id', function ($workflows) use ($organization, $workflowUuid): void {
                $workflows->select('id')
                    ->from('workflows')
                    ->where('organization_id', $organization->id)
                    ->where('uuid', $workflowUuid);
            });
        }

        $this->eligibility->constrainInstances($visible, $user, $organization);

        $query = WorkflowEvent::query()
            ->where('workflow_events.organization_id', $organization->id)
            ->where('workflow_events.created_at', '>=', $from)
            ->where('workflow_events.created_at', '<', $to)
            ->whereIn('workflow_events.workflow_instance_id', $visible)
            ->with([
                'actor:id,name',
                'instance' => fn ($instance) => $instance->select('id', 'uuid', 'workflow_id', 'workflow_version_id', 'organization_id'),
                'instance.workflow:id,uuid,name',
            ])
            ->orderByDesc('workflow_events.id');

        if ($eventType !== null) {
            $query->where('workflow_events.event_type', $eventType);
        }

        if ($actor === 'system') {
            $query->whereNull('workflow_events.actor_id');
        } elseif ($actor !== null) {
            $actorId = OrganizationMembership::query()
                ->where('organization_id', $organization->id)
                ->where('uuid', $actor)
                ->value('user_id');
            $query->where('workflow_events.actor_id', $actorId ?? 0);
        }

        if ($instanceUuid !== null) {
            $query->whereIn('workflow_events.workflow_instance_id', WorkflowInstance::query()
                ->select('id')
                ->where('organization_id', $organization->id)
                ->where('uuid', $instanceUuid));
        }

        return $query;
    }
}
