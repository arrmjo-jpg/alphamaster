# ADR 0018: Grouped, Encrypted, and Cached Settings System

* **Status**: Accepted
* **Date**: 2026-09-03

## Context

Application configuration must be dynamically modifiable by administrators while keeping secrets secure and reads ultra-fast.

## Decision

Store settings in a typed, grouped key-value table. Secret attributes (is_secret) use Laravel's encrypted cast. Settings are cached in Redis using cache tags and invalidated on update. Public settings are exposed via /api/v1/settings/public.

## Consequences

Provides instant settings retrieval without database queries and protects credentials at rest.
