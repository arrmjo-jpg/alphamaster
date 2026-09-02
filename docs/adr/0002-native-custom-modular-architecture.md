# ADR 0002: Native Custom Modular Architecture

* **Status**: Accepted
* **Date**: 2026-09-03

## Context

Modularity is mandatory for isolating domain features and enabling future business modules to be added without modifying Core. External modular packages (like nwidart/laravel-modules) introduce package dependency, composer merge complexities, and version lag during major Laravel upgrades.

## Decision

Adopt a zero-dependency Native Modular Architecture within app/Modules/. Each module is a self-contained bounded context with its own ServiceProvider registered in bootstrap/providers.php, routes, migrations, models, actions, and tests. Pest architecture tests (arch()) enforce module layer boundaries.

## Consequences

Eliminates third-party package risks, guarantees instant compatibility with Laravel 13+, and preserves pure Laravel idiomatic conventions.
