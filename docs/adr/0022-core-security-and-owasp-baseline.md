# ADR 0022: Core Security and OWASP Baseline

* **Status**: Accepted
* **Date**: 2026-09-03

## Context

The foundation must provide defense-in-depth against common vulnerabilities (OWASP Top 10) by default.

## Decision

Enforce composite rate-limiting, strict CORS whitelisting, secure response headers (HSTS, CSP, X-Frame-Options), Eloquent parameter binding,  mass assignment protection, and continuous composer audit scans in CI.

## Consequences

Establishes a hardened, production-ready security posture from Day 1.
