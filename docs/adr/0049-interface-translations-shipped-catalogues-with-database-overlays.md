# ADR 0049: Interface Translations — Shipped Catalogues with Database Overlays

* **Status**: Proposed — not implemented
* **Date**: 2026-09-11
* **Relates to**: ADR 0015, ADR 0043, ADR 0048

## Context

The platform has **two translation domains**, and until now only one was named.

| | Content translations | Interface translations |
| :--- | :--- | :--- |
| Examples | Role labels, notification wording, localized settings | "Dashboard", "Save", "Sign out", API error messages, setting labels |
| Where it lives | Database, owned by the module the content belongs to | `backend/lang/{en,ar}.json`, `admin/src/i18n/{en,ar}/common.json` |
| Who changes it | Operators, through the workshop (ADR 0043) | Developers, in a commit |
| Adding a language | Workshop shows it as 0% and it can be translated (ADR 0048) | **Nothing.** The Admin ships `en` and `ar` only; a new language falls back to English everywhere |

ADR 0043 kept language files out of the workshop on purpose: "making them editable at runtime would make a deployment able to silently revert an operator's edit". That concern is real and still has to be answered. But the consequence is that a platform which lets an operator add French cannot let anyone read its own console in French without a code change and a rebuild.

## The question

Can operators translate interface strings into a new language at runtime — without a rebuild, without breaking the Admin's bundled catalogue, without a deploy able to undo their work, and with English remaining the explicit fallback?

## Proposal

**Shipped base catalogue + database overlay = runtime catalogue.**

1. **The shipped files stay the base and stay authoritative for the languages they ship.** English is the source and the final fallback. Arabic stays intact. Nothing writes to the files.
2. **Overlays live in the database**: one row per language, catalogue and key — `(catalogue, locale, key) → value`, with the **hash of the English source text** the translation was written against.
3. **Runtime merge, never file generation.**
   * *Backend*: a translation loader that decorates Laravel's file loader, merging a language's overlay over its file (or over nothing, for a language with no file), cached per language in the platform cache (ADR 0035) and invalidated on write.
   * *Admin*: the bundled `en`/`ar` catalogues remain; on selecting a language, the console fetches that language's overlay from an unauthenticated read endpoint and merges it with `i18n.addResourceBundle`. i18next already falls back to `en` key by key, so a partially translated French console shows French where it exists and English elsewhere — explicitly, not by accident.
4. **Stale detection answers ADR 0043's concern.** A deploy that changes an English string changes its hash; overlays written against the old hash are shown as **needs review** rather than silently kept or silently lost. A deploy can never revert an overlay, because it never touches one.
5. **In the workshop, as their own sources.** "Interface — console" and "Interface — API messages" become `TranslationSource`s, declared by Localization, stored in the overlay table, and kept separate from content sources in the UI. The two domains share the workshop and the coverage calculation (ADR 0048) and do not share storage.

## What blocks implementation — the decisions this needs

1. **Where the Admin's key catalogue comes from.** The backend has `lang/en.json`; it does **not** have `admin/src/i18n/en/common.json`, which lives in the Admin's source and build. For the workshop to list console strings, that catalogue has to reach the backend — most simply by copying it into the backend image at build time as a read-only manifest. That couples the two builds, and it is the decision that most needs making.
2. **Whether shipped languages can be overridden.** Letting an operator override shipped Arabic is a different product from letting them add French. Recommendation: overlays apply only where the shipped file has no value, until there is a reason to do otherwise.
3. **Permission.** A new `interface.translate` permission, or reuse of an existing one. Recommendation: a new one — rewriting the console's wording is not the same power as editing settings.
4. **Keys that are not text.** Plural forms and interpolation placeholders must survive translation; the workshop has to validate that `{{count}}` in English is still present in French.

## Options considered

* **Status quo.** Honest, and the gap stays: a new language is a content language only.
* **Generate files and rebuild.** Rejected: needs a deploy to add a translation, which is the thing being avoided.
* **Replace the shipped files with the database.** Rejected: the platform would need a populated database to render its own sign-in screen, and English would stop being a guaranteed fallback.

## Consequences if accepted

Adding French becomes a complete operation: content and interface both appear in the workshop at 0%, both can be filled by hand or by AI suggestions (ADR 0044), and the console is readable in French as soon as its overlay has text. Until this is accepted and built, the Languages page states plainly that a new language's interface falls back to English.
