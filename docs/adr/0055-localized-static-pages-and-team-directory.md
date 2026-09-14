# ADR 0055: Localized Static Pages and the Team Directory

* **Status**: Accepted
* **Date**: 2026-09-14
* **Amends**: ADR 0033 (foundation boundary), ADR 0015 (reading a translation), ADR 0032 (SEO fallback)
* **Implements**: ADR 0032 (`seo_meta`, its first consumer)
* **Extends**: ADR 0043/0048 (translation sources and coverage), ADR 0052 (module extension points), ADR 0053 (edge delivery)

## Context

Every site built on AlphaMaster needs two kinds of content that do not depend on what the site is about:

* **Pages** that are written once and linked from the header or footer: privacy policy, terms, about, contact, advertise.
* **A team directory**: the people behind the site, each with a profile.

ADR 0033 lists "pages" and "publication states" as project-specific. Its test is whether a different kind of application would need the thing in this shape. A news site, a competition platform and a tools site all need a privacy policy and an about page with the same fields and the same lifecycle. Leaving these to each project means every project rebuilds them, and every one decides localization, slugs and SEO differently.

The reference news platform built both, and showed what goes wrong:

* **Pages** were one row per language, linked by a translation group. Status, ordering and header placement were copied into every row and drifted apart.
* **Languages** were a constant in code: `ar` and `en`.
* **The team** was single-language, with its name, title and biography as plain columns.
* **Settings** used `site_name_ar` and `logo_light_en` columns.

It also got some things right, and those are kept: a slug per language that keeps Arabic letters, a history of old addresses answered with a permanent redirect, and HTML sanitised on write.

## Decision

### 1. Two foundation capabilities, amending ADR 0033

**Static pages and the team directory are foundation capabilities**, in two modules: `Pages` and `Team`. Each is independent of the other, and in the Admin both sit under one navigation group, *Static Pages*.

ADR 0033's line still holds for everything else. These modules carry no domain meaning:

* no categories, no authors as a concept, no editorial workflow;
* a page has three states: `draft`, `published`, `archived`;
* a team member is `active` or not.

A project that needs articles, reviews or an approval process builds its own module.

### 2. One record, relational translations, languages from Language Management

A page is one row. A team member is one row. Their text is in `page_translations` and `team_member_translations`, one row per locale, `UNIQUE(owner, locale)`, through `HasTranslations` (ADR 0015).

| | Localized (per locale) | Not localized |
| :--- | :--- | :--- |
| **Page** | title, slug, summary, body, SEO | status, published_at, sort_order, authorship, timestamps |
| **Team member** | name, position, bio, slug, SEO | avatar, is_active, sort_order, social links, timestamps |

* There are no locale columns, and no row per language.
* Adding a language in Language Management makes it a translation target at once, with no migration and no code change.
* A locale is stored as a code and validated against the languages the platform knows. It is not a foreign key, so removing a language never deletes content written in it.

### 3. Reading is strict: no silent fallback

ADR 0015 lets a translation read fall back to the default language, then to any translation. That suits labels and settings copy, where showing something beats showing nothing. **It is wrong for content that has its own address.** A privacy policy shown in English at the French address tells a reader that is the French policy.

`HasTranslations` gains a strict read, `translationIn($locale)`, which returns the row for that locale or nothing. Pages and Team read only through it. The fallback read is unchanged for everything that uses it today.

### 4. Availability: page status and translation completeness are separate

**A page is published or not.** Its translations are complete or not. The two never overwrite each other.

**Publishing needs a complete translation in the default language.** A missing or incomplete translation in any other language never blocks publishing, and never unpublishes a page.

A translation is **complete** when:
* page: title, slug and body are non-empty;
* team member: name, position and slug are non-empty.

**Content is public in a locale only when all of these hold:**
* the language is active;
* the page is published and its `published_at` has passed, or the member is active;
* the translation in that locale is complete.

A language that is inactive (a draft, ADR 0048) can be written but is never served.

### 5. The public API names the content language, and it is required

```
GET /api/v1/pages?locale={code}
GET /api/v1/pages/{slug}?locale={code}
GET /api/v1/team?locale={code}
GET /api/v1/team/{slug}?locale={code}
```

* **`locale` is required.** It is not negotiated from `X-Locale`, `Accept-Language` or the account's preference. Those still decide the language of messages and labels; they never decide which content is returned.
* **Missing or not served:** a missing `locale` is a 422. A `locale` that is not an active language is a 404 `CONTENT_LOCALE_NOT_SERVED`.
* **Not available in that language:** content that exists but is not available in the requested language is a 404 `CONTENT_NOT_AVAILABLE_IN_LOCALE`. Its details list `available_locales`, the active languages it is available in. Nothing is substituted.
* **Alternates:** a response carries `alternates`, a `{locale, slug}` pair for every other language it is available in, for `hreflang`.

Because the language is in the address, ADR 0053 allows these responses at the edge.

### 6. Slugs are per language

The slug lives in the translation row: `UNIQUE(locale, slug)`.

* The generator keeps letters in any script, so Arabic stays Arabic, and falls back to a transliteration only when nothing is left.
* The same slug may be used in every language, because uniqueness is only within a language.
* A published translation whose slug changes records the old one in `{entity}_slug_history (owner, locale, slug)`. A request for the old slug in that language is answered `301` with the current address. A slug that is in use again is never redirected.

### 7. SEO: ADR 0032's store, localized, with no cross-language fallback

`seo_meta` is implemented in Core, with pages and team members as its first consumers. It is polymorphic, with one row per locale.

**Resolution never leaves the locale:**

| Value | Resolves from, in order |
| :--- | :--- |
| title | per-locale SEO title → the translation's title (or name) → the site's default title in that locale |
| description | per-locale SEO description → the translation's summary (or position) |
| robots | per-locale robots → site policy |
| og image | per-locale og media → the member's avatar |

This amends ADR 0032's rule that a locale with no row falls back to the default locale first.

### 8. The Admin: one editor, a content-language selector, translation status

**A page or member is edited in one screen:**
* the non-localized fields;
* a content-language selector listing every language the platform knows, the default first;
* each language's status: **Translated**, **Incomplete** or **Not translated**, and **Draft — not served** for a language that is not active.

Choosing another language shows that language's fields. They are empty where nothing is written. The default language's text is never placed in them, which is the same rule as `draft.ts` in Settings (ADR 0043).

**Display and content languages stay apart.** The console's display language stays what the operator reads in, and the content language is its own parameter (`?locale=` on reads, the path on writes), exactly as in Settings.

This is the same case as ADR 0043 §4a's exception for settings: content an author writes directly in the target language. The selector and status list are shared components used by Settings, Pages and Team, not three implementations.

### 9. The translation workshop and coverage

`PageTranslationSource` and `TeamTranslationSource` are registered with `TranslationRegistry`. Pages and members therefore appear in the workshop and in the Languages page's coverage without a change to Localization.

**Fields offered** (amended by ADR 0056, which makes each field's metadata the contract):
* pages: title (required), summary, body (required, HTML), SEO title, SEO description;
* members: name (required), position (required), bio (HTML), SEO title, SEO description.

SEO fields are optional, so an item without them is still translated.

**Slugs are not offered, and are never sent to an AI provider.** A slug is an address. It is set in the editor, where its uniqueness can be checked, and a translation written without one is given one from its title or name in that language.

**Scale.** `entries()` reads every item. That is fine at the scale these modules are for, tens of pages and members. ADR 0048 §4's threshold applies if that changes.

### 10. Permissions, audit, cache, media

**Permissions** are declared by each module (ADR 0052):

| Module | Permissions |
| :--- | :--- |
| Pages | `pages.view`, `pages.create`, `pages.update`, `pages.publish`, `pages.delete` |
| Team | `team.view`, `team.create`, `team.update`, `team.delete` |

**Audit records who changed what, never the text:**
* `page.created`, `page.updated`, `page.published`, `page.unpublished`, `page.archived`, `page.deleted`
* `team_member.created`, `team_member.updated`, `team_member.deleted`
* for a translation, the locale and the field names that changed

Workshop writes keep `translation.updated` (ADR 0048).

**Cache:**
* Public responses use a `public-content` profile (ADR 0053), localized, with tags `pages:{id}`, `pages:list`, `team:{id}`, `team:list`.
* Every write purges the owner's tags after commit.
* No application cache is added; the tables are read directly.

**Media:**
* Pages and Team never import Media (the architecture tests enforce it).
* A member's avatar and an og image are stored as media ids and resolved through `MediaReferenceContract`, a Core contract Media implements, like `ProfileAvatarContract`.
* Only public images that are ready to serve are accepted.

**HTML:** bodies and biographies are sanitised on write with `symfony/html-sanitizer`, to an allow-list of structural and inline elements. Scripts, event handlers, styles and non-http(s) links are removed.

## Alternatives considered

**One row per language with a translation group.** Rejected, for the reason the reference platform shows. Everything that is not language is copied into every row and drifts, and "the page" has no identity to publish, order or delete.

**Locale columns (`title_ar`).** Rejected by ADR 0015 and ADR 0032. A language added at runtime would need a migration.

**Fall back to the default language when a translation is missing.** Rejected for addressable content (§3). A reader cannot tell a fallback from a translation.

**Negotiate the content language from `X-Locale` or `Accept-Language`.** Rejected (§5). The same address would serve different content, an edge could not store it, and the console's language would silently become the content's.

**One slug for all languages.** Rejected as the only option. A title in Arabic does not produce a usable English address. Per-language uniqueness still lets an operator use one slug everywhere.

**Build these per project.** Rejected (§1). Every project needs them in this shape, and localization, slugs and SEO are exactly what should not be decided twice.

## Consequences

* A project gets localized static pages, a team directory, per-locale SEO and edge delivery without writing them.
* Adding a language is a Language Management action. The new language appears in every editor's selector, in each item's translation status and in the workshop.
* A page can be live in two languages and missing in a third, and each address says exactly that.
* Content in a draft language can be written and reviewed before the language is served.
* `seo_meta` exists, so a later module attaches SEO without a migration of its own.
