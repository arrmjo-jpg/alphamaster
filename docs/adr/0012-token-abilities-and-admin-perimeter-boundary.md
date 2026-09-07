# ADR 0012: Sanctum Token Abilities and Admin Perimeter Boundary

* **Status**: Accepted
* **Date**: 2026-09-03
* **Revised**: 2026-09-03 — fourth stage aligned with the implemented perimeter
* **Revised**: 2026-09-07 — a fifth stage: a verified email address for administrators

## Context

Admins and regular users share a unified user identity in the database but operate within strictly segregated security boundaries. Regular user authentication tokens must never be capable of invoking administrative endpoints, and administrative tokens must be explicitly scoped. Rather than maintaining artificial separate guards (which duplicates models, migrations, and session handlers), Laravel Sanctum token abilities provide fine-grained perimeter security.

## Decision

Adopt Sanctum token abilities and a layered perimeter defense boundary rather than multi-guard infrastructure:

1. **Token Scoping**: Admin authentication issues Sanctum tokens strictly with abilities `['admin:access']`. Regular user authentication issues tokens with `['user:access']`. A token carries exactly one ability, never a combination and never a wildcard. Two further abilities exist, and neither is a sign-in. `mfa:enrol` is issued only to an administrator who has not yet satisfied the mandatory MFA requirement of ADR 0013; it reaches the enrolment endpoints alone. `email:verify` is issued only to an administrator whose address is unverified; it reaches the endpoint that requests a verification link and nothing else — not `me`, not `logout`, not enrolment. Both are refused by every stage of this perimeter, and the two are disjoint: neither reaches the other's endpoints.
2. **Layered Defense Pipeline**: Administrative endpoints (`/api/v1/admin/*`) enforce a four-stage security pipeline:
   - `auth:sanctum`: Verifies token signature, expiration, and resolves the authenticatable user.
   - `ability:admin:access`: Validates that the token explicitly carries the `admin:access` ability.
   - Account / Status Checks: Middleware (`EnsureAccountActive`) validates that the user account is not suspended, locked, or soft-deleted.
   - Admin Authorization: Middleware (`EnsureUserIsAdmin`) establishes administrative identity from the `is_admin` flag, and fails closed when it cannot.
   - Email Verification: Middleware (`EnsureEmailVerified`) requires a verified address for an administrator, and fails closed when the identity cannot answer the question at all.

The fourth stage originally also named Spatie RBAC, Policies, and an MFA-status check. None of those exist, and the middleware's role lookup has been removed: consulting an absent role system is an authorization decision nothing can verify, and it read as a granted permission while granting nothing. Role and permission evaluation enters this stage when Spatie RBAC is implemented under ADR 0014, at which point this record is revised again.

Multi-factor authentication is enforced at sign-in rather than at the perimeter (ADR 0013). A user with MFA enabled receives no access token from the login endpoint at all, only a short-lived challenge token, so no token that reaches the perimeter can belong to an unsatisfied MFA challenge. The perimeter therefore has no MFA state to evaluate.

### The fifth stage: a verified email address

**An administrator must have verified their email address.** Not a regular user: verification gates administrative access and nothing else, so a regular account is reported as unverified and is not stopped for it.

The reason is what an administrative account *is*. It can change the platform's configuration, rotate credentials and read the audit trail, and every one of those acts is attributed to an address. An address nobody has proved control of is an unowned identity, and it is also the address a future recovery flow would trust.

**Enforced twice, deliberately.** Unlike MFA, this is not left to sign-in alone.

*At issuance.* `AuthService::issueToken()` refuses to mint `admin:access` for an unverified administrator, and raises rather than returning something weaker. Three paths issue an access token — sign-in, completing an MFA challenge, and exchanging an enrolment credential — and all three pass through that one method, so the invariant is stated once rather than three times and a fourth path cannot quietly omit it. Sign-in checks verification before it reaches issuance, so in ordinary operation the refusal is unreachable; arriving there means a path exists that nobody intended, and a loud failure is the useful outcome.

*At the perimeter.* `EnsureEmailVerified` refuses a request whose holder is unverified, whatever token it carries and however that token was obtained. This is what covers a token minted before the rule existed, or an address that changes after a token is issued. A perimeter that trusts the issuing path is exactly as correct as the last person to edit that path.

The stage sits **after** `EnsureUserIsAdmin` and before permission evaluation. Order is contract: running it earlier would answer `EMAIL_VERIFICATION_REQUIRED` to a regular user probing an administrative route, telling them the route exists and what it wants next.

**Verification precedes enrolment at sign-in**, and that ordering is load-bearing rather than cosmetic. Completing enrolment hands back a real `admin:access` token in the same response (ADR 0013); if an unverified administrator could reach enrolment, that exchange would be a path to administrative access without a verified address.

**The deadlock, and the same answer as ADR 0013.** Verification is now a precondition of administrative access, so an unverified administrator holds no access token — but the endpoint that sends the verification link is authenticated, because one that accepted an address would mail strangers and confirm which addresses hold accounts. `email:verify` bridges those two facts: a real Sanctum token carrying one narrow ability, enforced by the ability layer that already exists, with no second enforcement path to keep in step. It reaches no endpoint that issues a token, which is what makes it safe to hand to an account that has proved only a password.

## Consequences

Eliminates the complexity and technical debt of multi-guard configuration while establishing an ironclad, defense-in-depth security perimeter that prevents privilege escalation at the token ability level before any route or permission logic is executed.

Administrative authorization is currently coarse: a user either is an administrator or is not. Any endpoint needing finer distinctions must wait for ADR 0014 rather than inventing its own role check, so that authorization has exactly one home.

An administrator cannot hold a token bearing `admin:access` without both a verified email address and a confirmed second factor, at any point in the lifecycle. ADR 0013 already stated the second half of that; this record states the first, and the two are enforced by the same mechanism.

The cost is a fourth shape a client must branch on at sign-in — access token, MFA challenge, enrolment required, verification required — and a first-run sequence with two steps in it. That is the honest shape of two prerequisites, and pushing it into the client is preferable to a server that issues a privileged token it intends to be ignored.

Every administrative route repeats the perimeter string, because each module writes its own rather than importing Auth. A module that forgot the new stage would be a hole, so a test enumerates every route carrying `EnsureUserIsAdmin` and requires the verification stage beside it.
