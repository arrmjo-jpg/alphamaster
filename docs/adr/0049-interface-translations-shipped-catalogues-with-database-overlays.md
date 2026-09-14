# ADR 0049: Interface Translations — One Language System, a Code Catalogue and a Database Overlay

* **Status**: Accepted
* **Date**: 2026-09-11, accepted and implemented 2026-09-14
* **Relates to**: ADR 0015, ADR 0043, ADR 0048, ADR 0056

## Context

The platform had two translation domains and only one of them followed Language Management.

| | Content translations | Interface translations (before) |
| :--- | :--- | :--- |
| Examples | Role labels, notification wording, settings, pages, team | "Dashboard", "Save", menus, empty states, API and validation messages |
| Where it lived | Database, owned by the module the content belongs to | `backend/lang/{en,ar}` and `admin/src/i18n/{en,ar}/common.json` |
| Adding a language | Appears in the workshop as not translated (ADR 0048, ADR 0056) | **Nothing.** The Admin offered `SUPPORTED_LOCALES = ['en', 'ar']` and fell back to English |

An operator could add French and translate every page into it, and still could not read the console in French, or choose it in the top bar, without a developer adding a file, a code change and a rebuild.

## Decision

### 1. Language Management is the only source of languages

Which languages exist, which are served, which is the default and which way each runs are decided in Language Management and nowhere else.

The Admin has no list of languages:
* The top bar offers every language `/api/v1/languages` returns.
* The document's direction is the chosen language's own `direction`. Nothing knows which languages run right to left.
* A language added in Language Management is a console language after a refresh, with no build, no code and no migration.

### 2. The Interface Translation Catalog is code, and English is its source

The catalogue answers "what does the interface say". Language Management answers "in which languages".

| Catalogue | Source files | Placeholders |
| :--- | :--- | :--- |
| `console` — the Admin | `backend/lang/interface/console/en.json` | `{{name}}` |
| `api` — API, validation and notification messages | `backend/lang/en.json`, `backend/lang/en/*.php` | `:name` |

The English files define every key. A key a developer adds to them *is* a catalogue entry: after deploy it appears in the workshop as not translated in every other language. No registration, no source, no migration.

The console catalogue lives in the backend's tree because the platform serves it. The Admin bundles only its English file, at build time, as the fallback that must render without the API.

Files shipped for other languages — Arabic today — stay the base for that language and are never rewritten.

### 3. Operators' translations are a database overlay

`interface_translations (catalogue, locale, key) → value` is written by the workshop, one row per key and language. Each row keeps the hash of the English text it was translated from.

* **Resolution.** A language's wording is its shipped file, if any, overlaid by its rows, overlaid on English key by key.
* **Deploys.** A deploy never touches a row.
* **Stale rows.** A deploy that changes an English string changes its hash. A row written against the old hash still displays, and the workshop counts that key as not translated, so it is translated again.
* **Fallback is display only.** A key a language has not translated is shown in English and remains **not translated** in the workshop.

Resolution runs in two places:
* **Backend.** A translation loader decorates Laravel's file loader and merges the overlay into JSON and group lines. `__()`, validation and notification messages are therefore translated without changing how they are written.
* **Admin.** A public endpoint, `GET /api/v1/interface/console/{locale}`, serves the merged catalogue. The Admin adds it to i18next when the language is chosen.

Both are cached per catalogue and language. Writing a translation, and changing any language, invalidates the application cache and the edge.

### 4. The workshop, through the one translation system

Two `TranslationSource`s are registered by Localization: **Interface — Admin Console** and **Interface — API Messages**. They use everything content uses (ADR 0056):
* `TranslationField` metadata
* item statuses and coverage
* translation batches
* AI generation
* human review
* accepting once, and accept all ready

**Items.** An item is a group of keys under one parent, for example `modules` or `translations.item`. Its fields are the keys.
* A sidebar, a dialog or a set of buttons is reviewed and accepted as one.
* The grouping is derived from the key, so a new menu needs nothing.

**Source language.** A source may declare the language it is translated from (`DeclaresSourceLocale`). The interface sources declare the catalogue's language, so interface wording is always translated from English, whatever the content default is.

**Permission.** The sources are read and written with `interface.translate`, a permission of its own: rewriting the console's wording is not editing settings.

### 5. Placeholders and plural forms are protected

The same set of placeholders must be present in a translation as in its source:
* `{{name}}` and `:name`
* Laravel's plural segments and ranges

A generation that changes the set fails. A write that changes it is refused.

**Plural forms.**
* **Workshop:** every plural form a key has in English is a field of its own.
* **Admin display:** a language that needs more plural forms than English fills the missing ones from `_other`.

### 6. Guarded

A test fails when the Admin uses a literal key that is not in the English catalogue. A dynamic key must have a catalogue branch to resolve in.

## Consequences

* Adding French is one operation. It appears in the top bar, and every interface and content item appears in the workshop as not translated. "Translate all missing" translates both, a person reviews and accepts item by item, and the console reads in French.
* Adding a menu is adding its English wording. After deploy the key is in the workshop in every language.
* Arabic keeps its shipped wording. An operator's change to it is a row, and survives every deploy.
* The Admin image is built with the catalogue from `backend/lang/interface`, so the two builds share one file instead of two copies.
