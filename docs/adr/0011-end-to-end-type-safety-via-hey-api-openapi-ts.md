# ADR 0011: End-to-End Type Safety via @hey-api/openapi-ts

* **Status**: Accepted
* **Date**: 2026-09-03

## Context

Manual TypeScript interfaces in the frontend lead to silent type drift, runtime bugs, and duplicated validation schemas.

## Decision

Use @hey-api/openapi-ts to compile openapi.json into typed Fetch clients, TypeScript interfaces, and static Zod validation schemas. CI validates that generated artifacts match committed specifications.

## Consequences

Eliminates duplicate schema definitions, providing strict compile-time type safety across the network boundary.
