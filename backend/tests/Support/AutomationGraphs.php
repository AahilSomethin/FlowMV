<?php

namespace Tests\Support;

use Illuminate\Support\Str;

class AutomationGraphs
{
    /**
     * @param  array<string, mixed>  $notification
     * @param  list<array<string, mixed>>  $fields
     * @return array{graph_definition: array<string, mixed>, form_definition: array<string, mixed>}
     */
    public static function notification(array $notification = [], array $fields = []): array
    {
        $start = (string) Str::uuid();
        $notify = (string) Str::uuid();
        $end = (string) Str::uuid();

        return [
            'graph_definition' => [
                'nodes' => [
                    WorkflowGraphs::node($start, 'start', 0, 0, ['label' => 'Start', 'description' => '']),
                    WorkflowGraphs::node($notify, 'notification', 220, 0, array_replace([
                        'label' => 'Notify',
                        'description' => '',
                        'channels' => ['in_app', 'email'],
                        'recipient_roles' => ['manager'],
                        'include_submitter' => true,
                        'include_task_participants' => false,
                        'allowed_field_ids' => [],
                        'subject' => 'Update for {{workflow.name}}',
                        'body' => 'Instance {{instance.uuid}}',
                    ], $notification)),
                    WorkflowGraphs::node($end, 'end', 440, 0, [
                        'label' => 'Done',
                        'description' => '',
                        'result' => 'completed',
                    ]),
                ],
                'edges' => [
                    WorkflowGraphs::edge($start, $notify, 'out'),
                    WorkflowGraphs::edge($notify, $end, 'out'),
                ],
            ],
            'form_definition' => ['fields' => $fields],
        ];
    }

    /**
     * @param  array<string, mixed>  $assignment
     * @param  list<array<string, mixed>>  $fields
     * @return array{graph_definition: array<string, mixed>, form_definition: array<string, mixed>}
     */
    public static function assignment(array $assignment = [], array $fields = []): array
    {
        $start = (string) Str::uuid();
        $assign = (string) Str::uuid();
        $end = (string) Str::uuid();

        return [
            'graph_definition' => [
                'nodes' => [
                    WorkflowGraphs::node($start, 'start', 0, 0, ['label' => 'Start', 'description' => '']),
                    WorkflowGraphs::node($assign, 'assignment', 220, 0, array_replace([
                        'label' => 'Do work',
                        'description' => '',
                        'assignee_mode' => 'role',
                        'assignee_roles' => ['manager'],
                        'assignee_user_id' => null,
                        'exclude_submitter' => true,
                        'instructions' => 'Review the request.',
                    ], $assignment)),
                    WorkflowGraphs::node($end, 'end', 440, 0, [
                        'label' => 'Done',
                        'description' => '',
                        'result' => 'completed',
                    ]),
                ],
                'edges' => [
                    WorkflowGraphs::edge($start, $assign, 'out'),
                    WorkflowGraphs::edge($assign, $end, 'out'),
                ],
            ],
            'form_definition' => ['fields' => $fields],
        ];
    }

    /**
     * @param  array<string, mixed>  $delay
     * @param  list<array<string, mixed>>  $fields
     * @return array{graph_definition: array<string, mixed>, form_definition: array<string, mixed>}
     */
    public static function delay(array $delay = [], array $fields = []): array
    {
        $start = (string) Str::uuid();
        $wait = (string) Str::uuid();
        $end = (string) Str::uuid();

        return [
            'graph_definition' => [
                'nodes' => [
                    WorkflowGraphs::node($start, 'start', 0, 0, ['label' => 'Start', 'description' => '']),
                    WorkflowGraphs::node($wait, 'delay', 220, 0, array_replace([
                        'label' => 'Wait',
                        'description' => '',
                        'resume_mode' => 'duration',
                        'duration_seconds' => 60,
                        'resume_at' => null,
                    ], $delay)),
                    WorkflowGraphs::node($end, 'end', 440, 0, [
                        'label' => 'Done',
                        'description' => '',
                        'result' => 'completed',
                    ]),
                ],
                'edges' => [
                    WorkflowGraphs::edge($start, $wait, 'out'),
                    WorkflowGraphs::edge($wait, $end, 'out'),
                ],
            ],
            'form_definition' => ['fields' => $fields],
        ];
    }
}
