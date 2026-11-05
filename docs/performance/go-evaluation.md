# Performance baseline and Go evaluation

Decision: omit a Go prototype/service. No eligible deterministic workload meets the CPU gate. Local component, authenticated HTTP and durable-queue measurements are recorded below; these are not production load evidence.

## Reproduction and limitations

Before optimization, `php artisan flowmv:benchmark --samples=10000 --env=phase9d` measured deterministic normalization and numeric conditions. Raw results are in ignored `backend/storage/app/phase10-audit/baseline-deterministic.json`. Host: Windows, Intel Family 6 Model 183, 24 logical processors, 16,944,848,896 bytes physical RAM, PHP 8.4.23, Node 22.19, PostgreSQL 18. Hardware is shared with desktop processes and Docker activity, not an isolated load generator. Database target for subsequent profiles is the new loopback owned `flowmv_phase10_demo`, port 55490, 100 synthetic requests. No hosted workload is measured.

Run `php artisan demo:profile --samples=100 --env=demo` from backend. It checks ownership, performs three warmups per workload, uses concurrency one and records only aggregate times/query counts, never SQL bindings or messages. Raw profile is `backend/storage/app/phase10-audit/profile-100.json`. This invokes real in-process services, not authenticated HTTP or production workers. SQL time is Laravel driver time; wall minus SQL includes application and client/OS waiting and is **not CPU**. CPU uses getrusage user+system where supported, with coarse native timer resolution. No transport delay was injected. DOCX is a small synthetic sample; larger documents need separate workloads.

| Workload | Samples | p50 ms | p95 ms | Throughput/s | SQL total ms | Queries | PHP CPU total ms |
| --- | --- | --- | --- | --- | --- | --- | --- |
| Admin analytics, 100 requests | 100 | 32.4961 | 54.4158 | 27.10 | 2208.780 | 1600 | 1875.000 |
| Leave definition normalization | 100 | 0.3389 | 0.4966 | 2754.59 | 0 | 0 | 46.875 |
| Real synthetic DOCX extraction | 100 | 0.8231 | 1.5072 | 1052.51 | 0 | 0 | 46.875 |
| Verified duplicate Slack ingress | 100 | 15.8603 | 22.9067 | 60.00 | 1200.610 | 1400 | 687.500 |

Process peak was 34 MiB. These are repeated bounded synthetic workloads, with no HTTP throttling surface; authenticated API measurements must report 429s separately. The 10,000-sample deterministic baseline had normalization p95 0.0514 ms/p99 0.0977 ms and numeric condition p95 0.0051 ms/p99 0.0075 ms. Different definitions, process state and host contention make those two runs unsuitable as before/after speedup evidence. p99 is omitted for all workloads below 10,000 successful samples.

## Eligibility gate

Measured deterministic normalization and DOCX extraction each account for about 1.8% of combined measured PHP CPU, far below the required 25%. Most measured CPU is in analytics and verified ingestion, which include database and authorization/state processing and are ineligible to move into an independent Go workload. This mixture is illustrative, not a production CPU attribution model. It provides no qualifying extraction candidate. Do not move authentication, authorization, vault decryption, workflow state or audit into a service for a benchmark.

Decision: omit a Go prototype. No communication-inclusive evidence demonstrates 2× throughput **and** 20% lower p95 with equal security outcomes. A Go installation is not an adoption reason. Runtime checks and audit were preserved; no cross-request/job authorization cache was added. Any future eligible prototype needs private authenticated transport, bounded payload/concurrency, deadlines, shutdown handling and fail-closed behavior.

## Authenticated API and queue measurements

`node scripts/profile-demo-http.mjs native` and its `container` profile use generated synthetic accounts with ordinary session login and CSRF. Each endpoint has 20 successful samples at concurrency two; all returned 200 and no 429s were excluded. PHP's development server serializes work. These small samples describe local responsiveness; no p99 or production concurrency assertion is justified.

| Target / endpoint | p50 ms | p95 ms | Successful/s |
| --- | --- | --- | --- |
| Native request list | 622.715 | 1094.989 | 2.22 |
| Native analytics | 557.627 | 998.736 | 2.14 |
| Docker request list | 133.710 | 169.260 | 12.53 |
| Docker analytics | 167.133 | 258.110 | 10.10 |

Raw native output is `profile-http-native.json`; subsequent captures use unique timestamped names to preserve evidence. Container raw output uses `profile-http-container-*.json` under the ignored audit directory. The container uses its own database and built Linux application; host/filesystem/runtime differences make this **not** a before/after code optimization comparison. In-process SQL attribution is separate from HTTP boot, session, serialization and transport overhead.

`php artisan demo:queue-profile --env=demo --jobs=40` dispatched 40 database jobs onto a unique queue and ran a dedicated bounded worker. Each job rechecked demo ownership, used a simulated scanner and ran the real synthetic DOCX parser. All 40 completed; no workflow/request/audit rows or provider operations were created. Total 3048.111 ms included insertion and worker startup/shutdown, giving 13.12 jobs/s at concurrency one. Per-job processing p50/p95 was 8.957/12.724 ms; queue-wait p50/p95 2395.368/2731.184 ms; enqueue-to-completion p50/p95 2403.753/2739.803 ms. Aggregated measured worker CPU was 156.25 ms, with 34 MiB peak PHP allocated memory. That is not OS RSS, and CPU excludes process boot and has coarse timer resolution. No injected provider delay or p99 is reported. Initial raw output is `queue-profile-native.json`; future captures have unique run IDs.

## Optimization assessment

Most native HTTP time is outside the profiled deterministic evaluators. SQL and authorized aggregation/ingestion dominate the measured service workloads; their security/state coupling makes them ineligible for an independent Go service. The native/container gap also shows substantial environment overhead. No demonstrated parser/condition bottleneck justifies a microservice or speculative rewrite. Existing SQL visibility aggregation and batched provider metadata were retained; no permission checks or audit were removed. Production-sized SQL plans, cold/warm HTTP measurements and external transport delays require a separately controlled load environment before choosing a further optimization. This is an explicit rejection of Go for this evidence set, not a claim that all production bottlenecks are solved.
