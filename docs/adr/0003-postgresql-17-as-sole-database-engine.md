# ADR 0003: PostgreSQL 17 as Sole Database Engine

* **Status**: Accepted
* **Date**: 2026-09-03

## Context

The foundation requires advanced relational features, high-performance indexing, semi-structured metadata storage, and reliable concurrency control.

## Decision

PostgreSQL 17 is the mandatory, primary database engine. Features utilized include JSONB with GIN indexing, partial indexes (WHERE deleted_at IS NULL), stored generated columns, native INET types for IP tracking, transactional advisory locks, and full-text search with tsvector.

## Consequences

The application relies on PostgreSQL capabilities. Generic multi-database abstraction (e.g. MySQL portability) is intentionally abandoned in favor of PostgreSQL performance and feature depth.
