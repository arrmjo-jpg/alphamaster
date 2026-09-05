# ADR 0031: Unified API Presentation Contract

* **Status**: Accepted
* **Date**: 2026-09-04
* **Revised**: 2026-09-05 — `_options` naming fixed for a catalogue that accompanies an existing field
* **Revised**: 2026-09-05 — media references: how a variant is asked for and how the one actually served is reported
* **Implementation**: Implemented in Phase 13 Scope D. The six `present()` methods are gone and every payload named here is produced by an API Resource.

## Context

The audit of 2026-09-04 found two presentation styles in the same API, and no record of a choice between them.

`LanguageResource` is the only API Resource in the platform. It arrived in Phase 3 (`e0bf27e`) and was never adopted as a convention. Every module built afterwards — Authorization, Integration, Media, Notification, User — carries a private `present()` method inside its controller instead: six of them, each defining its own payload shape with nothing shared between them.

That is drift rather than a decision. No ADR chose either approach, and the two have simply coexisted since Phase 6.

It matters now because ADR 0030 requires a label beside a technical value in many payloads. With six bespoke `present()` methods there is no single place to add one, no shared naming, and nothing that stops the third module from inventing `display_name` while the first two used `label`.

There is a second reason. ADR 0010 makes Scramble infer the OpenAPI contract from the code, and Scramble reads Resources and typed returns. A controller returning a hand-built array declares nothing a generator can use, which is the difference between a contract that documents itself and 46 endpoints documenting an untyped `data`.

## Decision

**API Resources are the presentation layer. A controller does not shape a payload.**

Each module presents its models through `Illuminate\Http\Resources\Json\JsonResource` classes in its own `Resources/` namespace, alongside the `LanguageResource` that already does this. The private `present()` methods are technical debt to be replaced, not a second sanctioned style.

### Naming contract for labelled values

Where ADR 0030 adds a human label beside a technical identifier, the pairing uses one of exactly two shapes.

**A field on a larger object** keeps its existing name and gains a sibling suffixed `_label`:

```json
{
  "id": "01J...",
  "scan_status": "not_scanned",
  "scan_status_label": "لم يُفحص",
  "account_type": "admin",
  "account_type_label": "مدير"
}
```

**A catalogue entry**, where the client is choosing from a set rather than reading one field, is an object of `value` and `label`:

```json
{ "value": "sms_otp", "label": "رمز SMS" }
```

and where the technical identifier is itself a key with structure — a permission, a setting — the technical member is named for what it is:

```json
{ "key": "users.update", "label": "تعديل المستخدم" }
{ "name": "content_editor", "label": "محرر المحتوى" }
```

Where a catalogue accompanies an existing field rather than replacing it, the catalogue field keeps that field's name and gains the suffix `_options`: `available_methods` is joined by `available_methods_options`. The source field keeps its type and contents, and the catalogue holds one `{value, label}` entry per member, in the same order.

Three rules govern all of it:

1. **The technical member never moves and never changes.** `scan_status` stays `scan_status` and keeps its backed value. Labels are added beside existing fields, so every current consumer keeps working.
2. **A label is always derived from the request locale**, through the chain ADR 0015 defines. The same endpoint returns a different label and an identical value to two clients asking in different languages.
3. **A label is never an input.** Requests are addressed by identifier. Nothing is looked up, matched, or authorized by its label, because a label is not unique, not stable, and not language-independent.

### Media references name the variant they carry

ADR 0024 decided that a request for a variant an asset does not have is answered with the original, and that the response must state which variant was actually served. That is a presentation question, and this record answers it.

**`url` stays a plain string on the media resource.** `GET /api/v1/media/{id}` is served by `MediaResource`, where `url` is a nullable string meaning the asset itself. Rule 1 above applies to it like anything else: it does not become an object. Phase 13 added `_label` siblings to that resource and split the administrative view into `MediaAdminResource`, which carries no `url` at all — neither change touches the type of this field, and neither is affected by what follows.

**A media reference is how one payload points at a file it does not own** — an image on a content record, `og_image` in SEO metadata (ADR 0032), a logo behind a branding setting (ADR 0018). A reference is an object, and it names the variant it carries:

```json
{
  "media_id": "01J...",
  "url": "https://cdn.example/…",
  "variant": "original",
  "requested_variant": "thumbnail"
}
```

`variant` is what this URL actually is. `requested_variant` is what was asked for. When they differ, a substitution happened and the consumer can see it without comparing against a request it may no longer hold — which matters because these payloads travel: an SEO document is cached, an admin screen renders a list it fetched earlier, a client stores a response. A consumer that must remember its own question to notice it got a different answer is a consumer that will stop noticing.

Both fields are always present. When no variant was asked for, both name the default, so nothing branches on a key being absent.

Neither field is ever localized. Variant names are technical identifiers under ADR 0030 — `thumbnail` and `og` are contract, not wording — and a screen that needs to say *Thumbnail* in Arabic resolves that label from the identifier through the same mechanism every other label uses. Storage paths and disks continue to appear nowhere, as Phase 10 established.

### Asking for a variant

A caller names a variant by its technical identifier. Names are the contract; dimensions are not, and a caller that asks in pixels is coupling to configuration ADR 0024 reserves the right to change.

Three outcomes, and the first is the one ADR 0024 does not cover:

| Request | Result |
| :--- | :--- |
| A name the platform does not define | `422`, `VALIDATION_ERROR`. A caller bug, reported as one. |
| A defined name, in this asset's set, not yet produced | Original served, `variant` says `original`. |
| A defined name, not in this asset's set | Original served, `variant` says `original`. |

The first row is a real distinction rather than a formality. Asking for `thumbnial` is a typo in the caller; asking for `thumbnail` on a favicon is a correct request the platform has deliberately chosen not to satisfy. Answering both with the original would erase the difference and let a misspelling look like a working integration forever.

The second and third rows are deliberately indistinguishable in the payload. A consumer can do nothing useful with the difference — both mean *this is the original, use it* — and reporting it would leak internal pipeline state into a contract that has no business carrying it.
### The envelope is unchanged

`ApiResponse` keeps `{success, message?, data?, meta?}` and the error shape `{success:false, error:{code, message, details?}}`. `error.code` remains a technical identifier and is never localized (ADR 0015). Resources shape `data`; they do not touch the envelope.

## Alternatives considered

**Keep `present()` and standardise its output by convention.** Cheapest, and honest about the fact that six methods already exist. Rejected: a convention with no type behind it is what produced the current drift, and it gives ADR 0010's generator nothing to read.

**A single generic transformer** driven by model metadata. Rejected as premature — it would need a metadata layer that does not exist, and ADR 0008's metadata is for dynamic extensions, not for shaping every core payload.

**Localize the label inside the model** as an accessor. Rejected: it puts request-scoped locale state on a model that may be serialised in a queued job, where the request locale is not the recipient's locale — the distinction ADR 0019 already had to make for notifications.

**Report the served variant only when it differs from the request.** Smaller payloads, and the common case says nothing. Rejected: every consumer would branch on whether a key exists, and the absent key is indistinguishable from an older server that never sent it.

**A boolean such as `is_fallback`.** Rejected: it is derived from the two names and carries less — it says a substitution happened without saying what arrived, which is exactly the question a caller needs answered.

**Making `url` an object on the media resource itself.** The tidiest shape on paper. Rejected because it changes the type of a field that already ships, which rule 1 forbids and every current consumer would feel.

**Returning a map of every variant to its URL.** Rejected: a private asset’s URL is signed with a short TTL, so building all of them means minting credentials for files the caller did not ask for and may not be entitled to.
## Consequences

One place per module decides what a client sees, so adding a label is one edit rather than six, and a new module has an existing shape to follow instead of a choice to re-make.

Six controllers must be converted, and each conversion is a chance to change a payload by accident. The API tests assert current shapes, which is what makes the conversion checkable — a payload that changes will fail a test rather than surprise a client.

ADR 0010's contract generation improves as a side effect: Resources are the input Scramble reads, so this phase's work is a prerequisite that pays for itself in the next one.

Until the conversion happens the two styles coexist. That is now a recorded state with an intended direction rather than an unexamined split.
