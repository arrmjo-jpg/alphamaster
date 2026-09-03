# ADR 0013: Contract-Based Multi-Factor Authentication (MFA)

* **Status**: Accepted
* **Date**: 2026-09-03
* **Revised**: 2026-09-03 — SMS OTP implemented for regular users; WhatsApp still deferred

## Context

The platform must support TOTP, SMS/WhatsApp OTP, and future WebAuthn/Passkeys without re-engineering the auth core.

Admin accounts require MFA. This is enforced, not aspirational: the enforcement point and the deadlock it has to avoid are described below.

## Decision

Abstract MFA behind `MfaMethodContract`. A method owns its own secret material and verification rule, so a new factor is added by implementing the contract rather than by changing the challenge flow that consumes it.

Enrolment returns a typed `MfaEnrolment` rather than the `{secret, uri}` array the contract first used. That shape was drawn from TOTP and meant nothing for a method that has neither a shared secret nor a provisioning URI; each method now returns only the fields it actually has. A method whose code must be delivered additionally implements `DeliversMfaCodes`, so the challenge flow can ask whether delivery applies instead of every method carrying a `send()` that most would leave empty.

**Implemented.** TOTP via `pragmarx/google2fa`, resolved through `MfaManagerContract`, which registers methods by type. Enrolment returns a secret and an `otpauth://` URI exactly once and leaves the method inactive until it is confirmed with a genuine code. Secrets are encrypted at rest with `Crypt::encryptString`; a failed decrypt raises rather than returning ciphertext. A code is accepted only from a time slice strictly newer than the last one accepted, so a code cannot be replayed inside its own validity window.

A temporary `mfa_token` governs the two-step challenge. It is not a Sanctum token and grants nothing: a user with MFA enabled receives only this challenge token from the login endpoint, never an access token. Only the token's SHA-256 hash is stored, so the cache never holds a usable credential, and the challenge is single use.

Recovery codes provide emergency access. They are stored only as hashes, shown in plaintext once at generation, and consumed atomically so one code cannot be spent twice. Disabling MFA requires a currently valid code or an unused recovery code, so a hijacked session cannot strip the second factor from an account.

Both the login and the challenge endpoints are brute-force limited under ADR 0022.

**Mandatory for administrators.** MFA is compulsory for any account with `is_admin`, and optional for everyone else. An administrator who has not enrolled receives no access token from the login endpoint — only a Sanctum token carrying the single ability `mfa:enrol`, which reaches the two enrolment endpoints and nothing else. It cannot pass the admin perimeter, and it cannot act as the user: `me`, `logout`, MFA status and MFA disable all refuse it. Completing enrolment proves possession of the factor, which together with the password already presented is a complete two-factor authentication, so the enrolment credential is destroyed and exchanged for a real `admin:access` token in the same response rather than forcing a second sign-in.

The enrolment ability exists to resolve a deadlock: a compulsory second factor means the administrator needs some credential to enrol with, but must not hold an access token until they have one. Expressing that as a scoped ability rather than a bespoke credential means the existing perimeter enforces it, with no second enforcement path to keep in step.

An administrator who disables MFA has every one of their tokens revoked, so the invariant that an administrator holding access has a second factor is true continuously rather than merely at sign-in. Their next sign-in returns them to enrolment. Disabling remains available so that a lost device can be recovered from with a recovery code.

**SMS one-time codes.** Implemented for regular users, dispatched through the Integration module's SMS capability (ADR 0017), so the method owns no transport: provider selection, failover and usage logging happen there and a vendor change is invisible to it. The number is stored encrypted on the method row and is verified by the enrolment itself — a code sent to it and returned proves possession, exactly as a TOTP code proves possession of a secret — so there is no separate unverified-number state to reason about. Only a hash of the pending code is stored, it dies on first correct presentation, and it expires after five minutes.

Delivery is an explicit request, never a side effect of signing in. A login for an SMS-enrolled account returns the challenge and sends nothing; the client then asks for a code. Otherwise anyone holding a password could make the platform send unlimited messages to the account owner's phone. The send endpoint is throttled on the challenge token and the method enforces a thirty second resend cooldown on top, and a resend invalidates its predecessor so two live codes never exist for one account.

**SMS does not satisfy the administrator requirement.** SIM swap and SS7 interception are practical attacks and NIST SP 800-63B treats an out-of-band SMS authenticator as restricted. Having made administrator MFA compulsory for security reasons, allowing exactly those accounts to satisfy it with the weakest available factor would undo the point. Administrators are refused SMS enrolment, and an account that somehow holds only a confirmed SMS method is still sent to enrolment when it becomes an administrator — the policy is evaluated, not merely enforced at the point of enrolment.

**Deferred.** WhatsApp OTP needs a WhatsApp capability in the Integration module, which arrives with a consumer for it. Nothing is blocked in the meantime.

## Consequences

Enables adding WebAuthn (laravel/passkeys) or an OTP channel later by implementing the contract, without touching the challenge flow, the token issuing path, or the perimeter.

An administrator cannot hold a token bearing `admin:access` without a confirmed second factor, at any point in the lifecycle: not before enrolling, not while enrolling, and not after disabling. Controls elsewhere in the platform may rely on that.

The cost is a third token ability and a login response that a client must branch on three ways — access token, MFA challenge, or enrolment required. That branching is the honest shape of the requirement, and pushing it into the client is preferable to a server that issues a privileged token it intends to be ignored.
