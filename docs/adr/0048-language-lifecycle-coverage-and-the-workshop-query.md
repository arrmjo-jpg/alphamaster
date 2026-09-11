# ADR 0048: Language Lifecycle, Translation Coverage, and the Workshop's Query Contract

* **Status**: Accepted
* **Date**: 2026-09-11
* **Amends**: ADR 0043
* **Leaves unchanged**: ADR 0015 (locale negotiation), ADR 0044 (AI proposes, a person accepts)

## Context

An audit of the language workflow found the parts worked and the whole did not.

* Adding a language created a row that was **served immediately** — the column defaults to active — so a new language went live at 0% translated.
* The translation workshop only offered **active** languages as targets, so there was no way to translate a language *before* serving it. The two constraints together forced "publish first, translate after".
* The workshop computed completeness per source; the Languages page showed none. There was no single answer to "how far has French got?".
* The workshop returned **every entry of every source in every active language** in one response and filtered on the client. Correct at today's size, and a shape that cannot grow.
* Translation writes were not in the audit trail, except settings, which the settings service records as value changes. Accepting an AI suggestion recorded nothing about who accepted it.

## Decision

### 1. An inactive language is a draft

`is_active` already means **served**, and nothing else: locale negotiation accepts only active languages (ADR 0015 §2), the public language list returns only active ones, and the active-language cache holds only those. An inactive language is never negotiated, never listed to clients, never a fallback.

That is exactly the draft state, so **no new status is introduced**. A separate `draft` flag would create a fourth combination — draft *and* active — that means nothing, and a second place to ask whether a language is live.

| | Exists | Translatable in the workshop | Served (negotiated, listed, fallback) |
| :--- | :---: | :---: | :---: |
| Inactive ("draft") | ✓ | ✓ | ✗ |
| Active | ✓ | ✓ | ✓ |

The Admin presents inactive as **Draft — not served**, and **creates new languages as drafts by default**, with an explicit choice to serve immediately. The API is unchanged: `is_active` is still optional and still defaults to true when omitted, so any client relying on that keeps its behaviour. The default language's rules are unchanged: it must be active, and making a language the default activates it.

### 2. The workshop and AI suggestions target any language the platform knows

Amends ADR 0043, whose workshop offered only active languages. A write or a suggestion request now names any existing language; a code that is not a language is still refused (`UNKNOWN_LOCALE`). The source is still the default language, which is always active.

### 3. Coverage is one calculation

`Localization\Services\TranslationCoverage` is the only place coverage is computed. The workshop's counts and the Languages page read it; nothing else counts.

* **Fields, not items** (ADR 0043 §4, unchanged).
* **Only saved text counts.** An unreviewed AI suggestion is not a translation; nothing falls back.
* **Over the sources the caller may view.** A count of untranslated notification wording is a statement about content an operator without `notifications.view` was not granted (ADR 0043 §3), so coverage is per caller, not global.

### 4. The workshop is queried, not downloaded

`GET /admin/translations` takes a **target** language and returns one page of entries for it, filtered and searched on the server:

* `state`: `all`, `missing` (a field has no text), `translated` (every field has text), `needs_review` (a field has an AI suggestion ready), `failed` (a field's suggestion failed). These are the states the platform stores; nothing is invented for the filter.
* `search`: matched against the item's title, its context, and the source and target text.
* `source`, `page`, `per_page` (at most 100).

Each field carries only the source and target values. The response also carries the target's coverage, overall and per source.

**The boundary, stated.** Sources still enumerate their content in memory on each request (ADR 0043, *`entries()` reads everything*). That is fine at today's catalogue — tens of roles, a handful of templates, ten localized settings, under a hundred fields — and it keeps the browser to one page regardless. It stops being fine at roughly **ten thousand fields in total, or a few thousand in one source**. At that point `TranslationSource` gains paged enumeration and counting at the source, and the controller stops counting in PHP. The query contract above does not change when that happens, which is the point of fixing it now.

### 5. One overview for the Languages page

`GET /admin/translations/overview` returns, per language: coverage (from §3), and the number of AI suggestions in each state the platform stores — waiting, ready for review, failed, accepted, discarded — counted over the content the caller may write. It also says whether AI is available, and whether the caller may use it. No new state is invented: *processing* is not a state the platform records, so it is not shown.

### 6. Translations are audited

`translation.updated` is recorded when a workshop save or an accepted suggestion changes content:

* **actor** (from the request), **subject** `source/item`, **locale**, the **field names** that changed;
* **origin**: `manual` or `ai`, and for `ai` whether the person **edited** the suggestion before accepting.

No text — neither the old wording nor the new — and no prompt or vendor detail (ADR 0037's rule, the same one that keeps addresses out of `account.updated`). A save that changes nothing records nothing.

Suggestions record **`requested_by`** (already) and **`accepted_by`** (new).

Settings are also recorded by the settings service as `setting.updated` (ADR 0038). The two records describe different things — a value changed; somebody translated something — and both stay.

## Consequences

The workflow an operator expected is the one the platform supports: add a language as a draft, translate it by hand or with AI, watch coverage rise, activate it when it is ready.

Translating a draft language is invisible to every client until it is activated, because activation is the only thing negotiation reads.

A client of the old workshop shape breaks: the index now requires reading a target's page rather than the whole catalogue. The only client is the Admin, changed in the same commit, and the contract is published through OpenAPI.

## Not decided here

* **Interface strings** — the console's own wording and API messages — remain code-owned catalogues. That is a separate translation domain and needs its own decision (ADR 0049, proposed).
* **Batched AI requests.** A request queues one job per missing field from inside the HTTP request. That is right for hundreds of fields; at the §4 threshold it becomes one job that enumerates and fans out in chunks.
