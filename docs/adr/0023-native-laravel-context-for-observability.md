# ADR 0023: Native Laravel Context for Distributed Observability

* **Status**: Accepted
* **Date**: 2026-09-03

## Context

Tracing requests across HTTP controllers, Monolog entries, background queue jobs, and external exception trackers requires a unified context mechanism.

## Decision

Use Laravel 13's native Context facade (Context::add('request_id', ...)). Context automatically binds to Monolog log records, propagates to background queue jobs via Horizon, and links Sentry error traces.

## Consequences

Eliminates manual parameter passing and provides end-to-end distributed tracing out of the box.
