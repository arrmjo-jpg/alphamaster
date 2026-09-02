# ADR 0012: Sanctum Token Abilities and Admin Perimeter Boundary

* **Status**: Accepted
* **Date**: 2026-09-03

## Context

Admins and regular users share a unified user identity in the database but operate within strictly segregated security boundaries. Regular user authentication tokens must never be capable of invoking administrative endpoints, and administrative tokens must be explicitly scoped. Rather than maintaining artificial separate guards (which duplicates models, migrations, and session handlers), Laravel Sanctum token abilities provide fine-grained perimeter security.

## Decision

Adopt Sanctum token abilities and a layered perimeter defense boundary rather than multi-guard infrastructure:
1. **Token Scoping**: Admin authentication issues Sanctum tokens strictly with abilities `['admin:access']`. Regular user authentication issues tokens with `['user:access']`.
2. **Layered Defense Pipeline**: Administrative endpoints (`/api/v1/admin/*`) enforce a four-stage security pipeline:
   - `auth:sanctum`: Verifies token signature, expiration, and resolves the authenticatable user.
   - `ability:admin:access`: Validates that the token explicitly carries the `admin:access` ability.
   - Account / Status Checks: Middleware (`EnsureAccountActive`) validates that the user account is not suspended, locked, or soft-deleted.
   - Admin Authorization / RBAC: Middleware and Policies (`EnsureUserIsAdmin`, Spatie RBAC) evaluate role, permissions, and MFA status.

## Consequences

Eliminates the complexity and technical debt of multi-guard configuration while establishing an ironclad, defense-in-depth security perimeter that prevents privilege escalation at the token ability level before any route or permission logic is executed.
