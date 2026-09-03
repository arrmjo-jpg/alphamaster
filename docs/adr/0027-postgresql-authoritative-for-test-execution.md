# ADR 0027: PostgreSQL Is Authoritative for Test Execution

* **Status**: Accepted
* **Date**: 2026-09-03

## Context

`backend/phpunit.xml` declares `DB_CONNECTION=sqlite` and `DB_DATABASE=:memory:`. Inside the Docker stack those declarations do not take effect: PHPUnit `<env>` entries are not marked `force="true"`, so the real environment variables supplied by the `backend` container's `env_file` win. The result is that the same command resolves to two different engines:

* `vendor/bin/pest` on the host → SQLite in-memory.
* `docker compose exec backend php artisan test` → PostgreSQL 17.

This was discovered during the Phase 4 gate review. It is not a defect on its own, but it was undocumented, and it interacts with ADR 0003, which makes PostgreSQL 17 the sole production engine and explicitly abandons generic multi-database portability.

The divergence has real consequences. PostgreSQL aborts an entire transaction when a constraint is violated (`SQLSTATE[25P02]`), while SQLite lets the transaction continue. Four database-level constraint tests passed on SQLite and failed on PostgreSQL for exactly this reason. Engine-specific behaviour — CHECK constraints, partial indexes, transaction abort semantics — is only ever proven on PostgreSQL.

## Decision

PostgreSQL is authoritative for test results. A change is considered verified only when `docker compose exec backend php artisan test` passes; a green SQLite run is a convenience signal, never a gate.

`phpunit.xml` is deliberately left unchanged. Removing the SQLite defaults would break the fast host-side loop for contributors without a running Docker stack, and adding `force="true"` would make the container run SQLite too, destroying the only PostgreSQL coverage the suite has. The file's SQLite values are therefore understood as the host fallback, not as the project's target engine.

Because SQLite is a fallback rather than a supported engine, application code does not acquire portability layers to satisfy it. Where an invariant must exist in both places — such as the `settings` table CHECK constraints — the PostgreSQL implementation is the real one and the SQLite implementation exists solely so the local harness can exercise the same behaviour. Any driver other than `pgsql` or `sqlite` raises rather than silently skipping the invariant.

## Consequences

CI must run the suite through the Docker stack against PostgreSQL; a host-only SQLite run is insufficient to merge. Tests that assert database-level behaviour must be written so they hold under PostgreSQL transaction semantics, which generally means wrapping an expected constraint violation in a nested transaction (a `SAVEPOINT`) so that subsequent assertions can still query. Contributors keep a fast host-side loop, at the cost of remembering that a green host run has not proven engine-specific behaviour.
