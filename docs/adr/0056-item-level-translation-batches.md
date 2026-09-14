# ADR 0056: Item-Level Translation Batches

* **Status**: Accepted
* **Date**: 2026-09-14
* **Amends**: ADR 0043 (§4 coverage, §6 one item per request), ADR 0044 (§5 the unit of review — human review is kept), ADR 0048 (§3 coverage, §5 overview), ADR 0055 (§9 fields offered)

## Context

The workshop translated fields, not items. Every part of the flow worked one field at a time:

* **Suggestions.** Each field got its own suggestion, its own job and its own **Accept** button.
* **Accepting.** Each accept wrote one field through `TranslationSource::write`. A notification template refuses a subject without its body. Accepting a French subject on its own therefore failed with 422 `template_needs_both`, even though the body's suggestion was ready beside it.
* **Coverage.** Coverage counted fields. A page without an SEO description counted as unfinished, and the operator saw "7 of 12 fields" rather than "3 of 5 items translated".
* **Asking.** An operator could ask for a whole language, but not for one source.

The generation had the same problem:

* Every field got the same instruction for short interface text, with a fixed 512-token ceiling.
* An HTML page body was translated as if it were a button label, and nothing checked whether its tags and links survived.

Pages and Team (ADR 0055) were about to be built on this flow. Their items have required fields, optional SEO fields, rich text, and a slug that must never be decided by a model.

## Decision

### 1. The item is the unit

An item — a role, a template, a setting, a page, a team member — is what is translated, reviewed, accepted and counted. A field is never something an operator is asked about, and no endpoint names one on its own.

### 2. `TranslationField` metadata is the contract

A source describes each field, and the workshop, the coordinator, the prompt and the Admin read only that description. None of them ever looks at a field's name.

| Property | Meaning |
| :--- | :--- |
| `name`, `label` | the owning module's attribute, and a translation key for it |
| `required` | whether the item is incomplete in a language without it |
| `type` | `plain_text` or `html` |
| `group` | `content` or `seo` |
| `maxLength` | the most characters the owner accepts |
| `translatable` | false for a field shown for context and never sent |
| `meta` | anything a later consumer needs, without a contract change |

A module added later registers a `TranslationSource` that describes its fields, and it is translated with no change to Localization.

### 3. An item has one status in a language

`TranslationItemStatus` has six cases.

* **From what is written:**
  * `not_translated`: nothing written.
  * `incomplete`: some required field is empty.
  * `translated`: every required field has text.
* **From the item's open translation batch:**
  * `pending`
  * `ready`
  * `failed`

Optional fields never affect the status. A page with a title and body in French and no SEO is **translated**. An SEO field that a source later marks `required` counts without any other change. Progress is shown as filled out of all translatable fields ("3 / 5"). Coverage (ADR 0048 §3) now counts translated items out of items.

### 4. `translation_batches` is a real record

A batch holds:

* the target: source, item and locale
* its status: `pending`, `ready`, `failed`, `accepted` or `dismissed`
* field counts: total, ready and failed
* the first failure's reason, already redacted
* who requested it and who accepted it
* timestamps

There is **one batch per source, item and locale**. Pressing Translate for an item whose batch is pending or ready returns that batch, and nothing new is generated. A unique constraint holds this line even when two requests race past the check.

Within a batch, each field is still a `translation_suggestions` row with its own job, carrying the field's metadata. The operator never sees that granularity, only the batch.

The batch's status follows from its fields, recounted under a lock whenever a field job finishes:

* **pending** while any field is still being generated
* **failed** if any field failed
* **ready** when every field came back

A failed batch cannot be accepted. Asking for the item again retries it. The fields that came back, and still match what they were generated against, are kept and not paid for twice.

### 5. Translate: one item, one source, or everything missing

`POST /admin/translations/batches` takes a locale and, optionally, a source and an item. There is no field.

* **"Translate all missing"** starts a batch for every item the requester may write that is not translated. It asks no question per item or per field. An empty optional field does not make a translated item a task.
* **Naming one item** also includes its empty optional fields.
* **The target** is the locale in the request, always. `X-Locale` and `Accept-Language` choose only the language of the reply's messages. The default language is refused as a target.
* **Nothing is published.** A translation is a proposal until it is accepted, and publishing stays the owning module's separate decision.

### 6. Generation respects the kind of field

`TranslationPrompt` has three instructions, all of which protect placeholders:

* **plain text:** keeps the register, punctuation and line breaks
* **HTML:** translates text nodes and the `alt` and `title` attributes only, and keeps every tag, link and technical attribute
* **SEO:** stays within the field's maximum length

**Output budget.** It is computed per field from the source's length. The operator's `ai.max_output_tokens` is the floor, and one field can never ask for more than a fixed ceiling.

**Checks before anyone is shown the result.** Each of these fails the field, and therefore the batch, with a reason:

* an empty answer
* an answer longer than the field accepts
* an HTML answer whose tags, links or technical attributes changed

**Slugs.** A slug is never a field. It is not sent to a provider. The owning module generates it from the translated title or name under its own rules (ADR 0055 §6).

### 7. Accept once; review stays human

ADR 0044 §5 is unchanged in substance: AI never writes, it proposes, and a person accepts. What changes is how much a person accepts at once — **the item**, not the field.

`POST /admin/translations/batches/{id}/accept` works as follows:

* **What is written.** The request carries only the fields the reviewer edited, and every other field is written as generated. All of them are written in **one** `TranslationSource::write` call, so a module's rule about its fields together is asked about the state the item will be in.
* **What is refused.** A batch is refused if it is not ready. It is also refused if any of its fields changed after generation, if a required field is left empty, or if a value is longer than its field allows.
* **What is recorded.** One `translation.updated` audit record, with origin `ai`, the changed field names, and whether the reviewer edited it.

`POST /admin/translations/batches/accept-ready` accepts every ready item in a language the caller may write, **item by item**. One refused item is reported with its reason and does not stop the rest. The answer reports each result, e.g. "9 accepted / 1 failed".

`DELETE /admin/translations/batches/{id}` discards a batch without writing anything.

The per-field endpoints under `/admin/translations/suggestions` are removed.

### 8. Languages are data

A language added in Language Management is at once a target in the workshop, in every source, in Pages, Team and Settings. Every existing item appears as not translated in it. Nothing is registered per language, and no empty translation rows are created, because an absent row already means "not translated".

An architecture test fails if application code outside seeders names a content locale in a list, a comparison, an array key or a match arm.

## Consequences

* Accepting a notification template writes subject and body together, and the 422 cannot recur through the workshop.
* An operator works through items with one status each, and translates a language or a source in one action.
* Coverage and the Languages page count items, so an optional SEO field no longer makes a finished item look unfinished.
* HTML and length problems are caught before review rather than by a reviewer.
* The workshop's response grows. Each entry carries its status, progress, field metadata and, for writers only, its open batch.
* A reviewer who wants only some of an item's fields must edit or clear the rest before accepting. Accepting half an item is deliberately not a separate action.

## Alternatives considered

**Keep per-field suggestions and add a "bundle accept".** Rejected. Two ways to accept the same content means the per-field path still exists to fail, and status and coverage would still be counted per field.

**Accept automatically when generation succeeds.** Rejected by ADR 0044 §5, which this record keeps. A model's output is not a translation until a person has read it.

**One prompt, with the field type as a hint.** Rejected. HTML preservation and a length limit are different tasks, and a single instruction that tries to cover both does neither reliably.

**A larger fixed token ceiling.** Rejected. It either still truncates the longest bodies or lets every label request far more output than it needs.
