# Manual test plan

Status: **Not executed.** Use a disposable single-site WordPress 6.2+ environment with PHP 8.2+, MySQL 8.4, and a registered `property` post type. Record exact versions, database prefix, source revision, date, tester, actual result, and evidence for every case. Repeat installation with a non-default prefix.

| Case | Action | Expected result | Actual / evidence |
| --- | --- | --- | --- |
| Activation | Activate on supported single-site WordPress | Table, columns, indexes, and schema option created | Pending |
| Reinstallation | Deactivate, reactivate with existing leads | No lead loss | Pending |
| Unsupported runtime | Attempt activation on incompatible PHP/WordPress or multisite network | Controlled failure | Pending |
| Missing property type | Attempt insert before registering `property` | `hm_invalid_property` | Pending |
| Wrong property | Insert using absent ID or a post of another type | `hm_invalid_property` | Pending |
| CRUD | Insert, read, update fields, update status/budget, delete | Correct values and affected counts | Pending |
| Invalid fields | Empty/long/multibyte name, unknown field, invalid ID, negative/oversized budget, unknown status | `hm_invalid_input`; no write | Pending |
| Missing rows | Read/update/delete absent positive ID | `null` or `0` according to method | Pending |
| Filters | Status, minimum budget, both, zero budget, no matches | Correct items and total | Pending |
| Pagination | Equal timestamps, pages 1/end/beyond, size 100/101, invalid/overflow page | Stable order and correct errors | Pending |
| Injection | Submit SQL metacharacters in values, IDs, order and unknown keys | No query structure change | Pending |
| Database failure | Deny read/write or rename table in disposable site | Safe `WP_Error`; version not falsely advanced | Pending |
| Indexes | Run `SHOW INDEX`, `EXPLAIN`, `EXPLAIN ANALYZE` for representative filters | Record actual plans and selectivity | Pending |
| Migration | Simulate older version and failed installation | Retry safe, data preserved, version only advances on success | Pending |
| Deactivation | Deactivate with leads present | Data preserved | Pending |
| Default uninstall | Uninstall without cleanup preference | Leads and schema retained | Pending |
| Opt-in uninstall | Explicitly enable cleanup in disposable site, uninstall | Only plugin table/options removed | Pending |
| Benchmark | Seed 5,000 pairs, run measurements, inspect SQL/plans, clean up | Matching results and recorded measurements | Pending |
| Memory | Run isolated samples with peak reset | Delta and peak recorded in bytes | Pending |

The `property` post type must be supplied by another plugin/theme and registered before repository writes. If absent, writes fail gracefully; table installation does not depend on the post type.
