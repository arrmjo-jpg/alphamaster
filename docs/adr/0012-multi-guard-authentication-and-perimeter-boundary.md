# ADR 0012: Multi-Guard Authentication and Perimeter Boundary

* **Status**: Accepted
* **Date**: 2026-09-03

## Context

Admins and regular Users share an identity concept but operate in distinct security boundaries. Regular user tokens must never touch Admin endpoints.

## Decision

Enforce a perimeter security boundary: Admin login issues Sanctum tokens with abilities: ['admin:access']. User login issues tokens with abilities: ['user:access']. Admin routes (/api/v1/admin/*) enforce auth:sanctum, ability:admin:access, and EnsureUserIsAdmin.

## Consequences

Prevents privilege escalation at the token ability level before role/permission checks are evaluated.
