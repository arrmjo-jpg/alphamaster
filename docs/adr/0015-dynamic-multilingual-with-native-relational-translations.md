# ADR 0015: Dynamic Multilingual Architecture with Native Relational Translations

* **Status**: Accepted
* **Date**: 2026-09-03

## Context

Enterprise applications require dynamic multi-language capabilities where administrators can add, activate, deactivate, or re-order languages at runtime without modifying application config files or restarting containers. Third-party translation packages (such as Astrotomic) couple heavily to static arrays in `config/translatable.php`, causing friction with dynamic database-driven languages. Furthermore, core middleware must not couple directly to localization domain logic or hardcode language lists.

## Decision

1. **Database-Driven Language Schema (`languages`)**:
   - **Primary Key**: ULID 26-character string (`id`).
   - **Attributes**: `code` (unique, e.g. `en`, `ar`), `name`, `native_name`, `direction` (`ltr`/`rtl`), `is_active`, `is_default`, `sort_order`, timestamps.
   - **Single Default Constraint**: Enforced at the PostgreSQL engine level via a partial unique index:
     `CREATE UNIQUE INDEX idx_languages_single_default ON languages (is_default) WHERE is_default = TRUE;`
   - **RTL / LTR Source of Truth**: Direction is read dynamically from the database record (`direction` column) and exposed via `X-Direction` response header. No language codes (such as Arabic or English) are hardcoded to directions in application code.

2. **Deterministic Locale Resolution Precedence**:
   1. Explicit request header `X-Locale` or query parameter `?locale=`.
   2. Authenticated user's preferred locale (`$user->preferred_locale` / `$user->locale`).
   3. HTTP `Accept-Language` header weighted negotiation.
   4. Database configured default language (`is_default = TRUE`).
   5. Application fallback configuration (`config('app.locale', 'en')`).
   *Only active database languages are accepted as valid targets; invalid or inactive locales smoothly fall back to subsequent tiers.*

3. **Core Bounded-Context Isolation (DIP)**:
   - Core defines `App\Modules\Core\Contracts\LocaleResolverInterface`.
   - Localization implements `App\Modules\Localization\Services\LocaleResolver`.
   - Core's `SetLocale` middleware injects the interface without importing or depending on the Localization module, avoiding circular architecture.

4. **Redis Caching Strategy**:
   - Active language metadata is cached in Redis under `localization:languages:active` (TTL 24h).
   - Default language code is cached under `localization:languages:default`.
   - Cache invalidation is automatic via Eloquent `saved` and `deleted` lifecycle hooks on the `Language` model.

5. **Separation of System UI vs Entity Translations**:
   - **System UI & API Errors**: Native JSON translation dictionaries (`lang/{locale}.json`).
   - **Business Domain Entities**: Native relational translations via `HasTranslations` trait and normalized `{entity}_translations` tables with composite index `UNIQUE(foreign_id, locale)`.

## Consequences

Eliminates third-party package technical debt, enforces PostgreSQL engine-level data integrity for default languages, enables dynamic admin management of locales, and preserves strict hexagonal architectural boundaries between Core and Localization.
