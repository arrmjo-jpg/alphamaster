# ADR 0005: Docker Container Isolation (One Process Per Container)

* **Status**: Accepted
* **Date**: 2026-09-03

## Context

Running multiple long-lived processes (API, queue workers, scheduler) in a single container via supervisord couples lifecycles, obscures resource metrics, and prevents independent scaling.

## Decision

Every major service and process runs in its own container: backend (PHP-FPM), horizon (queue supervisor), scheduler (cron runner), postgres, redis, and reverse-proxy (Nginx). The backend image is reused across PHP services with distinct command entrypoints.

## Consequences

Allows independent resource allocation, horizontal scaling of worker processes, transparent health monitoring, and isolated restart policies.
