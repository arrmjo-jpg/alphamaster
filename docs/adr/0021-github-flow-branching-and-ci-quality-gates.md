# ADR 0021: GitHub Flow Branching and CI Quality Gates

* **Status**: Accepted
* **Date**: 2026-09-03

## Context

The codebase requires automated quality gates to prevent regressions, type errors, style mismatches, and breaking API changes.

## Decision

Adopt GitHub Flow: protected main branch, short-lived feature branches, and pull requests. CI runs Pint, Larastan (Level 8), Pest (Feature, Unit, Arch), Spectral OpenAPI validation, TypeScript checks, and Docker smoke tests.

## Consequences

Zero broken code or undocumented API changes can reach production.
