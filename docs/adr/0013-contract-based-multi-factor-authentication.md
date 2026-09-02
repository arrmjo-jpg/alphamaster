# ADR 0013: Contract-Based Multi-Factor Authentication (MFA)

* **Status**: Accepted
* **Date**: 2026-09-03

## Context

Admin accounts require mandatory MFA. The platform must support TOTP, SMS/WhatsApp OTP, and future WebAuthn/Passkeys without re-engineering the auth core.

## Decision

Abstract MFA behind MfaMethodContract. TOTP is implemented via pragmarx/google2fa. OTP methods utilize the Integration module. A temporary mfa_token governs the two-step challenge flow. Recovery codes provide emergency access.

## Consequences

Enables adding WebAuthn (laravel/passkeys) seamlessly in the future by implementing the contract.
