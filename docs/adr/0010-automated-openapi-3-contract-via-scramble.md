# ADR 0010: Automated OpenAPI 3.1 Contract via Scramble

* **Status**: Accepted
* **Date**: 2026-09-03

## Context

Manually maintained Swagger/OpenAPI documentation drift from actual code. Heavy PHPDoc annotation packages clutter domain code.

## Decision

Use dedoc/scramble to automatically infer OpenAPI 3.1 specifications from Laravel FormRequests, API Resources, Spatie Data objects, and Route definitions without manual annotations.

## Consequences

Guarantees that the OpenAPI specification matches production code, enabling automated drift detection in CI.
