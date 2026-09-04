# ADR 0030: Human Display Labels vs Technical Identifiers

* **Status**: Accepted
* **Date**: 2026-09-04
* **Implementation**: Not started. This record establishes the decision; the work is tracked in ADR 0029.

## Context

A foundation audit on 2026-09-04 found that the platform has no concept of a human-readable label. Every identifier the code uses is also the string the interface would have to display.

The evidence, from the code as it stands:

* `permissions` carries `name`, `guard_name`, `module`. `roles` carries `name`, `guard_name`. Neither table has a label column and no translation table exists for either.
* `AdminRbac::rolesFor()` returns `getRoleNames()` and `permissionsFor()` returns `pluck('name')`, so an administrator's roles reach the client as `super_admin` and their permissions as `users.update`.
* `RoleRequest` validates the role name as `regex:/^[a-z][a-z0-9_]*$/` and tells the administrator so: *"A role name must be a lowercase identifier, e.g. content_editor."* Creating a role today means typing the technical key by hand, and there is no field in which to put a human name.
* Fifteen enums exist across the modules and **none** declares a display method. `scan_status`, `account_type`, `visibility`, `type`, and the MFA method reach API responses as raw backed values: `not_scanned`, `sms_otp`, `security.alert`.

None of this was decided. No existing ADR mentions display labels — ADR 0014 is detailed about permission key structure and module ownership and silent on presentation, and ADR 0029's deferred backlog does not list it. This is an architectural question that was never asked, not a deferral.

Two precedents already exist inside the codebase, which is what makes the gap a gap rather than a constraint. `permissions.module` is a column added on top of Spatie's schema, so extending these tables is precedented. And `integration_providers` already separates `driver` (`twilio`) from `label` (`Twilio`) and returns both.

## Decision

**A technical identifier and a human display label are different things, stored differently, and never substituted for one another.**

### Technical identifiers

`users.update`, `scan_failed`, `sms_otp`, `security.alert`, `content_editor`, `admin`.

They are stable, machine-readable, language-independent, and belong to the code and the database. They appear in API payloads, in authorization checks, in enum backed values, in database rows. **They are never translated**, and a change to one is a breaking change to the contract.

They are not intended for display. A client that renders a raw identifier to an end user is rendering an internal value, and the fix is a label rather than a rename.

### Display labels

*View Users*, *فشل الفحص*, *رمز SMS*, *محرر المحتوى*.

They are human-readable, locale-specific, and exist per active language. They carry no meaning the system depends on: changing a label changes what a person reads and nothing else.

### Where a label is stored depends on who defines the thing

This is the substantive decision, and it follows the split ADR 0015 already drew between system text and entity text.

**Code-defined sets → `lang/{locale}.json`, keyed by the identifier.**

Applies to enums and to the administrative permission catalogue. These sets are fixed by a deployment: a new case is a code change, so its label is a code change too. A database table for them would need a migration or seeder run for every enum case added, and would drift silently when one was not.

```
"enum.media.scan_status.not_scanned": "لم يُفحص"
"enum.auth.mfa_type.sms_otp":         "رمز SMS"
"permission.users.update":            "تعديل المستخدم"
```

The key is derived from the identifier so it cannot be invented at a call site, in the same spirit as ADR 0014's rule that a permission string is never invented at a call site. A missing key resolves through the locale fallback chain and, failing that, renders the identifier — visibly wrong rather than blank, so an untranslated label is reported rather than hidden.

**Runtime-created records → relational translation tables.**

Applies to roles, to localized setting values, and to business entities. These are created by administrators after deployment, so their labels cannot live in a file that ships with the code. They follow the pattern ADR 0015 §5 established and the Notification module already runs in production: a locale is a row, keyed `UNIQUE(owner_id, locale)`, never a JSON blob.

### Enum display in the API

An enum-valued field keeps its technical value under its existing name. The label is added alongside it; it does not replace it.

```json
{ "scan_status": "not_scanned", "scan_status_label": "لم يُفحص" }
```

or, where a client consumes a catalogue of options rather than one field:

```json
{ "value": "not_scanned", "label": "لم يُفحص" }
```

The naming contract is fixed in ADR 0031. No existing field changes type or meaning, so adding labels breaks no consumer.

### Enums do not contain their own text

An enum declares its translation key, not its Arabic or English wording. Putting display text inside a PHP enum would put a per-language string in a place that has no locale and cannot be re-read when the request locale changes.

## Alternatives considered

**A `label` column on `permissions` and `roles`, single-language.** Matches what `integration_providers` does today, and is the cheapest change. Rejected for anything user-facing: one column holds one language, and ADR 0015 makes the platform's language set dynamic and administrator-managed. It would work only for as long as the platform stayed monolingual, which is the thing this foundation is explicitly not.

**A `permission_translations` table.** Consistent with the relational pattern, and the obvious symmetric choice next to `role_translations`. Rejected because the permission catalogue is code-defined: ADR 0014 enumerates it in `AdminPermission` and refuses any permission outside it, so a translation row could only ever mirror a constant that already exists in the code, and would need seeding on every catalogue change. Roles are different in kind — administrators create them — which is why they get the table and permissions do not.

**Translating `roles.name` / `permissions.name` in place.** Rejected outright. These are the values authorization is evaluated against; translating them would make an authorization check locale-dependent.

**Deriving labels by humanising the identifier at the client** (`users.update` → "Users Update"). Rejected: it produces wording no one chose, cannot express Arabic at all, and would place a presentation rule in every client independently.

## Consequences

Every identifier now has a defined display path, and the question "what does the user see" has one answer per kind of thing rather than being decided per endpoint.

Two stores rather than one is a real cost: a reader must know whether a label lives in a lang file or a table. The rule that decides it is a single question — *does a deployment define this set, or does an administrator?* — and it is the same question ADR 0015 already answers for text.

Adding labels does not change any existing field, so this is additive for every current consumer.

Until the implementation lands, raw identifiers continue to reach clients. The gap is now recorded rather than unnoticed; see ADR 0029.
