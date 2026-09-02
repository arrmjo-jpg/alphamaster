# ADR 0025: Horizon as Sole Queue Supervisor Daemon

* **Status**: Accepted
* **Date**: 2026-09-03

## Context

Running both a standalone queue:work container and Laravel Horizon on the same Redis queue causes worker process conflicts and breaks Horizon metrics tracking.

## Decision

Laravel Horizon (php artisan horizon) is the SOLE supervisor and worker daemon for Redis queues. No standalone worker container running queue:work will be created. The horizon container manages worker processes, auto-balancing, retries, and metrics.

## Consequences

Provides clean, single-point queue management, accurate telemetry in the Horizon dashboard, and prevents process contention.
