# ADR 0004: ULID Primary Keys with Pragmatic Index Locality

* **Status**: Accepted
* **Date**: 2026-09-03

## Context

Autoincrement integers expose business metrics and risk enumeration attacks. Random UUID v4 causes random write patterns and heavy B-Tree index fragmentation on large tables.

## Decision

Adopt ULID (Universally Unique Lexicographically Sortable Identifier) as the primary key standard across all entity tables. ULID is stored as CHAR(26) generated via Laravel's native HasUlids trait. ULID is lexicographically sortable and can improve index locality compared with fully random identifiers, but it does not guarantee zero B-tree fragmentation.

## Consequences

Provides 128-bit collision resistance, 26-character URL-safe string representation, and improved insert locality compared to UUID v4.
