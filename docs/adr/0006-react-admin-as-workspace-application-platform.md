# ADR 0006: React Admin as a Workspace Application Platform

* **Status**: Accepted
* **Date**: 2026-09-03

## Context

Traditional admin panels rely on repetitive, rigid page structures (Sidebar -> Table -> Modal -> Form) that do not support modern contextual productivity workflows (e.g., Linear, Notion).

## Decision

Build React Admin as an extensible Workspace Application Platform. Each domain module registers a self-contained Workspace featuring internal tabs/views, contextual filters, saved views, command palette actions, and synchronized deep URL state.

## Consequences

Requires upfront investment in shell and workspace platform components, but delivers an extensible, high-productivity interface for all future business modules.
