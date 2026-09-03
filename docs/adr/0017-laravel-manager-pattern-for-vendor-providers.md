# ADR 0017: Laravel Manager Pattern for Vendor Providers

* **Status**: Accepted
* **Date**: 2026-09-03
* **Revised**: 2026-09-03 — implemented for the SMS capability

## Context

Business logic must not couple to external vendors (Twilio, Meta, OpenAI, SES, S3). Providers must support dynamic configuration, credentials, and failover.

## Decision

Abstract external services behind `ProviderManager`, extending `Illuminate\Support\Manager` so driver creation follows the framework's own convention. The default driver is read from `integration_providers` rather than from a config file, which is what allows a vendor to be swapped from the Admin UI without a deploy. Credentials are encrypted at rest and usage is recorded in `integration_usage_logs`.

**Capabilities.** One capability exists per consumer, not per vendor the ADR once listed. SMS is implemented because the OTP multi-factor method deferred by ADR 0013 needs it. Email, WhatsApp, storage and AI arrive with the phases that consume them; the manager is what makes each addition a driver method and a table row rather than a redesign. Building them before a consumer exists would be the speculative construction this project has avoided throughout.

**Selection and failover.** Every active provider for a capability forms a chain, default first and then by priority. The order is derived rather than stored, so changing which provider is default reorders the chain with no further bookkeeping. The dispatcher walks the chain until one succeeds. A vendor rejection is returned as a failure result rather than thrown, because an expected refusal is an outcome to fall past, not an exceptional condition; exceptions are reserved for programming and configuration faults. When every provider fails, the caller receives the last failure — an undeliverable message is a result, not a crash. A capability with no active provider at all does raise, because that is a misconfiguration rather than a delivery outcome.

**Credentials.** Encrypted with `Crypt::encryptString`, hidden from serialisation, and write-only across the API: omitting the field on update leaves the stored secret untouched, sending null clears it, and no endpoint ever reads one back. The admin API reports only whether credentials are present. A failed decrypt raises rather than returning ciphertext, which would otherwise be handed to a vendor as if it were an API key and surface the fault somewhere far less obvious; the dispatcher treats that as a failure of that provider and continues down the chain.

**Usage logging.** Every attempt is recorded with its driver, outcome, vendor reference or error, and duration. Capability and driver are denormalised so a line stays readable after the provider row is gone. Message recipients and bodies are deliberately absent: a usage log exists to operate the integration, not to store its content.

**Drivers.** Vendor drivers are written against Laravel's HTTP client rather than vendor SDKs. That adds no dependency, keeps the driver coupled to our contract instead of a vendor's client, and leaves it fully exercisable in tests through `Http::fake()`. A log driver ships as the default so a fresh installation can send without an operator supplying anything; Twilio ships provisioned but inactive and credential-less, activated once keys are supplied, in the same spirit as a secret setting provisioned unset (ADR 0018).

## Consequences

Enables swapping vendors from the Admin UI without modifying application code, and makes a failing vendor a logged failover rather than an outage.

Configuring vendors is administrative, so the endpoints sit behind the full admin stack with their own `integrations.view` and `integrations.update` permissions (ADR 0014) — reading which vendors exist and changing their credentials are separately grantable.

A capability's contract is the seam a future vendor attaches to. Adding one is a `create<Name>Driver` method and a row; nothing that sends a message changes.
