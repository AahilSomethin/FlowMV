# Phase 11 migration measurements

New AI orchestration is Go-owned functionality. It is **not** a measured acceleration of existing Laravel code. The existing PHP extraction path was retained after testing a coherent pure extracted-text matcher through the actual signed internal HTTP client.

## Reproduction

On the owned native demo, start the native Go service and run `php artisan demo:ai-benchmark --env=demo --samples=80 --no-interaction` from backend. The command verifies the exact demo database/installation and simulated mode. It performs no provider inference or domain writes; private aggregate results use unique filenames under ignored `backend/storage/app/phase11-audit`.

Measured 2026-11-04 on the same Windows workstation described in the [Phase 10 baseline](go-evaluation.md): PHP 8.4.23, Go 1.27.3, concurrency one, 20 fields, 64,560 synthetic input bytes, 80 successful samples, zero throttles, zero workload SQL queries and zero injected transport delay. Setup ownership checks are outside the timed workload. Measurements include client/OS scheduling and are not isolated production load evidence.

| Workload | p50 ms | p95 ms | Successful operations/s |
| --- | --- | --- | --- |
| PHP in-process extraction, including schema validation | 0.1802 | 0.2940 | 5,352.75 |
| Go extraction via signed HTTP, readiness, serialization and response validation | 8.9692 | 28.6278 | 77.18 |

Every successful Go result matched PHP values and uncertainty output for this bounded workload. This does not establish exhaustive parser parity. The communication-inclusive candidate is substantially slower. No 2× throughput / 20% lower p95 benefit exists, and Phase 10 did not establish a qualifying CPU bottleneck. Do not adopt this migration. PHP remains the rollback-free default because execution never switched away from it.

## Candidate decisions

Event authentication/activation, integration outboxes, authorized analytics and read-only domain endpoints stay with Laravel. Their existing permission/state/SQL boundaries are valuable and no sound measured migration benefit was demonstrated. Go instead owns new provider-independent advisory operations, fixed-host provider adapters, strict output handling and bounded execution. See the [migration audit](../architecture/go-service.md).

No p99 is reported with fewer than 10,000 successful samples. No CPU reduction, worker RSS improvement, live-provider throughput or production capacity is claimed. Go race/concurrency correctness checks and native/container scenario evidence are recorded separately in the [acceptance ledger](../phase11-ai-go-acceptance.md). Production-scale queue, provider-delay and resource benchmarks remain separate operational work.
## New Go domain microbenchmarks

A separate 2026-11-05 native run used `go test -run '^$' -bench . -benchmem -benchtime=500ms '-cpu=1,4' ./internal/ai ./internal/document` on Intel Core i7-14650HX, Windows amd64, Go 1.27.3. The quoted CPU argument is PowerShell-safe. These are repeated in-process synthetic operations, not REST endpoint throughput, provider processing or a before/after migration comparison.

| Benchmark | Concurrency setting | Average ns/op | Bytes allocated/op | Allocations/op |
| --- | --- | --- | --- | --- |
| Simulated advisory service | GOMAXPROCS 1, parallel harness | 38,430 | 11,774 | 63 |
| Simulated advisory service | GOMAXPROCS 4, parallel harness | 12,965 | 11,722 | 63 |
| Extracted-text matching | GOMAXPROCS 1, serial harness | 572,867 | 57,941 | 5 |
| Extracted-text matching | GOMAXPROCS 4, serial harness | 641,178 | 57,936 | 5 |

The advisory workload includes deterministic simulation and output validation on roughly 64 KiB/20 fields, excluding HTTP, HMAC, SQL and external transport. The extraction benchmark uses its own repeated-text fixture. Do not compare these different fixtures as an optimization claim. Allocation counts are not process RSS; benchmark wall time is not attributed processing CPU. No end-to-end production throughput or tail latency is inferred.
