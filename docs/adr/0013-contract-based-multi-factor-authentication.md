# ADR 0013: Contract-Based Multi-Factor Authentication (MFA)

* **Status**: Accepted
* **Date**: 2026-09-03
* **Revised**: 2026-09-03 — mandatory administrator MFA implemented; OTP channel deferred

## Context

The platform must support TOTP, SMS/WhatsApp OTP, and future WebAuthn/Passkeys without re-engineering the auth core.

Admin accounts require MFA. This is enforced, not aspirational: the enforcement point and the deadlock it has to avoid are described below.

## Decision

Abstract MFA behind `MfaMethodContract`. A method owns its own secret material and verification rule, so a new factor is added by implementing the contract rather than by changing the challenge flow that consumes it.

**Implemented.** TOTP via `pragmarx/google2fa`, resolved through `MfaManagerContract`, which registers methods by type. Enrolment returns a secret and an `otpauth://` URI exactly once and leaves the method inactive until it is confirmed with a genuine code. Secrets are encrypted at rest with `Crypt::encryptString`; a failed decrypt raises rather than returning ciphertext. A code is accepted only from a time slice strictly newer than the last one accepted, so a code cannot be replayed inside its own validity window.

A temporary `mfa_token` governs the two-step challenge. It is not a Sanctum token and grants nothing: a user with MFA enabled receives only this challenge token from the login endpoint, never an access token. Only the token's SHA-256 hash is stored, so the cache never holds a usable credential, and the challenge is single use.

Recovery codes provide emergency access. They are stored only as hashes, shown in plaintext once at generation, and consumed atomically so one code cannot be spent twice. Disabling MFA requires a currently valid code or an unused recovery code, so a hijacked session cannot strip the second factor from an account.

Both the login and the challenge endpoints are brute-force limited under ADR 0022.

**Mandatory for administrators.** MFA is compulsory for any account with `is_admin`, and optional for everyone else. An administrator who has not enrolled receives no access token from the login endpoint — only a Sanctum token carrying the single ability `mfa:enrol`, which reaches the two enrolment endpoints and nothing else. It cannot pass the admin perimeter, and it cannot act as the user: `me`, `logout`, MFA status and MFA disable all refuse it. Completing enrolment proves possession of the factor, which together with the password already presented is a complete two-factor authentication, so the enrolment credential is destroyed and exchanged for a real `admin:access` token in the same response rather than forcing a second sign-in.

The enrolment ability exists to resolve a deadlock: a compulsory second factor means the administrator needs some credential to enrol with, but must not hold an access token until they have one. Expressing that as a scoped ability rather than a bespoke credential means the existing perimeter enforces it, with no second enforcement path to keep in step.

An administrator who disables MFA has every one of their tokens revoked, so the invariant that an administrator holding access has a second factor is true continuously rather than merely at sign-in. Their next sign-in returns them to enrolment. Disabling remains available so that a lost device can be recovered from with a recovery code.

**Deferred.** SMS and WhatsApp OTP route through the Integration module, which does not exist yet; the contract is the seam where they attach, and no partial implementation of them is present. TOTP is sufficient for the mandatory administrator requirement, so nothing waits on it.

## Consequences

Enables adding WebAuthn (laravel/passkeys) or an OTP channel later by implementing the contract, without touching the challenge flow, the token issuing path, or the perimeter.

An administrator cannot hold a token bearing `admin:access` without a confirmed second factor, at any point in the lifecycle: not before enrolling, not while enrolling, and not after disabling. Controls elsewhere in the platform may rely on that.

The cost is a third token ability and a login response that a client must branch on three ways — access token, MFA challenge, or enrolment required. That branching is the honest shape of the requirement, and pushing it into the client is preferable to a server that issues a privileged token it intends to be ignored.
