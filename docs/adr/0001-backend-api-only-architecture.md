# ADR 0001: Backend API-Only Architecture

* **Status**: Accepted
* **Date**: 2026-09-03

## Context

The platform serves as a reusable foundation for diverse business applications, supporting a standalone React Admin platform and independent public frontends (Next.js, Nuxt, Mobile Apps). A monolithic Blade approach tightly couples UI rendering to the backend lifecycle and hinders client independence.

## Decision

Laravel 13 will be built strictly as an API-Only backend. No Blade views or hybrid server-side admin panels (such as Filament or Nova) will be used for the core platform. Communication happens exclusively over versioned RESTful JSON APIs (/api/v1/*).

## Consequences

Decouples backend deployment and lifecycle from all client interfaces. Requires robust API contracts (OpenAPI) and standardized error formatting.
