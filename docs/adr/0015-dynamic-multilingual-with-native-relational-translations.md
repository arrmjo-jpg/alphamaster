# ADR 0015: Dynamic Multilingual Architecture with Native Relational Translations

* **Status**: Accepted
* **Date**: 2026-09-03

## Context

Languages must be managed dynamically from the database (not hardcoded in configs). Astrotomic relies on static config/translatable.php locales, creating runtime friction with dynamic languages.

## Decision

Replace Astrotomic with a Native HasTranslations trait in Modules/Localization. Translations reside in normalized {entity}_translations tables with unique localized slugs (UNIQUE(locale, slug)). Active locales are resolved from Redis-cached languages.

## Consequences

Eliminates third-party package friction, guarantees full compatibility with dynamic database languages, and enables high-performance PostgreSQL queries.
