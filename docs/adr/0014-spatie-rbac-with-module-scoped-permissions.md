# ADR 0014: Spatie RBAC with Module-Scoped Permissions

* **Status**: Accepted
* **Date**: 2026-09-03

## Context

Access control requires fine-grained, cached permissions across multiple guards with clean organizational scoping.

## Decision

Use spatie/laravel-permission v6 with Redis caching. Permissions use the format {module}.{resource}.{action} with an added module column. Modules seed their own permissions independently.

## Consequences

Standardizes permission conventions and integrates natively with Laravel Gates and Policies.
