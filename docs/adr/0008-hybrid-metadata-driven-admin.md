# ADR 0008: Hybrid Metadata-Driven Admin

* **Status**: Accepted
* **Date**: 2026-09-03

## Context

Fully server-driven UIs (Salesforce style) sacrifice frontend DX, type safety, and component flexibility. Fully hardcoded UIs require frontend deployments for simple field additions.

## Decision

Adopt a Hybrid Metadata architecture: core domain entities and layouts are statically compiled and typed; dynamic extensions (custom fields, dynamic permissions, active locales) are server-driven via a metadata API.

## Consequences

Maintains compile-time TypeScript safety for core flows while providing dynamic adaptability for client-specific extensions.
