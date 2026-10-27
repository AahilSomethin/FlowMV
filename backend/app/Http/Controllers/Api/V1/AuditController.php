<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\OrganizationRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Analytics\AuditExplorerRequest;
use App\Services\Analytics\AuditExplorer;
use App\Services\Workflows\Execution\ApprovalEligibility;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AuditController extends Controller
{
    public function index(
        AuditExplorerRequest $request,
        AuditExplorer $audit,
        ApprovalEligibility $eligibility,
    ): \Illuminate\Http\JsonResponse {
        $organization = $request->organization();
        $admin = $eligibility->role($request->user(), $organization->id) === OrganizationRole::Admin;
        $page = $audit->page(
            $request->user(),
            $organization,
            $request->windowStart(),
            $request->windowEnd(),
            $request->workflowUuid(),
            $request->eventType(),
            $request->actorFilter(),
            $request->instanceUuid(),
            max(1, (int) $request->input('page', 1)),
            $request->perPage(),
        );

        return response()->json([
            'data' => [
                'timezone' => 'UTC',
                'scope' => $admin ? 'organization' : 'visible_requests',
                'scope_label' => $admin ? 'Organization-wide' : 'Limited to requests you can view',
                'items' => $audit->present($page->items()),
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'total' => $page->total(),
            ],
        ]);
    }

    public function export(AuditExplorerRequest $request, AuditExplorer $audit): StreamedResponse
    {
        $export = $audit->export(
            $request->user(),
            $request->organization(),
            $request->windowStart(),
            $request->windowEnd(),
            $request->workflowUuid(),
            $request->eventType(),
            $request->actorFilter(),
            $request->instanceUuid(),
        );

        return response()->streamDownload(function () use ($export): void {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['created_at', 'event_type', 'workflow', 'instance', 'node', 'actor', 'payload']);

            foreach ($export['rows'] as $row) {
                fputcsv($out, $row);
            }

            fclose($out);
        }, 'flowmv-audit.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'X-Export-Truncated' => $export['truncated'] ? 'true' : 'false',
        ]);
    }
}
