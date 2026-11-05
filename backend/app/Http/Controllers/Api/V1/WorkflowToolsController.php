<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Workflows\CreateWorkflow;
use App\Actions\Workflows\SaveWorkflowDraft;
use App\Http\Controllers\Controller;
use App\Http\Resources\WorkflowResource;
use App\Models\Workflow;
use App\Policies\WorkflowPolicy;
use App\Services\Workflows\WorkflowPreview;
use App\Services\Workflows\WorkflowTemplates;
use App\Support\ActiveMembershipLocator;
use App\Support\WorkflowLocator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class WorkflowToolsController extends Controller
{
    public function templates(Request $request, ActiveMembershipLocator $memberships, WorkflowTemplates $templates): JsonResponse
    {
        $org = $memberships->organizationFrom($request);

        return response()->json(['data' => ['version' => $templates::VERSION, 'items' => $templates->catalog(), 'can_create' => $request->user()->can('create', [Workflow::class, $org])]]);
    }

    public function instantiate(Request $request, string $organization, string $template, ActiveMembershipLocator $memberships, WorkflowTemplates $templates): JsonResponse
    {
        $org = $memberships->organizationFrom($request);
        $this->authorize('create', [Workflow::class, $org]);
        $d = $request->validate(['name' => 'required|string|max:120', 'bindings' => 'present|array|max:4']);
        $bindings = $templates->bind($request->user(), $org, $template, $d['bindings']);
        $definition = $templates->definition($template, $bindings);
        $workflow = DB::transaction(function () use ($request, $org, $template, $templates, $d, $definition) {
            $w = app(CreateWorkflow::class)($request->user(), $org, $d['name'], $templates->recipe($template)[2]);

            return app(SaveWorkflowDraft::class)($request->user(), $w, 1, $definition['graph_definition'], $definition['form_definition']);
        });
        $workflow->setAttribute('viewer_role', app(WorkflowPolicy::class)->role($request->user(), $org));
        $workflow->setAttribute('present_definition', true);

        return (new WorkflowResource($workflow))->response()->setStatusCode(201);
    }

    public function preview(Request $request, WorkflowLocator $locator, WorkflowPreview $preview): JsonResponse
    {
        $workflow = $locator->workflowFrom($request);
        $this->authorize('update', $workflow);
        abort_unless(array_diff(array_keys($request->all()), ['graph_definition', 'form_definition', 'scenario', 'preview_inputs', 'version_uuid']) === [], 422);
        $private = $request->attributes->get('integration_input');
        $versionId = $request->input('version_uuid');
        if ($versionId !== null) {
            abort_unless(is_string($versionId) && $private->get('graph_definition') === null && $private->get('form_definition') === null, 422);
            $version = $locator->version($workflow, $versionId);
            $definition = ['graph_definition' => $version->graph_definition, 'form_definition' => $version->form_definition];
        } else {
            $graph = $private->get('graph_definition');
            $form = $private->get('form_definition');
            if ($graph === null && $form === null) {
                $draft = $workflow->draft()->firstOrFail();
                $definition = ['graph_definition' => $draft->graph_definition, 'form_definition' => $draft->form_definition];
            } else {
                abort_unless(is_array($graph) && is_array($form), 422);
                $definition = ['graph_definition' => $graph, 'form_definition' => $form];
            }
        }
        $inputs = $private->get('preview_inputs', []);
        $scenario = $private->get('scenario', []);
        abort_unless(is_array($inputs) && is_array($scenario), 422);

        return response()->json(['data' => $preview->run($request->user(), $workflow, $definition, $inputs, $scenario)])->header('Cache-Control', 'no-store, private');
    }

    public function compare(Request $request, WorkflowLocator $locator): JsonResponse
    {
        $workflow = $locator->workflowFrom($request);
        $this->authorize('view', $workflow);
        $data = $request->validate(['from' => 'required|uuid', 'to' => 'required|uuid']);
        $before = $locator->version($workflow, $data['from']);
        $after = $locator->version($workflow, $data['to']);
        $left = collect($before->graph_definition['nodes'])->keyBy('id');
        $right = collect($after->graph_definition['nodes'])->keyBy('id');
        $changes = [];
        foreach ($left->keys()->merge($right->keys())->unique() as $id) {
            $a = $left->get($id);
            $b = $right->get($id);
            if ($a === null || $b === null) {
                $changes[] = ['node_id' => $id, 'kind' => $a === null ? 'added' : 'removed', 'type' => ($b ?? $a)['type'], 'fields' => []];

                continue;
            }
            $fields = [];
            foreach (array_unique(array_merge(array_keys($a['data']), array_keys($b['data']))) as $key) {
                if (($a['data'][$key] ?? null) != ($b['data'][$key] ?? null)) {
                    $fields[] = $key;
                }
            }
            if ($a['type'] !== $b['type'] || $fields !== [] || $a['position'] != $b['position']) {
                $changes[] = ['node_id' => $id, 'kind' => $a['type'] !== $b['type'] ? 'type_changed' : ($fields !== [] ? 'configuration_changed' : 'moved'), 'type' => $b['type'], 'fields' => $fields];
            }
        }
        $edges = fn ($v) => collect($v->graph_definition['edges'])->map(fn ($e) => implode(':', [$e['source'], $e['source_handle'], $e['target'], $e['target_handle']]))->sort()->values()->all();
        $form = fn ($v) => collect($v->form_definition['fields'])->map(fn ($f) => array_intersect_key($f, array_flip(['id', 'type', 'required', 'validation', 'options'])))->all();

        return response()->json(['data' => ['from' => $before->version_number, 'to' => $after->version_number, 'changes' => $changes, 'connections_changed' => $edges($before) !== $edges($after), 'form_structure_changed' => $form($before) != $form($after), 'notice' => 'Structural comparison only. Message text, submission values and credential references are not returned.']])->header('Cache-Control', 'no-store, private');
    }
}
