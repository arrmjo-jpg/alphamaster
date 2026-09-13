# ADR 0043: The Translation Workshop and the Translatable-Content Registry

* **Status**: Accepted
* **Date**: 2026-09-10
* **Amended by**: ADR 0048 — the workshop may target languages that are not yet served, is queried a page at a time rather than downloaded, shares one coverage calculation with the Languages page, and records translations in the audit trail

## Context

The platform ships two languages and has done since M1. It has a languages screen that manages which languages exist, a `HasTranslations` trait (ADR 0015) that gives a model a normalised `{entity}_translations` table, and three kinds of content that use it:

| content | owner | translated attributes |
| :--- | :--- | :--- |
| role labels | Authorization | `label` |
| notification wording | Notification | `subject`, `body` |
| the settings whose value is copy | Settings | `value` |

Every one of those is editable today, and every one is editable *only* through its own screen, in whichever language the console happens to be set to. That produces three problems that no screen in the console could answer.

**Nobody can see what is missing.** Adding Arabic to the language list does nothing to the roles, the settings copy or the notification wording. There has never been a surface that says "eleven of these forty fields have no Arabic", so the honest answer to "is the platform translated?" has been "somebody would have to go and look".

**Nobody can see the source while writing the target.** Translating means reading one language and writing another. The settings screen shows one locale at a time — the console's own — so the way to write Arabic is to switch the whole console to Arabic, at which point the English is no longer on screen.

**A locale is inherited rather than chosen.** `SettingService::updateGroup()` wrote a localized value into `app()->getLocale()`. That is right for the settings screen, where an operator working in Arabic is writing Arabic. It makes translation impossible, because the entire activity is writing one language from a console in another.

A fourth thing was tempting and is wrong: doing this by hand, per module. Three screens each growing a locale switcher and a completeness badge is three implementations of the same idea, none of which can answer a question about the platform as a whole.

## Decision

### 1. Translatable content is declared, not discovered

A module that owns translatable content declares a `TranslationSource`. The interface answers six questions: what the content is called, which permissions read and write it, what items it holds with what has been written for each, and how to write one item in one locale.

```php
interface TranslationSource
{
    public function key(): string;              // stable, in the URL, never shown
    public function label(): string;            // a translation key
    public function viewPermission(): ?string;
    public function writePermission(): string;  // never null
    /** @return array<int, TranslationEntry> */
    public function entries(): array;
    /** @param array<string, string> $values */
    public function write(string $id, string $locale, array $values): void;
}
```

Registration is explicit and static, from the owning module's service provider, exactly as `SettingCatalogue` and `ConfigurationPortability` already are. Nothing scans, nothing is read from configuration, and a source is a class a developer wrote — which is the line ADR 0033 draws between an extension point and a plugin system.

### 2. The registry lives in Core, and the workshop lives in Localization

This is the part that is load-bearing rather than tidy.

The workshop is served by **Localization**, because it is about languages and because the languages screen is where an operator would look for it. But the content belongs to **Settings**, **Authorization** and **Notification**, and the module rules say:

* Localization may not depend on Settings, Authorization or Notification;
* Settings may not depend on Localization.

So the registry cannot live in Localization — the owners could not register with it — and it cannot live in any of the owning modules. It lives in `App\Modules\Core\Translation`, which all four may name. This is precisely the direction `ConfigurationPortability` (ADR 0039), `EffectiveGrants` (ADR 0028) and `RetentionPolicyContract` (ADR 0037) already run in: Core declares the shape, the module that owns the answer binds and registers an implementation, and Core imports nothing back.

The consequence worth stating: **the workshop never learns which module a source came from.** It renders `key`, `label`, entries and permissions. A future module becomes translatable by declaring a source, and no file in Localization changes.

### 3. Authorization is per source, not per screen

One endpoint serves several bodies of content owned by different modules, so `may this caller?` has a different answer per source. A single `permission:` middleware on the route could only ever ask one of those questions, and whichever it asked would be wrong for the other two.

The source names the permission its own module enforces, and the controller asks that question:

* role labels need `roles.view` / `roles.update`;
* notification wording needs `notifications.view` / `notifications.update`;
* settings copy needs `settings.view` / `settings.update`.

**A workshop that granted itself a way around those would be a privilege escalation with a friendly name.** An operator holding `settings.update` and nothing else can translate settings copy and is answered `404` — not `403` — for notification wording, because telling somebody who may not read content that a particular item exists is itself a statement about content they were not granted.

`writePermission()` returns a non-nullable string deliberately. Everything translatable here is content other people read, and "anyone who reached the console" is not an authorization decision.

### 4. Nothing falls back inside the workshop

Everywhere else in the platform, reading a translation falls back: requested locale, then the platform default, then any translation that exists (ADR 0015). That is right for rendering — a partially translated entity should degrade to something readable rather than to nothing.

It is exactly wrong here. A fallback would put the English value in the Arabic column, and an untranslated item would look finished. The workshop reads the translation rows directly and reports only what has actually been written; an empty string is the same absence as a missing row, and both count as untranslated.

Completeness is counted **in fields, not items**. A template with an Arabic subject over an English body is not half a translated template in any sense a recipient would recognise.

### 5. A localized write names its locale

`SettingService::updateGroup()` takes an optional `?string $locale`, defaulting to the request's. The settings screen passes nothing and behaves exactly as before; the workshop passes the language being written.

Writes go through the same service the settings screen uses, so a translation is versioned, audited, and rolled back like any other change to a value (ADR 0038, ADR 0040). Nothing in the workshop touches a table directly.

### 6. One item, one locale, per request

A translator works down a list. A failure should cost the entry they are on, not a batch they did not know they were sending, and an unknown item or field is refused rather than accepted and dropped — the workshop addresses items by ids it was handed a moment earlier, so a miss means the content moved underneath the editor, and quietly discarding the text somebody just typed is the worst available answer.

## Consequences

**A new question is answerable.** "What is still untranslated?" has an answer that is computed rather than remembered, and it covers the platform rather than one screen at a time.

**Becoming translatable is cheap.** A module declares a source in its provider. The workshop, the completeness counts and the permission handling all apply without an edit anywhere else.

**The per-source permission check is now the boundary.** It is enforced in one controller rather than by middleware, which is a place a future contributor could weaken by accident. Tests assert it directly, including the case that matters most: an operator with every settings permission and no notification permission must not be able to rewrite notification wording through this endpoint.

**`entries()` reads everything.** Each source loads its whole body of content with translations eagerly. That is correct at this platform's scale — tens of roles, a handful of templates, ten localized settings — and it is the thing to revisit first if a source ever grows to thousands of items. The interface would take a page rather than the workshop learning to paginate three different tables.

**Language files are still language files.** This governs content in the database. The strings that live in `lang/*.json` — API messages, setting labels, the console's own wording — are code, ship with a deployment, and are deliberately out of scope: making them editable at runtime would make a deployment able to silently revert an operator's edit, which is a different decision and a worse one.

## Alternatives considered

**A locale switcher on each existing screen.** Three implementations of one idea, and still no answer to a question about the platform as a whole. It also leaves the source language off screen while the target is being written, which is the specific thing that makes translation hard.

**Localization owning the content directly.** A generic `translations` table Localization reads and writes, keyed by entity type and id. It removes the registry, and it removes the per-module ownership with it: Localization would have to know that a `role` label means something different from a `template` body, that one needs `roles.update` and the other `notifications.update`, and that a settings value must be written through the settings service to be audited. Every one of those facts belongs to the owning module.

**Scanning for `HasTranslations` users.** Discovery rather than declaration. It would find the models and could not answer the questions that matter — which permission guards this, what to call it, whether writes must go through a service — so each model would need an attribute or interface anyway, at which point declaration is the same work without the reflection.
