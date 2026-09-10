# ADR 0018: Grouped, Encrypted, and Cached Settings System

* **Status**: Accepted
* **Date**: 2026-09-03
* **Revised**: 2026-09-03 — aligned with the implemented Phase 4 contract
* **Revised**: 2026-09-04 — extended after the foundation gap audit: setting classification, localized values, branding, mail, and site content settings
* **Revised**: 2026-09-06 — a definition registry supersedes seeder provisioning; single-tenancy recorded in ADR 0034 removes scope from this engine
* **Revised**: 2026-09-10 — a definition declares its reach; stored mail configuration applies to the platform's own mailer

## Context

Application configuration must be dynamically modifiable by administrators while keeping secrets secure and reads ultra-fast.

The original record described an intended design. Implementing it surfaced three points where the intent could not survive contact with the requirements, and this record has been revised to state what the module actually does, because an ADR that disagrees with the code is worse than no ADR. Laravel's `encrypted` cast applies to a column unconditionally, but only the rows flagged `is_secret` are encrypted here, so the cast cannot express the rule. Cache tags would let a single secret's plaintext share a tagged set with public values, and the segmentation the module needs is a property of the key layout rather than of tag membership. The `/public` suffix in the original endpoint added a path segment that carried no information the resource did not already imply.

## Decision

Settings live in a typed, grouped key-value table. Every value is stored as a canonical string alongside its declared `SettingType`, and conversion in both directions is strict: a value that cannot be represented exactly in its declared type is rejected rather than coerced.

**Encryption.** Secret values are encrypted with `Crypt::encryptString()` and read back with `Crypt::decryptString()`, driven by the `is_secret` flag through the model's value lifecycle (`setRawValue()` / `getRawValue()`) rather than by an Eloquent cast. A failed decrypt raises `SettingDecryptionException`; the stored ciphertext is never returned in place of plaintext. A secret that has not been provisioned is `null` — a legitimate state distinct from both an empty string and a decrypt failure — and the admin API renders an unset secret as `null` while rendering a provisioned one as a mask. The seeder provisions secrets unset; generating or rotating secret material is an operator action, never a side effect of seeding.

**Caching.** Settings are cached in Redis under segmented, explicitly named keys, without cache tags. Public payloads, per-group public payloads, the list of groups exposing public settings, and the internal per-group index each occupy their own key and are invalidated by explicit `forget` calls. Decrypted secrets are never written to any cache entry: the internal group index carries non-secret values plus the *names* of the secret keys, and a secret is read from the database on demand. Invalidation runs after the database transaction commits (`DB::afterCommit()`), never inside it, so a concurrent reader cannot repopulate a key from uncommitted state and pin a stale value for the full TTL. A cache entry whose shape does not match the current contract is treated as a miss and rebuilt.

**Public API.** Public settings are exposed as:

* `GET /api/v1/settings` — all public settings, grouped by group name.
* `GET /api/v1/settings/{group}` — public settings for one group.

There is no `/public` alias. Responses carry keys and typed values only, with no metadata flags. A group that exposes no public settings is reported as `404`, which keeps internal groups indistinguishable from absent ones. The `{group}` parameter is constrained to a lowercase identifier and checked against the known public groups before any cache write, so an arbitrary path segment cannot mint a cache entry.

**Administration.** Settings are provisioned by migrations and seeders; the admin API updates existing settings but never creates them, so an unknown group or key is a `404` rather than a validation error. Both invariants — that a secret is never public, and that `type` holds a known `SettingType` value — are enforced by database constraints, not by model events, so they survive `WithoutModelEvents` and raw query-builder writes.

## Extension — 2026-09-04: classification, labels, and localized values

This section adds decisions the original record did not make. The engine above is unchanged: typed grouped keys, strict conversion, `is_secret` encryption through the value lifecycle, segmented cache keys, settings provisioned by migration and never created through the API.

**What the audit found.** A setting has a `key` (`general.site_name`), a `value`, and a `description` column that the admin API returns verbatim. The description is a single-language English sentence stored in the database — `Public platform brand name.` — with no translation path. There is no display label at all, so an interface has only the technical key or that English sentence to show. And a setting holds exactly one value, so a platform whose language set is administrator-managed cannot express a site name that differs per language.

### Three things, not one

| | Example | Nature |
| :--- | :--- | :--- |
| **Key** | `general.site_name` | Technical identifier. Never translated, never displayed as a caption. |
| **Label** | `Site Name` / `اسم الموقع` | Human-readable name of the field, per locale. |
| **Value** | `Our News` / `أخبارنا` | The configured content, sometimes per locale. |

The label answers *what is this field*; the value answers *what is it set to*. Conflating them is what leaves an interface rendering `general.site_name` as a caption.

### Labels and descriptions live in language files

Settings are provisioned by migrations and seeders and the admin API never creates them, so the set of settings is code-defined. By ADR 0030 that places their labels and help text in `lang/{locale}.json`:

```
"setting.general.site_name":       "اسم الموقع"
"setting.general.site_name.help":  "الاسم المعروض للمنصة."
```

The `description` column is retained for provisioning-time documentation and is no longer the interface source. Reconciling the two is implementation work, not a second decision.

### Classification

Every setting is exactly one of three kinds, and the kind determines how it behaves.

**Technical settings** — `general.timezone`, `general.date_format`, `mail.host`, `mail.port`, `security.mfa_required`, `general.default_locale`. One value, no locale. The value is configuration and means the same thing in every language.

**Localized content settings** — `general.site_name`, `general.site_description`, `general.maintenance_message`, `general.cookie_message`, `general.footer_text`, `general.footer_copyright`. A value per active locale, because the value is text a visitor reads.

**Secret settings** — SMTP password, provider credentials, API keys. Encrypted at rest by the existing `is_secret` path, masked in admin responses, never returned in plaintext, never logged, never cached.

Two invariants follow, and both belong in the database rather than in model events, for the reason this record already gives:

* A setting is never both secret and localized. A credential has no language, and a per-locale copy of a secret multiplies the thing that must be protected.
* A setting is never both secret and public. Already enforced.

### Localized values are relational

A localized setting keeps its row in `settings` and carries its values in a translation table, following ADR 0015 and the notification-template pattern already in production:

```
settings.is_localized     boolean, default false

setting_translations
  id           ULID
  setting_id   → settings.id
  locale
  value        text
  UNIQUE (setting_id, locale)
```

Reading a localized setting resolves the request locale, then the platform default locale, then the base `settings.value` — the same chain ADR 0015 defines rather than a new one. A locale with no row is not an error.

**`site_name_ar` / `site_name_en` columns are explicitly rejected.** Adding a language would require a migration, which contradicts the runtime language management ADR 0015 exists to provide.

**A localized setting never shares a cache entry across locales.** The keys this record already defines — `settings:public` and `settings:group:{group}:public` — carry no locale, which is correct while every value is language-independent and wrong the moment one is not. Left unchanged, the first request in any language would populate the shared entry and every later request would receive that language for the full TTL.

Payloads that can contain a localized value are therefore keyed per locale:

```
settings:public:{locale}
settings:group:{group}:public:{locale}
```

This is the existing scheme with one more segment, not a second cache design. Everything above still holds: segmented explicit keys, no tags, no decrypted secret in any entry, and invalidation through explicit `forget` calls running after the transaction commits.

Invalidation widens to match. Writing, deleting or re-typing a localized setting — or adding, activating or deactivating a language — invalidates **every** locale variant of the affected payloads, because a stale entry in a language nobody happened to request is exactly the one that will be served next. A setting with no localized values is unaffected and keeps a single entry.

### Branding assets reference media; they never contain it

Branding is a set of settings whose value is a `MediaFile` identifier:

```
branding.logo_primary        branding.logo_light        branding.logo_dark
branding.favicon             branding.site_icon         branding.app_icon
branding.apple_touch_icon    branding.default_image     branding.social_image
branding.og_image            branding.admin_logo        branding.watermark
```

The stored value is a media id. Binary data, base64 payloads and file paths are never stored in `settings`: the Media module owns storage, scanning, delivery and CDN resolution (ADR 0024), and a second copy of that in Settings would be a second implementation of all four.

```
setting value → media_id → MediaFile → named variant → CDN or signed URL
```

This requires a media-typed setting so the value can be validated as an existing media id rather than as an opaque string. That type does not exist in `SettingType` today; defining it is a prerequisite of implementing branding, and is left to the implementation rather than named here so that this record does not fix an identifier the code has not yet declared. Deferred with the rest of this extension.

### Site content settings

Recorded here so their classification is settled before they are implemented; grouped as the engine already groups.

**Social** — `social.facebook`, `social.instagram`, `social.x`, `social.youtube`, `social.tiktok`, `social.linkedin`, `social.telegram`, `social.whatsapp`, `social.rss`. URLs and handles are technical: a profile URL does not vary by language. Any accompanying caption is localized content.

**Footer** — `footer.text` and `footer.copyright` are localized content. Footer link targets and social references are technical.

**Cookies and privacy** — `privacy.cookie_consent_enabled`, `privacy.policy_url`, `privacy.terms_url`, `privacy.cookie_policy_url`, `privacy.analytics_consent`, `privacy.marketing_consent`, `privacy.consent_version` are technical flags and URLs. `general.cookie_message` is localized content. Consent enforcement in a specific interface is a project concern (ADR 0033).

**Maintenance** — `general.maintenance_mode`, `general.maintenance_starts_at`, `general.maintenance_ends_at`, `general.maintenance_admin_bypass` are technical. `general.maintenance_message` is localized content. While maintenance is active the API answers `503` with the platform error envelope and a localized message, and never a framework HTML page; an administrator holding `admin:access` bypasses it when the bypass flag is set, so the platform can be repaired through its own interface.

### Mail configuration belongs in Settings, not in the Integration module

The audit asked where SMTP lives. The two candidates are this engine and the provider manager of ADR 0017.

The boundary in ADR 0017 is a vendor whose behaviour differs per provider and which the platform selects between at runtime with failover. SMTP is not that: it is one transport with one set of connection parameters, and Laravel already abstracts the mailer. Putting it behind the provider manager would model a choice the platform does not make.

Mail configuration is therefore a settings group:

```
mail.enabled       mail.mailer        mail.host          mail.port
mail.encryption    mail.username      mail.password      (secret)
mail.from_address  mail.from_name     mail.reply_to
```

`mail.password` is a secret setting under the existing rules: encrypted, masked, never returned, never logged.

**A transactional email provider with its own API — SendGrid, Postmark, SES — is a different question and does belong behind ADR 0017**, as an `email` capability alongside `sms`. The distinction is the same one this record draws: SMTP is platform configuration; an API-driven sender is a vendor.

**Target capability, not implemented:** verifying a mail configuration by connecting, and sending a test message to a nominated address. Both write to the audit trail and neither reveals the password.

### Implementation status

Everything in this extension is decided and **none of it is implemented**. The existing engine, encryption, caching and public API are unchanged and remain as described above. Tracked in ADR 0029.

## Extension — 2026-09-06: the definition registry supersedes seeder provisioning

This section changes a decision the original record made, rather than adding to it. It is written here, above the text it supersedes rather than over it, so the two remain comparable.

### What is superseded, and what is not

The Administration paragraph above says:

> Settings are provisioned by migrations and seeders; the admin API updates existing settings but never creates them, so an unknown group or key is a `404` rather than a validation error.

**The first clause is superseded. The second is reaffirmed.**

Provisioning by seeder is replaced by a **definition registry**. The admin API still updates existing settings and still never creates them, and an unknown group or key is still a `404` — that half is not weakened, and it is what keeps an arbitrary key from minting a row or a cache entry.

### Why the seeder is no longer the right place

The engine has one definition of a setting spread across four places. Its `type`, `is_secret` and `is_public` live as columns on a seeder row. Its validation lives partly in `UpdateGroupSettingsRequest` and partly in `SettingType`'s strict conversion. Its label and help text are headed for the language catalogues under ADR 0030. Its default is indistinguishable from its current value, because the seeder writes one into the other.

The 2026-09-04 extension above adds three more attributes to that spread — the technical/localized/secret classification, `is_localized`, and a media-typed value — which is what turned a tolerable scatter into a reason to decide.

Nothing can answer *what settings exist and what are the rules for each* without reading all four. A future administrative interface needs exactly that answer, and so does a typed client.

### The decision

**A definition registry is the sole declaration of what a setting is.** Key, group, type, default, classification, `is_secret`, `is_public`, `is_localized`, validation, and the permission required to change it are declared once, in code, in one place.

**An idempotent synchroniser materialises the rows.** Definitions do not read themselves into the database. One command creates rows that are missing and updates the declared attributes of rows that exist, and it may be run repeatedly with the same result. Values are not part of a definition: the synchroniser writes a value only when creating a row that has none, and never overwrites a value an operator has configured.

**Per-setting seeder declarations are removed once the registry is authoritative.** This is the point of the decision. Two declaration sources would be worse than the one imperfect source there is today, so the seeder entries go rather than being left as a second opinion.

### Schema migrations and definition provisioning are different things

They are separated deliberately, and the registry does not blur them.

A **schema migration** changes the shape of the `settings` table or its constraints — adding `is_localized`, creating `setting_translations`, adding an invariant. It is versioned, ordered, runs once, and belongs in `Database/Migrations` exactly as it does today.

**Definition provisioning** decides which settings exist and what their rules are. It is declarative, unordered, converges rather than accumulates, and is re-run whenever definitions change.

Conflating them is how a platform ends up needing a migration to add a setting. The invariants ADR 0027 requires at engine level — that a secret is never public, that `type` holds a known `SettingType` — remain database constraints written by migrations, because they must survive a raw query-builder write and the registry cannot enforce them.

### Removed definitions are orphans, never deletions

The synchroniser **never deletes a settings row** because its definition disappeared from the registry.

A definition can vanish for reasons that have nothing to do with intent: a bad merge, a module removed from a build, a refactor that renamed a key without migrating it, a branch deployed out of order. Every one of those would, under a deleting synchroniser, silently destroy configured production values — and the more valuable the setting, the more likely it was configured rather than left at its default.

So a row whose definition no longer exists is **reported as an orphan** and left exactly as it is. The operator decides whether it is obsolete, and removing it is an explicit act.

**A configured secret is never destroyed because a definition was removed.** This is the same rule, stated separately because it is the case with no recovery: a deleted encrypted credential cannot be reconstructed from anything the platform still holds, and the operator who would notice is the one who finds an integration broken later. An orphaned secret is reported like any other orphan, and its ciphertext is left untouched — never logged, never decrypted, and never included in the report in any form beyond its key.

### Not implemented

Everything in this section is decided and none of it is built. It is scoped to Phase 16A and tracked in ADR 0029.

### Scope, after ADR 0034

The 2026-09-04 extension and this one both describe a settings engine with **no tenant dimension**. ADR 0034 records that AlphaMaster is single-tenant, so a definition declares no scope, there is no override table, and the effective value of a setting is the stored value once validated and cast. Nothing in this record should be read as leaving room for a workspace scope to be added quietly; adding one means superseding ADR 0034 first.

## Extension — 2026-09-10: reach, and mail that reaches the mailer

An audit of the catalogue asked a question nothing in this engine could answer: *what does changing this actually do?*

For thirty of sixty-six settings the answer was "nothing, yet". Some are published for a public website that has not been built; some are declared ahead of a capability the platform does not have — the image pipeline, provider retry, public registration, a machine-to-machine caller; two are formatting a client does and that nothing here will ever read. Every one of them was presented in the console exactly like the setting that closes an account after five failed sign-ins.

That is not a labelling problem. An operator reasonably assumes a control that can be changed does something, and a screen that presents thirty inert controls beside thirty-six live ones is lying by omission — which is worse than an honest absence, because the operator now believes something false about their own platform.

### A definition declares its reach

`SettingDefinition` carries a `SettingReach`: `PLATFORM` (the platform reads it and behaves differently), `PUBLISHED` (served through the settings API for a client to read; nothing here behaves differently), `AWAITING` (declared ahead of the thing that would read it).

The set is small and closed on purpose. A setting is read by this platform, or served to something else, or waiting for something that does not exist — there is no fourth answer that is honest. A free-text note in its place would drift into a second help text.

The catalogue publishes `reach` and a translated `reach_notice`, and the console renders both: a badge, because that is what an operator scanning a group of eighteen sees, and a sentence, because the badge alone does not explain.

**A setting nothing reads yet is still editable.** The value is real: it persists, it is versioned, it exports and restores with the rest of the configuration, and it will be read the moment its reader exists. Disabling the control would say "you may not set this", which is a different and untrue statement.

**A test pins the list.** Adding a setting nothing reads is then a deliberate act visible in a diff, rather than something that happens by not noticing — which is how the first twenty-nine arrived.

### Mail configuration applies to the mail the platform sends

Eleven settings described an SMTP connection and exactly one thing read them: the "send a test message" button. Everything else — email verification, notifications, announcements — went out through `config/mail.php`, which is environment-driven and unreachable from the console. An operator could configure a mail server, watch the test message arrive, and still have every real message leave through whatever the deployment's environment file said.

The stored configuration is now applied to the mailer the rest of the platform resolves, with three properties that are decisions rather than details:

* **Lazily**, when something first resolves the mail manager. A request that sends no mail reads no settings, and a console command running before the settings table exists never asks for one.
* **Failing open to the environment.** If the settings store cannot be read, the deployment's own configuration stands — the same precedent the rate limiter and the maintenance middleware set. A platform whose mail configuration lives in a database should still send mail when the database is unreachable.
* **`mail.enabled` off means "the platform has no opinion", not "send nothing".** Suppressing mail is `MAIL_MAILER=log`, which a deployment already has. Quietly swallowing a password-reset message is a worse failure than sending it from the wrong host.

A half-configured connection — enabled, with no host or no sender — is not applied over a working one, because replacing a working mailer with one that cannot connect is worse than not applying at all.

## Consequences

Provides instant settings retrieval without database queries and protects credentials at rest, at the cost of one database read per secret access, which is the price of keeping plaintext out of Redis entirely.

Because the invariants are enforced in the database, they are engine-level behaviour and must be verified on PostgreSQL; see ADR 0027, which makes PostgreSQL authoritative for test execution and explains why a green SQLite run does not prove them. Where the local SQLite harness needs the same guarantees, they are reproduced with triggers whose only purpose is to let the test suite exercise the behaviour — PostgreSQL remains the sole production engine under ADR 0003.
