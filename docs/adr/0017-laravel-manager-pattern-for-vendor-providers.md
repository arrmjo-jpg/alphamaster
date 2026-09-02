# ADR 0017: Laravel Manager Pattern for Vendor Providers

* **Status**: Accepted
* **Date**: 2026-09-03

## Context

Business logic must not couple to external vendors (Twilio, Meta, OpenAI, SES, S3). Providers must support dynamic configuration, credentials, and failover.

## Decision

Abstract external services behind ProviderManager extending Illuminate\Support\Manager. Default and fallback drivers are configured in integration_providers with encrypted credentials. Usage is tracked in integration_usage_logs.

## Consequences

Enables swapping vendors from the Admin UI without modifying application code.
