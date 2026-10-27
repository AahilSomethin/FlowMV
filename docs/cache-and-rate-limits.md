# Cache and rate limits

Local development keeps `CACHE_STORE=file`. That store is process-local to the application server and is the correct default for a single-node Laragon or laptop setup. Phase 6 measurements showed a remote database cache adding roughly one second per limiter query, so file cache avoids that latency locally.

Production deployments with **more than one application instance** must use a shared cache store (`redis` or `database`) for both application cache and rate limiting. File cache cannot coordinate counters across instances, so login, register, invitation, and API throttles would under-enforce.

Do not switch the local default away from `file` unless you are deliberately testing a multi-instance topology. Keep `SESSION_DRIVER=database` regardless of cache store so Sanctum SPA sessions remain shared and durable.
