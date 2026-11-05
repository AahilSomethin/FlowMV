<?php

namespace App\Console\Commands;

use App\Support\EnvironmentChecker;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class CheckProductionReadiness extends Command
{
    protected $signature = 'operations:check {--runtime : Read database privileges and worker/scheduler liveness} {--runtime-only : Inspect runtime without certifying production configuration}';

    protected $description = 'Report safe production configuration findings; passing is not release authorization';

    public function handle(): int
    {
        $findings = $this->option('runtime-only') ? [] : EnvironmentChecker::productionFindings();
        $runtime = [];
        if ($this->option('runtime') || $this->option('runtime-only')) {
            try {
                foreach (['scheduler', 'worker'] as $name) {
                    $at = Cache::get('operations.'.$name);
                    $runtime[$name] = is_int($at) && $at <= time() && $at >= time() - 180;
                    if (! $runtime[$name]) {
                        $findings[] = $name.': fresh heartbeat required';
                    }
                }
                $role = DB::selectOne('select r.rolsuper, r.rolcreatedb, r.rolcreaterole, r.rolbypassrls, (d.datdba = r.oid) as owns_database from pg_roles r cross join pg_database d where r.rolname = current_user and d.datname = current_database()');
                foreach (['rolsuper', 'rolcreatedb', 'rolcreaterole', 'rolbypassrls', 'owns_database'] as $privilege) {
                    if ($role->$privilege) {
                        $findings[] = 'Database runtime role is privileged';
                        break;
                    }
                }
                foreach (['workflow_versions', 'workflow_events', 'workflow_submission_snapshots', 'integration_audit_events'] as $table) {
                    $grant = DB::selectOne("select has_table_privilege(current_user, ?, 'UPDATE,DELETE,TRUNCATE') as mutable", [$table]);
                    if ($grant->mutable) {
                        $findings[] = 'Immutable table runtime privilege: '.$table;
                    }
                }
                $runtime['queued_jobs'] = DB::table('jobs')->count();
                $runtime['failed_jobs'] = DB::table('failed_jobs')->count();
            } catch (\Throwable) {
                $findings[] = 'Runtime inspection unavailable';
            }
        }
        $this->line(json_encode(['result' => $findings === [] ? 'checks_passed_not_release_clearance' : 'not_cleared', 'findings' => array_values(array_unique($findings)), 'runtime' => $runtime, 'external_gates' => ['live providers', 'restore ownership', 'scanner and PDF isolation', 'operational approval']], JSON_THROW_ON_ERROR));

        return $findings === [] ? self::SUCCESS : self::FAILURE;
    }
}
