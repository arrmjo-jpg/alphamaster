# ADR 0009: Static Module Registry with Dynamic Extension Slots

* **Status**: Accepted
* **Date**: 2026-09-03

## Context

The Admin Shell must host modular workspaces without hardcoding domain references or requiring micro-frontend complexity.

## Decision

Use a typed Static Module Registry in the React Admin. Modules register workspace manifests, routes, navigation items, commands, and extension slot components. The shell renders modules based on active permissions.

## Consequences

Enables adding new workspaces simply by exporting a module definition, keeping the Admin Shell generic and decoupled.
