# ADR 0032: SEO Foundation Contracts

* **Status**: Accepted
* **Date**: 2026-09-04
* **Implementation**: Not started, and deliberately not scoped to a phase yet. See *Implementation status* below.

## Context

No ADR records a decision about SEO, and no SEO code exists. The platform has a Settings engine, a Media module, and relational translations, all of which SEO would build on, but nothing states what SEO the foundation owns and what belongs to the project built on top of it.

That distinction is the whole difficulty. A news site needs article-level SEO; a competition platform needs something else entirely. A foundation that ships an `articles` assumption is no longer a foundation. A foundation that ships nothing leaves every project to invent its own metadata table, its own fallback rules, and its own Open Graph handling.

This record draws the line before either happens.

## Decision

**The foundation owns SEO contracts, storage and resolution. It owns no page types.**

### What the foundation provides

**Site-level SEO defaults**, as settings (ADR 0018): default title, default description, default Open Graph image, robots policy, canonical host. These are the values every project needs and no project defines differently in kind.

**A polymorphic, per-locale metadata store.** Any model can carry SEO metadata without the foundation knowing what that model is:

```
seo_meta
  id                ULID
  seoable_type      morph
  seoable_id        ULID
  locale
  title             nullable
  description       nullable
  canonical_url     nullable
  robots            nullable
  og_title          nullable
  og_description    nullable
  og_media_id       nullable → media_files
  twitter_title     nullable
  twitter_description nullable
  twitter_media_id  nullable → media_files
  UNIQUE (seoable_type, seoable_id, locale)
```

Per-locale rows rather than columns, for the same reason ADR 0015 gives: the platform's language set is administrator-managed, so `title_ar` / `title_en` columns would require a migration to add a language.

**A resolution contract** that answers "what are the SEO values for this thing, in this locale" and applies the fallback chain. **A structured-data contract** returning a JSON-LD document for a model, with the foundation supplying `WebSite` and `Organization` from site settings and the project supplying its own types. **Sitemap and robots.txt contracts** — the foundation defines the interface and serves the output; what belongs in a sitemap is a project question.

### What the foundation does not provide

Article SEO, category SEO, product SEO, breadcrumb hierarchies, or any assumption that a project has pages of a particular kind. A project implements the contracts for its own models. See ADR 0033.

### Fallback chain

Resolution is explicit and ordered, and each step is recorded here so that two projects do not implement it differently:

```
title        → per-locale SEO title → the model's own title → site default title
description  → per-locale SEO description → the model's excerpt/summary → site default description
og_image     → per-locale og_media_id → the model's featured media → site default OG image
og_title     → og_title → resolved title
robots       → per-locale robots → site robots policy
canonical    → canonical_url → the model's own URL
```

A locale with no row falls back to the platform default locale before falling back to the site default value, which is the chain ADR 0015 already defines for translations rather than a second one invented here.

### SEO references media; it never processes it

An SEO image is a reference to a `MediaFile` plus a named variant, never a path, never an upload of its own:

```
og_image → media_id → MediaFile → variant "og" → CDN or signed URL
```

SEO does not resize, crop, or re-encode. The variant is produced by the Media pipeline (ADR 0024) and SEO asks for it by name. If the `og` variant does not exist yet, SEO resolves to the original, because a correct URL to an unoptimised image is better than a broken one.

## Alternatives considered

**A `seo` JSON column on each model.** Simplest, and avoids a join. Rejected: it cannot be queried or indexed per locale, and it repeats the JSON-versus-relational argument ADR 0015 already settled for translations.

**Per-locale columns** (`title_ar`, `title_en`). Rejected: incompatible with runtime-managed languages, which is the point of ADR 0015.

**Letting SEO own its own images.** Rejected: it would duplicate storage, scanning and CDN handling that ADR 0024 already owns, and produce images the Media module does not know exist.

**Deferring SEO entirely until a project needs it.** Tempting, and the reason nothing exists today. Rejected as a *decision* — the contracts have to be settled before a project builds on them, or the first project's assumptions become the foundation's by default. Deferring the *implementation* is a separate matter and is what this record does.

## Consequences

A project can add SEO to its own models without extending the foundation, and two projects on this platform resolve SEO the same way.

The store is one table with a morph key, so SEO for a model that has none costs nothing.

**Implementation status.** Nothing here is built. The scope is deliberately left unphased: SEO is worth implementing when there is a consumer whose requirements can test the contracts, and implementing it against no consumer is how a foundation acquires speculative surface — the mistake ADR 0024 already recorded twice, with the MediaLibrary packages and the unused attachment trait. Tracked in ADR 0029.
