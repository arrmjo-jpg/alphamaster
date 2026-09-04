# ADR 0015: Dynamic Multilingual Architecture with Native Relational Translations

* **Status**: Accepted
* **Date**: 2026-09-03
* **Revised**: 2026-09-03 — HasTranslations implemented in Core; users.preferred_locale created
* **Revised**: 2026-09-04 — extended after the foundation gap audit: localization scope stated explicitly, implementation status recorded

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
   - **Business Domain Entities**: Native relational translations via `HasTranslations` trait and normalized `{entity}_translations` tables with composite index `UNIQUE(foreign_id, locale)`. The trait lives in `App\Modules\Core\Concerns` and resolves the active locale through `LocaleResolverInterface`, so a module becomes translatable without depending on Localization — the same inversion applied to `SetLocale` in point 3. Notification templates are its first consumer.
   - **Recipient locale**: `users.preferred_locale` backs tier 2 of the precedence above. It was referenced by `LocaleResolver` from the outset but the column itself was only created when notifications first needed to render in a recipient's own language rather than the request's; until then that tier resolved to null.

## Extension — 2026-09-04: what localization covers, and what it does not

This section is an addition, not a restatement. The original record described infrastructure, and its point 5 named `lang/{locale}.json` for system text without saying which text. The audit of 2026-09-04 found why that mattered.

**What the audit found.** The infrastructure works end to end, verified against a live request: `X-Locale: ar` returns `Content-Language: ar` and `X-Direction: rtl`, and the five-tier precedence resolves correctly. What is absent is any use of it. `__()`, `trans()` and `Lang::` appear **zero times** in `app/`. `lang/en.json` and `lang/ar.json` hold three keys each and nothing references them. `lang/en/` and `lang/ar/` do not exist, so validation messages come from the framework English defaults. The same verified request that returns `Content-Language: ar` returns `"The email field must be a valid email address."` in its body.

A response that declares a language it does not speak is not an incomplete feature. It is a contract the platform is breaking, which is why this is recorded as an implementation gap against this ADR rather than as a new decision.

### Localization covers all human-readable output

Every string a person reads is localized, wherever it originates:

* **System and API messages** — success messages, error messages, and the messages produced by the central exception handler.
* **Validation messages** — both the framework catalogue, published to `lang/{locale}/validation.php`, and the custom messages declared in FormRequests.
* **Display labels** — for enums, permissions, roles and settings, stored as ADR 0030 specifies.
* **Entity content** — through the relational translations this record already established.
* **Notification content** — already implemented, and the pattern the rest follows (ADR 0019).

### Technical identifiers are excluded, explicitly

Localization applies to human-readable content only. It does **not** apply to `error.code` values such as `VALIDATION_ERROR` or `PERMISSION_DENIED`, nor to permission keys, role names, enum backed values, setting keys, or notification type identifiers. These are contract, and a client matches on them. ADR 0030 states the principle and where each label lives; this record governs the text, not the key.

### Localization is applied at the layer, not the call site

The audit counted 49 `successResponse()` and 25 `errorResponse()` calls, plus the handlers in `bootstrap/app.php`. Translating each call site would spread the concern across 74 places and guarantee the seventy-fifth is missed.

There are two choke points, and localization belongs in them: the `ApiResponse` trait, which builds every success and error envelope, and the exception handlers in `bootstrap/app.php`. A message passed to either is a translation key resolved against the request locale, so a call site names what it means and never which language it means it in.

### Translation authoring, and where AI sits

Adding a language creates a row and activates a locale; it does not create translations. The intended workflow — not implemented, and recorded here to fix the boundary before anything is built — is that translations may be **proposed** by an AI translation step, queued on Horizon like any other background work, writing into the same stores this ADR defines.

**AI output is never the source of truth and is never published unreviewed.** A proposed translation is a draft awaiting human confirmation. The reason is the one this project has applied to scanners and analysers throughout: a system that asserts a result it cannot verify is worse than one that reports the absence, and an unreviewed machine translation in a security notification or a permission label is an assertion nobody checked.

### Implementation status

| Element | State |
| :--- | :--- |
| Language schema, resolution, caching, headers | Implemented, Phase 3 |
| `users.preferred_locale` tier | Implemented, Phase 9 |
| Relational entity translations | Implemented for notification templates, Phase 9 |
| System and API message localization | **Not implemented** |
| Validation message localization | **Not implemented** — `lang/{locale}/validation.php` absent |
| Display labels | Decided in ADR 0030, **not implemented** |
| AI-assisted authoring | Boundary set above, **not implemented** |

Tracked in ADR 0029.

## Consequences

Eliminates third-party package technical debt, enforces PostgreSQL engine-level data integrity for default languages, enables dynamic admin management of locales, and preserves strict hexagonal architectural boundaries between Core and Localization.
