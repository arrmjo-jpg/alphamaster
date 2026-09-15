# ADR 0058: The SEO Platform — Public Addresses, Sitemap, Robots, Site Defaults, Structured Data and Media Invalidation

* **Status**: Accepted
* **Date**: 2026-09-15
* **Amends**: ADR 0032 (fallback chain, Twitter fields, contracts), ADR 0055 §7 (resolution table)
* **Builds on**: ADR 0024 (media), ADR 0033 (foundation boundary), ADR 0036 and ADR 0053 (edge cache), ADR 0041 (API-only topology), ADR 0052 (module extension points)

## Context

After ADR 0055, SEO was a Core capability for storage and resolution: one polymorphic `seo_meta` table, one write path, one set of rules, and one Admin editor. An audit on 2026-09-15 found the rest of what search needs was missing:

* **Addresses.** No public address could be composed for any content. The platform is API-only (ADR 0041) and the site is rendered elsewhere, so there was:
  * no default canonical link
  * no absolute hreflang alternate
  * no `x-default`
* **Documents.** No sitemap and no public robots.txt. The backend's `public/robots.txt` is never served, because the edge proxy sends `/` to the Admin container.
* **Site defaults.** None were applied in resolution. ADR 0032 and ADR 0055 §7 also disagreed about them.
* **Structured data.** None, though ADR 0032 had decided a contract.
* **`twitter_*`.** Decided in ADR 0032, and never needed.
* **Media.** Deleting a file used as a sharing image left cached pages naming it for up to an edge TTL.

Every one of these waited on one question: **who composes a public address?**

## Decision

### 1. Public addresses: each module declares a pattern, Core composes the address

`PublicUrlContract` (Core) holds one `PublicRoute` per content type:

```
pages  /{locale}/pages/{slug}
team   /{locale}/team/{slug}
```

* **Module responsibility:** declare its pattern from its own provider, like permissions and cache profiles (ADR 0052). A pattern starts with `/{locale}/`, so every language has an address of its own. Declaring the same type twice with a different pattern is an error.
* **Core responsibility:**
  * compose `origin + path`, percent-encoding every parameter, so an Arabic slug is a valid address
  * use `general.frontend_url` as the origin, read on every call
  * compose nothing when no origin is configured: no address is invented
* **Public API:**
  * content responses carry `url`
  * every `alternates` entry carries `url` beside `locale` and `slug`
* **Canonical:** by default, the content's own address in its own language. `seo_meta.canonical_url` stays an operator override.
* **The frontend follows the patterns.** It does not compose canonical, hreflang or sitemap addresses.

### 2. The sitemap is served by the API from sources modules register

`SitemapSource` (Core) is registered with `SitemapRegistry`:
* `key()`
* `count()`
* a lazy `entries()` of `SitemapEntry`. Each entry carries:
  * the absolute URL
  * `lastmod`
  * alternates per locale
  * the default-language address
  * resolved robots
  * any canonical override

**A module decides what it publishes:**
* Live content only, in served languages only.
* Pages lists published pages whose publication time has come. Team lists active members.
* Drafts, archived content and inactive languages are never read.

**Core decides how it is written.** `SitemapRenderer`:
* skips an entry that is `noindex` or whose canonical points elsewhere
* writes at most **50,000** addresses per file
* adds `xhtml:link` alternates and `x-default`
* writes a `sitemapindex` naming every file

**Routes**, under `/api/v1` because the platform is API-only:

| Route | Serves |
|---|---|
| `/api/v1/sitemap.xml` | the index |
| `/api/v1/sitemaps/{source}-{n}.xml` | one file |

* **The frontend or edge serves them at the site root:** `/sitemap.xml` and `/sitemaps/...`. The index names files at the public origin.
* **Without an origin:** both answer 404. A sitemap of relative addresses is not one.
* **Cache:** the `seo` profile, not localized. Tags:
  * `settings:public`
  * `localization:languages`
  * `sitemap:index`
  * `sitemap:{source}`
* **Invalidation:** a content write purges its source tag and the index.

A future module adds a source. The renderer does not change.

### 3. The public robots.txt is served by the API and depends on the environment

`GET /api/v1/robots.txt`, served by the frontend or edge at `/robots.txt`.

* **Outside production:** `User-agent: *` and `Disallow: /`, always, whatever is configured.
* **In production:**
  * allow crawling
  * `Disallow: /api/v1/admin`
  * the operator's `seo.robots_extra`
  * `Sitemap: {origin}/sitemap.xml` when an origin exists
* **Extra rules are filtered.** Only `User-agent`, `Allow`, `Disallow`, `Crawl-delay` and `Sitemap` lines are written. Anything else, markup included, is dropped.
* **The Admin console is unchanged:** `/robots.txt` answers `Disallow: /`, and every response carries `X-Robots-Tag: noindex, nofollow`.

### 4. Site-level defaults, in the same language only

`SiteSeoDefaultsContract` (Core). Settings implements it, because Core may not depend on Settings. Core binds a null implementation that invents nothing.

**Resolution**, which replaces both ADR 0032's chain and ADR 0055 §7's table:

| Value | Order |
|---|---|
| title | SEO title → content title → `general.site_name` in this language |
| description | SEO description → content summary → `general.site_description` in this language |
| og image | SEO image → content image → `branding.og_image` |
| robots | SEO robots → `seo.robots_policy` → `index,follow` |
| canonical | SEO canonical → the content's own address in this language |

* **No step leaves the language.** A localized default is that language's own translation.
* **The provisioned base value** answers only for the default language. A setting's ordinary read falls back across languages (ADR 0015); this read deliberately does not.
* **New settings (group `seo`):** `seo.robots_policy` and `seo.robots_extra`. Both are public and read by the platform, and the Admin Settings screen shows them with no new screen.
* **Reach changes:** `general.frontend_url`, `general.site_description` and `branding.og_image` move from *awaiting* to *platform*.

### 5. Structured data: Core supplies the site, modules supply their types

`StructuredDataGenerator` (Core) returns one schema.org node for a content type. `StructuredData` holds the generators and builds:

```
{ "@context": "https://schema.org",
  "@graph": [ WebSite, Organization, <the content's node> ] }
```

* **Core:**
  * `WebSite` and `Organization` from site settings, in the content's language, at the public origin
  * `isPartOf` linking the content to the site
  * every text value made plain text (markup stripped) and every empty value omitted
* **Modules:** Pages registers `WebPage` and Team registers `Person`.
* **Not built here:** `Article`, `SportsEvent` and competition types. They arrive with their modules.
* **Public API:** content responses carry `structured_data`. The backend builds it because it composes the addresses; the frontend places it in the page.
* **Leakage:** a generator receives only resolved, public values. A draft is never read, and a private file never resolves.

### 6. No Twitter/X storage

`twitter_*` fields are not built, and ADR 0032's are withdrawn. Open Graph is the sharing metadata. Resolution derives `twitter_card`: `summary_large_image` when there is a sharing image, `summary` otherwise. X reads the Open Graph title, description and image.

### 7. Media invalidation through a registry of references

`MediaReferencer` (Core) answers "which public responses show this file?" with edge tags. `MediaReferenceRegistry` asks every registered referencer.

* **Media:** when a file is deleted, fails scanning or processing, is found infected, or changes visibility, it asks the registry and purges the returned tags after commit. Media names no consuming module.
* **Modules:**
  * Pages answers for pages whose sharing image, in any language, is the file.
  * Team answers for members whose picture or sharing image it is, and adds the directory when a picture is involved.
* **A replaced reference** is a content write, and purges through the content's own path.

## Responsibilities

| | Core | A module |
|---|---|---|
| Addresses | composition, encoding, origin | its `PublicRoute` |
| Sitemap | registry, chunking, XML, indexability, routes, cache | its `SitemapSource`: what is live, in which languages |
| Robots | environment rule, filtering, route | nothing |
| Defaults | resolution order, locale strictness | the content's own title, summary and image |
| Structured data | graph, site nodes, plain-text rule | its generator |
| Media | registry, purge after commit (Media) | its `MediaReferencer` |

## Alternatives considered

**The frontend composes addresses.** Rejected: a sitemap, an hreflang alternate and a canonical link all need absolute addresses at the backend, and 301 redirects already live in the API.

**A sitemap per module with its own route.** Rejected: every module would reimplement chunking, alternates and indexability.

**Falling back across languages for site defaults.** Rejected, as in ADR 0055 §3: an Arabic page presenting the English site name as its title is a wrong answer, not a fallback.

**Storing `twitter_*`.** Rejected: a second copy of the same metadata that nothing reads differently.

**Letting a sharing image expire from the edge.** Rejected: a deleted or infected image would go on being shared for up to an hour.

## Consequences

* **Future modules** get addresses, sitemap entries, hreflang, structured data and media invalidation by registering four small classes. Core does not change.
* **The frontend** must serve `/robots.txt`, `/sitemap.xml` and `/sitemaps/*` from the API's `/api/v1` equivalents, and render `seo`, `alternates` and `structured_data` as given.
* **Public content responses** gain `url`, `alternates[].url`, `seo.twitter_card` and `structured_data`. `seo.robots` is always a value now.
* **Sitemap scale:** `count()` and `entries()` iterate lazily in chunks of 500. A source of hundreds of thousands of items costs a full pass per file. If that becomes a problem, a source can keep a count of its own; the contract does not change.
