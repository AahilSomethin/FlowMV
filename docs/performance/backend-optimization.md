# Backend optimization evidence

Phase 12 evidence is recorded in the [acceptance ledger](../phase12-final-acceptance.md). Host: Windows, Intel Core i7-14650HX, Node 22.19.0, PHP 8.4.23; native PostgreSQL is the owned local port 55490 target, never hosted. Go module uses 1.27.3. Measurements use synthetic data and no injected provider delay.

The initial HTTP workload runs `node scripts/profile-demo-http.mjs native`, with two concurrent clients, 20 samples per endpoint and normal session/RBAC/rate controls. Requests p95 3458.761 ms and analytics p95 2425.615 ms include transport, serialized development-server waiting and application processing. They cannot attribute this gap to PHP CPU. No remote PostgreSQL timing claim is made from the local target.

`php artisan demo:profile --env=demo --samples=40 --no-interaction` measures services after three warmups. Unfiltered analytics originally made 16 queries per call. Reusing its existing authorized workflow aggregate removes one query, giving 15; the HTTP regression counts 19 to 18 including middleware. Filtered rates still query their full authorized terminal cohort. No membership check was removed, and no authorization result is cached across requests/jobs.

Baseline service p50/p95: 31.3099/34.3389 ms; after: 49.554/64.2894 ms. SQL totals: 745.86 ms versus 1215.98 ms across 40 calls. Normalization, DOCX and replay workloads also slowed on this shared host. Thus the query-count reduction is verified; latency improvement is **not established**. Preserve both raw runs rather than selecting a favorable sample. No new index is justified by this small dataset; inspect production-scale plans before proposing one.

Existing PHP extraction remains in PHP. The [Phase 11 comparison](go-migration-results.md) rejected its migration, and this phase adds no domain responsibilities to Go. New Go changes concern TLS healthcheck correctness and fail-closed startup, not a claimed CPU optimization. More than 10,000 successes are required before reporting p99.

## End-to-end Go and queue evidence

Phase 12's signed HTTP comparison used 80 synthetic, matching extraction samples. PHP p50/p95 was 0.1151/0.2318 ms (7404.733 operations/s). Go including readiness, signing, JSON and HTTP was 8.2023/36.3874 ms (54.281 operations/s). There were zero SQL queries, provider calls or throttles. These small deterministic samples support retaining PHP extraction; they do not benchmark paid inference or prove an overall Go service capacity limit. Go continues to own bounded advisory AI orchestration.

The guarded queue profile processed 40/40 real DOCX extraction jobs with a simulated scanner, concurrency one: 1756.877 ms wall time, 22.77 jobs/s. Processing p50/p95 was 6.0484/12.7646 ms; queue wait 1253.8011/1477.1891 ms; enqueue-to-completion 1261.2202/1482.5721 ms. Worker CPU was 203.125 ms and peak PHP allocated memory 35,651,584 bytes (not operating-system RSS). The workload wrote no domain/audit records and made no provider calls. Queue waiting dominates this deliberately serial batch; do not compare it to provider/network latency or multiply it into an unmeasured concurrency claim.

The release candidate bounds PHP services at 512 MiB/two CPUs, Go at 256 MiB/two CPUs with a 192 MiB Go memory limit, and web at 768 MiB/two CPUs. These are configured limits, not measured production requirements. Its heartbeat records enqueue time, so a delayed queue cannot manufacture a fresh worker signal.

## PostgreSQL review

No Phase 12 schema migration or new index was introduced. Existing foreign keys, unique/deduplication constraints, organization indexes and protected immutable tables remain in place. The guarded PostgreSQL suite checks concurrent decisions, stale authorization, runtime roles and queued integration/AI races. Existing database-backed queues and bounded application clients remain; no unmeasured pooler or cross-request authorization cache was added.

A read-only staging `EXPLAIN (ANALYZE, BUFFERS)` on the organization-scoped request page used `workflow_instances_org_workflow_status_idx`, then a 25 kB quicksort; one synthetic row returned in 1.396 ms with eight shared buffer hits. This tiny local dataset cannot establish production selectivity, remote RTT, lock behavior at scale or justify index write overhead. An attempted task query using `tasks` was rejected because the actual table is `workflow_tasks`; no write occurred. Production-scale plans, growth/retention and remote database topology require operator workload evidence before further tuning.

The corrected empty task-page plan used `workflow_tasks_org_type_completed_idx`, seven shared buffer hits and 0.214 ms execution. There were zero lock-waiting sessions at that inspection instant. Empty/single-row plans do not prove scaled inbox performance or absence of contention under load.

Native bounded microbenchmarks (`go test -run '^$' -bench . -benchmem -benchtime=500ms -cpu=1,4 ./internal/ai ./internal/document`) measured simulated advisory at 26,634 / 9,094 ns/op (one/four CPUs), about 11.7 kB and 63 allocations/op; DOCX extraction at 404,566 / 401,893 ns/op, 57,936 B and five allocations/op. No provider inference occurs. These are current microbenchmarks, not before/after proof or HTTP throughput.
