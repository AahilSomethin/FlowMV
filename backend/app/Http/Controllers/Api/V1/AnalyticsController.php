<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Analytics\AnalyticsWindowRequest;
use App\Services\Analytics\OrganizationAnalytics;
use App\Support\CsvCell;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AnalyticsController extends Controller
{
    public function show(AnalyticsWindowRequest $request, OrganizationAnalytics $analytics): \Illuminate\Http\JsonResponse
    {
        return response()->json([
            'data' => $analytics->summary(
                $request->user(),
                $request->organization(),
                $request->windowStart(),
                $request->windowEnd(),
                $request->workflowUuid(),
                $request->statusFilter(),
            ),
        ]);
    }

    public function export(AnalyticsWindowRequest $request, OrganizationAnalytics $analytics): StreamedResponse
    {
        $export = $analytics->workflowExport(
            $request->user(),
            $request->organization(),
            $request->windowStart(),
            $request->windowEnd(),
            $request->workflowUuid(),
            $request->statusFilter(),
            2000,
        );

        return $this->csv($export['rows'], $export['truncated']);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function csv(array $rows, bool $truncated): StreamedResponse
    {
        return response()->streamDownload(function () use ($rows): void {
            $out = fopen('php://output', 'w');
            fputcsv($out, [
                'workflow',
                'started',
                'completed',
                'rejected',
                'in_progress',
                'failed',
                'completion_rate',
                'median_completion_seconds',
            ]);

            foreach ($rows as $row) {
                fputcsv($out, [
                    CsvCell::text($row['name']),
                    $row['started'],
                    $row['completed'],
                    $row['rejected'],
                    $row['in_progress'],
                    $row['failed'],
                    $row['completion_sufficient'] ? $row['completion_rate'] : '',
                    $row['median_completion_seconds'] ?? '',
                ]);
            }

            fclose($out);
        }, 'flowmv-analytics.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'X-Export-Truncated' => $truncated ? 'true' : 'false',
        ]);
    }
}
