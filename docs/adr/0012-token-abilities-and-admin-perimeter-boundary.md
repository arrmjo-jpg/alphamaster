# ADR 0012: Sanctum Token Abilities and Admin Perimeter Boundary

* **Status**: Accepted
* **Date**: 2026-09-03
* **Revised**: 2026-09-03 — fourth stage aligned with the implemented perimeter

## Context

Admins and regular users share a unified user identity in the database but operate within strictly segregated security boundaries. Regular user authentication tokens must never be capable of invoking administrative endpoints, and administrative tokens must be explicitly scoped. Rather than maintaining artificial separate guards (which duplicates models, migrations, and session handlers), Laravel Sanctum token abilities provide fine-grained perimeter security.

## Decision

Adopt Sanctum token abilities and a layered perimeter defense boundary rather than multi-guard infrastructure:

1. **Token Scoping**: Admin authentication issues Sanctum tokens strictly with abilities `['admin:access']`. Regular user authentication issues tokens with `['user:access']`. A token carries exactly one ability, never a combination and never a wildcard. A third ability, `mfa:enrol`, is issued only to an administrator who has not yet satisfied the mandatory MFA requirement of ADR 0013; it reaches the enrolment endpoints alone and is refused by every stage of this perimeter.
2. **Layered Defense Pipeline**: Administrative endpoints (`/api/v1/admin/*`) enforce a four-stage security pipeline:
   - `auth:sanctum`: Verifies token signature, expiration, and resolves the authenticatable user.
   - `ability:admin:access`: Validates that the token explicitly carries the `admin:access` ability.
   - Account / Status Checks: Middleware (`EnsureAccountActive`) validates that the user account is not suspended, locked, or soft-deleted.
   - Admin Authorization: Middleware (`EnsureUserIsAdmin`) establishes administrative identity from the `is_admin` flag, and fails closed when it cannot.

The fourth stage originally also named Spatie RBAC, Policies, and an MFA-status check. None of those exist, and the middleware's role lookup has been removed: consulting an absent role system is an authorization decision nothing can verify, and it read as a granted permission while granting nothing. Role and permission evaluation enters this stage when Spatie RBAC is implemented under ADR 0014, at which point this record is revised again.

Multi-factor authentication is enforced at sign-in rather than at the perimeter (ADR 0013). A user with MFA enabled receives no access token from the login endpoint at all, only a short-lived challenge token, so no token that reaches the perimeter can belong to an unsatisfied MFA challenge. The perimeter therefore has no MFA state to evaluate.

## Consequences

Eliminates the complexity and technical debt of multi-guard configuration while establishing an ironclad, defense-in-depth security perimeter that prevents privilege escalation at the token ability level before any route or permission logic is executed.

Administrative authorization is currently coarse: a user either is an administrator or is not. Any endpoint needing finer distinctions must wait for ADR 0014 rather than inventing its own role check, so that authorization has exactly one home.
