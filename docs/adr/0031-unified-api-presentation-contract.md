# ADR 0031: Unified API Presentation Contract

* **Status**: Accepted
* **Date**: 2026-09-04
* **Implementation**: Not started. This record establishes the decision; the work is tracked in ADR 0029.

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

Three rules govern all of it:

1. **The technical member never moves and never changes.** `scan_status` stays `scan_status` and keeps its backed value. Labels are added beside existing fields, so every current consumer keeps working.
2. **A label is always derived from the request locale**, through the chain ADR 0015 defines. The same endpoint returns a different label and an identical value to two clients asking in different languages.
3. **A label is never an input.** Requests are addressed by identifier. Nothing is looked up, matched, or authorized by its label, because a label is not unique, not stable, and not language-independent.

### The envelope is unchanged

`ApiResponse` keeps `{success, message?, data?, meta?}` and the error shape `{success:false, error:{code, message, details?}}`. `error.code` remains a technical identifier and is never localized (ADR 0015). Resources shape `data`; they do not touch the envelope.

## Alternatives considered

**Keep `present()` and standardise its output by convention.** Cheapest, and honest about the fact that six methods already exist. Rejected: a convention with no type behind it is what produced the current drift, and it gives ADR 0010's generator nothing to read.

**A single generic transformer** driven by model metadata. Rejected as premature — it would need a metadata layer that does not exist, and ADR 0008's metadata is for dynamic extensions, not for shaping every core payload.

**Localize the label inside the model** as an accessor. Rejected: it puts request-scoped locale state on a model that may be serialised in a queued job, where the request locale is not the recipient's locale — the distinction ADR 0019 already had to make for notifications.

## Consequences

One place per module decides what a client sees, so adding a label is one edit rather than six, and a new module has an existing shape to follow instead of a choice to re-make.

Six controllers must be converted, and each conversion is a chance to change a payload by accident. The API tests assert current shapes, which is what makes the conversion checkable — a payload that changes will fail a test rather than surprise a client.

ADR 0010's contract generation improves as a side effect: Resources are the input Scramble reads, so this phase's work is a prerequisite that pays for itself in the next one.

Until the conversion happens the two styles coexist. That is now a recorded state with an intended direction rather than an unexamined split.
