<?php

namespace App\Services\AI;

use Illuminate\Support\Facades\DB;

class AiBudget
{
    /** The caller holds the organization policy lock for every reservation. Units are conservative reservations, not currency. */
    public function reserve(object $run, object $policy, object $connection, int $units): void
    {
        abort_unless(DB::transactionLevel() > 0 && $units > 0, 503);
        $day = now()->utc()->toDateString();
        $base = DB::table('ai_usage_reservations')->where('organization_id', $run->organization_id)->where('utc_day', $day);
        foreach ([['organization_id', $run->organization_id, $policy->daily_organization_units], ['actor_user_id', $run->actor_user_id, $policy->daily_user_units], ['workflow_id', $run->workflow_id, $policy->daily_workflow_units], ['connection_id', $run->connection_id, $connection->daily_units]] as [$column, $id, $limit]) {
            abort_if((clone $base)->where($column, $id)->sum('reserved_units') + $units > $limit, 429, 'AI processing budget exhausted.');
        }
        DB::table('ai_usage_reservations')->insert(['run_id' => $run->id, 'organization_id' => $run->organization_id, 'actor_user_id' => $run->actor_user_id, 'workflow_id' => $run->workflow_id, 'connection_id' => $run->connection_id, 'utc_day' => $day, 'reserved_units' => $units, 'state' => 'reserved', 'created_at' => now(), 'updated_at' => now()]);
    }
}
