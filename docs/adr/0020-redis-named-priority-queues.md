# ADR 0020: Redis Named Priority Queues

* **Status**: Accepted
* **Date**: 2026-09-03

## Context

Critical jobs (auth, OTP) must not be starved behind long-running tasks (image conversions, external API sync).

## Decision

Organize Redis queues by priority: critical (30s), default (60s), notifications (30s), media (300s), integrations (60s), audit (30s). Job configurations follow Laravel Boost rules.

## Consequences

Prevents queue starvation and ensures low-latency execution for user-facing tasks.
