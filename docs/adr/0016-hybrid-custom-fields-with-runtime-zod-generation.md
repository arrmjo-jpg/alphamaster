# ADR 0016: Hybrid Custom Fields with Runtime Zod Generation

* **Status**: Accepted
* **Date**: 2026-09-03

## Context

Business entities need dynamic field extensions without altering database schemas per project. Pure EAV degrades query performance. Dynamic fields cannot have static Zod schemas at build time.

## Decision

Adopt a Hybrid architecture: core fields use relational columns; dynamic attributes use custom_field_definitions and custom_field_values. React Admin dynamically constructs Zod validation schemas at runtime using field definition metadata.

## Consequences

Preserves relational performance for core data while providing runtime-validated, dynamic form flexibility.
