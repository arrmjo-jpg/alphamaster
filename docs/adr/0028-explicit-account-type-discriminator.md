# ADR 0028: Explicit Account Type Discriminator

* **Status**: Accepted
* **Date**: 2026-09-03

## Context

Administrators and application users share one identity table. Until now the difference between them was an `is_admin` boolean, which was adequate while "administrator" meant only "may pass the admin perimeter".

It stopped being adequate once admin RBAC arrived. Roles and permissions are administrative infrastructure (ADR 0014), and the platform needs to state which accounts participate in them at all — a question a boolean named after one of its two states answers poorly. It also needed to be a field with security weight: something no request payload, profile endpoint or mass assignment could move, and something the database itself would refuse to hold a nonsense value for.

## Decision

`users.account_type` is an explicit discriminator with exactly two values, `admin` and `user`, backed by the `AccountType` enum. It replaces `is_admin` outright. The two are not kept side by side: one question deserves one source of truth, and a derived duplicate is a defect waiting to drift.

The column is constrained at the database level, as `settings.type` and `mfa_methods.type` already are, so the invariant survives raw writes and disabled model events. `User::isAdmin()` is the single place the platform asks the question; nothing infers administrative standing from a role, a permission, or any other relation.

`account_type` is excluded from mass assignment. Outside production `Model::shouldBeStrict()` turns an attempt into an exception; in production the attribute is discarded. Both are safe, and both are asserted, because the property has to hold in production and not merely in the environment the tests run in.

Crossing the boundary happens only through `AccountTypeManager`:

* **Promotion** revokes every existing token, because a token issued to a regular account carries `user:access` and must not linger beside the new standing. The account signs in again and, MFA being mandatory for administrators (ADR 0013), is taken through enrolment before it receives `admin:access`.
* **Demotion** revokes every token, so an `admin:access` token cannot outlive the standing that justified it, and strips every admin role and permission relation, so nothing dormant would take effect if the account were promoted again.

The promotion and demotion endpoints are themselves gated on an admin RBAC permission, so an administrator without it cannot create peers.

## Consequences

**Breaking API change.** `GET /api/v1/auth/me` now returns:

```json
{ "account_type": "admin" }
```

in place of:

```json
{ "is_admin": true }
```

`is_admin` is gone from the response rather than retained as an alias. Keeping both would reintroduce the second source of truth this record exists to remove, and would leave clients free to key off the field that is no longer authoritative. Clients read `account_type` and compare it against `"admin"`.

The database column is likewise gone. The change ships as a forward migration that backfills existing administrators before dropping `is_admin`, so a deployed database reaches the new state with `migrate` rather than only with `migrate:fresh`.

An account type says who may enter administration, never what they may do there. Permission still comes from ADR 0014, and `account_type = admin` on its own authorizes no action.

Application-level authorization for regular users — workspace membership, user groups, application permissions — remains deliberately unbuilt. When it arrives it attaches to the `user` side of this discriminator and stays separate from admin RBAC; no speculative tables for it exist today.
