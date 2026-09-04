# ADR 0021: GitHub Flow Branching and CI Quality Gates

* **Status**: Accepted
* **Date**: 2026-09-03
* **Revised**: 2026-09-04 — implemented in Phase 11; records which gates are live and which wait on later phases

## Context

The codebase requires automated quality gates to prevent regressions, type errors, style mismatches, and breaking API changes.

This record was accepted in Phase 1 and described a pipeline that did not exist. Ten phases then shipped without it. Every figure quoted at a phase gate — the suite on both engines, the architecture rules, Pint, the security scans — came from one developer machine and was believed because the person reporting it was trusted, not because anything checked. `statusCheckRollup` on PR #8 and PR #9 was empty, and `MERGEABLE` on a pull request is a statement about conflicts and nothing else.

What that cost became visible while preparing this phase. Both Dockerfiles had begun with `ROM` instead of `FROM` since Phase 1: `docker compose build` failed at parse time, on `main`, for ten consecutive phases. Nothing caught it because the containers running the tests were built before the mistake and nobody rebuilt from a clean checkout. A green suite says nothing about whether the image that ran it can still be produced.

## Decision

GitHub Flow stands: protected `main`, short-lived feature branches, pull requests.

The pipeline runs the project's own images — the same `docker/backend/Dockerfile` that builds the development containers — rather than provisioning a PHP onto the runner. The faster option was available and says less: an extension difference would make CI's green a different statement from the local one, and a gap of exactly that kind is what let the Dockerfile defect survive.

Every check is defined once, in `scripts/gate.sh`, which developers and the workflow both invoke. CI is not permitted to be a second description of the gate, because two descriptions drift until the two greens no longer mean the same thing.

Live as of Phase 11:

- **Docker build from scratch**, with `--no-cache --pull`, followed by assertions that the image runs and carries the extensions the application needs. The flags are the substance of this gate: a cached layer or a stale local image would hide the failure it exists to catch.
- **Pest** — the full suite on PostgreSQL and on SQLite, and the architecture rules.
- **Engine assertion.** `phpunit.xml` falls back to SQLite when no database environment is present, so a job labelled PostgreSQL could pass without ever reaching PostgreSQL. Each suite job declares `EXPECTED_DB_DRIVER` and the suite fails on a mismatch.
- **Pint**, style only, rewriting nothing.
- **Whitespace**, over the pull request's range rather than the working tree.
- **Secret scan**, versioned in the repository, which proves its own patterns and its own file walk before reporting and fails as `UNPROVEN` rather than clean when it cannot.
- **Static analysis** — Larastan at level 5, adopted in the follow-up phase after this one. Level 8, which this record originally named, reports 96 errors against this codebase; level 5 reported 31 and every one was fixed rather than baselined. What level 8 still reports stays open in ADR 0029 item 5.
- **Migrations and seeders from empty.**

Deferred, with the phase that unblocks each:

- **Spectral OpenAPI validation** — Phase 12. There is no OpenAPI document yet (ADR 0010).
- **TypeScript checks** — Phase 13. There is no frontend yet.

Branch protection settings are applied by the repository owner, not by the pipeline.

## Consequences

A pull request now carries evidence instead of a report. That is a narrower claim than the original "zero broken code can reach production", and it is the true one: the gates that exist are enforced, and the ones that do not exist are named here rather than implied by silence.

Each gate is proven by a planted failure before being trusted. A gate that has never been observed failing is not known to work — the ROM/FROM defect is the standing example of a check everyone assumed was happening, and the secret scan and the architecture rules have each produced a green over an empty set at least once in this project's history.

Running the project's images costs a build on every run and makes CI slower than the alternative. That is the price of the green meaning one thing in both places.

This record stays honest about its own history: it was accepted long before it was true, and describing an unbuilt pipeline as an accepted decision is how a gap survives ten phases without anyone deciding to accept it.
