# Performance benchmark report

**Status: pending execution. No timing, memory, database-query, or execution-plan results have been verified.**

An isolated Docker Compose environment and CLI benchmark runner have been added under this plugin project. The host has no Docker or Podman command, so the containers, integration tests, six workloads, and execution-plan collection could not be run here. No result below should be read as a pass or failure.

Code review on 2026-11-11 found an existing benchmark helper that seeded paired records and checked matching IDs, but its WordPress branch returned IDs while the custom branch returned full rows. The WordPress branch now also loads the corresponding metadata fields during measured operations. `run_suite()` defines unfiltered, status, budget, combined, no-match, and deep-page workloads. When `SAVEQUERIES` is enabled before WordPress bootstraps, samples include summed `$wpdb` database-call seconds; collect these in a separate diagnostic pass because query logging changes overhead. **These changes have not been run against WordPress.** SQL execution plans remain pending.

## Environment to record

WordPress version: pending. PHP version: pending. MySQL version: pending. LocalWP version: pending. Hardware and OS: pending. Source revision: pending. Active plugins: pending. Table prefix, charset, collation, indexes, OPcache, InnoDB buffer-pool, and object-cache settings: pending.

## Procedure

On a disposable local site, register `property`, create one property post, instantiate `HM_Benchmark`, call `seed( $property_id )`, then `run()` for the combined status/budget scenario. The class requires `WP_DEBUG`, a `local` WordPress environment, and an administrator. The 5,000-pair fixture is generated separately and recorded in `hm_cte_benchmark_manifest`. Call `cleanup()` only after saving the report.

Each `run_suite()` workload defaults to five excluded warm-ups and 30 measured operations per implementation. It alternates custom-table and `WP_Query` order. The reported elapsed seconds include database waits. Memory deltas and process peaks are recorded in bytes; query counts use `$wpdb->num_queries`. Collect database-call times in a separate `SAVEQUERIES` profiling pass, then capture actual SQL and run `EXPLAIN` and `EXPLAIN ANALYZE` separately. Save raw samples and check post/lead ID mapping before drawing conclusions.

## Results

| Metric | Custom table | WP_Query |
| --- | --- | --- |
| Dataset size | Planned: 5,000 | Planned: 5,000 |
| Mean / median elapsed time | Pending | Pending |
| Memory delta / peak | Pending | Pending |
| Query count | Pending | Pending |
| Database call time | Pending profiling pass | Pending profiling pass |
| SQL and execution plan | Pending | Pending |

No index choice or speedup claim is based on measurements yet. Assess budget and status/budget composite indexes only after collecting representative plans and write-cost evidence.
