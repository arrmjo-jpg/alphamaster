# AlphaMaster — product capability matrix

Audited 2026-09-10 against the repository and the running platform; revised the same
day as rows 5, 6, 14 and 17 were completed. Every row is
evidence-backed: a screen existing, an endpoint existing, a setting existing or a green
CI run is **not** treated as proof of anything.

**GREEN** — works end to end: backend capability, contract, generated client,
permission, Admin workflow, persistence, validation, states, tests, and verified in a
browser where a browser is involved.
**YELLOW** — partly there; the row says exactly which link is missing.
**RED** — absent.
**GRAY** — deliberately out of scope, with the record that says so.

---

## Summary

| | Count |
| :--- | ---: |
| GREEN | 24 |
| YELLOW | 5 |
| RED | 10 |
| GRAY | 4 |

Six rows moved to GREEN on 2026-09-10: phone verification (5), OTP configuration (6),
translation management (14), general settings (17), and — recording work this branch
already carries — the visual identity (36) and the two themes (38). Row 33 moved with
them and did not improve; see its entry.

The counts above are recomputed from the table rather than kept beside it, because the
previous ones had drifted from what the rows actually said.

The nine RED rows are not evenly weighted. **AI, Firebase, push notifications and
device registration do not exist in any form** — no module, no table, no setting, no
contract, no enum case. They were never built and no ADR describes them. Everything
else RED is a known, recorded gap with an argument attached.

---

## The matrix

### Identity and access

| # | Capability | State | Evidence and what is missing |
| --- | :--- | :---: | :--- |
| 1 | Authentication | **GREEN** | `POST /auth/login`, `/logout`, `/me`; five-stage perimeter (`auth:sanctum`, `ability`, `active`, `admin`, `email-verified`); HttpOnly cookie transport (ADR 0042) with a real personal access token, so `tokenCan()` reads real abilities. Sign-in verified in a browser repeatedly. |
| 2 | MFA | **GREEN** | TOTP and SMS OTP; `mfa_methods` with `confirmed_at`, `last_used_slice` replay protection; mandatory for administrators (ADR 0013); enrol/verify/status/disable endpoints; Admin enrolment screen. |
| 3 | CAPTCHA | **GREEN** | reCAPTCHA driver behind the provider manager, v2/v3 settings, site key public and secret encrypted on the provider row, throttled sign-in path, `Http::fake()` tests. |
| 4 | Email verification | **GREEN** | Signed link, `POST /auth/email/verify/send`, administrators refused until verified, address change clears verification. |
| 5 | Phone verification | **GREEN** | `phone_verified_at` on `users`, a `phone_verifications` table holding one hashed code per account with the keyed digest of the number it went to, and `POST /auth/phone/verify/send` + `/auth/phone/verify`. Neither route takes an account identifier, so nobody confirms somebody else's number; an administrator can see the state and cannot set it. A code sent to a number that has since changed is refused, and changing the number clears the confirmation in the model, so every write path is covered rather than every caller having to remember. A wrong answer costs an attempt *outside* the transaction — counting it inside one and then throwing rolled the count back, which made guessing free. Fifteen backend tests, four Admin tests, and the flow run in a browser: administrator sets a number, holder confirms it from **Your account**, badge and timestamp follow. |
| 6 | OTP | **GREEN** | Length, lifetime, resend cooldown and the number of wrong answers a code survives are four settings in the `auth` group, read through one `OtpPolicy` that both flows use — MFA and phone verification cannot drift into two policies. Each is proven to change behaviour rather than merely exist: an eight-digit setting delivers eight digits and the eight-digit code is accepted; a ten-minute lifetime is announced in the message and honoured past the old five-minute ceiling; a two-minute cooldown refuses at sixty seconds and allows at a hundred and twenty-one; three attempts discards the code. |
| 27 | Users | **GREEN** | Create, edit, activate/deactivate, promote/demote, role sync; identity-only edits; self-deactivation refused; 16 lifecycle tests; browser-verified end to end. |
| 28 | Roles | **GREEN** | Create, edit, delete, permission assignment, label/identifier separation with a server-derived immutable identifier. |
| 29 | Permissions | **GREEN** | Read-only catalogue grouped by module, role carriers named, orphan permissions called out. Dynamic creation is **intentionally** unsupported — `AdminPermission` is a code enum (ADR 0014) and the screen says so rather than offering CRUD. |
| 30 | Audit trail | **YELLOW** | Configuration, secrets, rollback, archival, backup, mail test, and — new — the full account lifecycle including role assignment. **Role *definitions* are unaudited**: creating a role, changing what it grants, deleting one. ADR 0037's amendment names this as the acknowledged gap. |

### Messaging

| # | Capability | State | Evidence and what is missing |
| --- | :--- | :---: | :--- |
| 7 | SMS | **GREEN** | Twilio driver over the HTTP client, provider chain with failover, usage logging, timeout now read from configuration. |
| 8 | Firebase / mobile integration | **RED** | **Zero references in the codebase.** No module, no credentials, no setting, no table, no contract, no capability enum case. Nothing to complete — this is greenfield. |
| 9 | Push notifications | **RED** | `NotificationChannel` has `database`, `mail`, `sms` and a comment saying "WhatsApp and push arrive when the Integration capabilities they need do". No channel, no device table, no transport. |
| 23 | Notifications | **YELLOW** | Templates, per-locale rendering, preferences, the in-app inbox, mark-read, and administrator announcements all work end to end and are browser-verified. |
| 24 | Notification producers | **RED** | `security.alert` and `account.updated` have seeded, active, translated templates and **have never been raised once**. `NotifierContract` lives in the Notification namespace and takes a Notification enum; every module that would produce a notification is forbidden by the architecture rules from importing that namespace. Blocked on decision 1 in `docs/m3-decisions.md`. |
| 25 | Integrations | **GREEN** | Provider list, capability/status/active/default, credential state without ever exposing a secret, usage window, make-default with `PROVIDER_INACTIVE` refusal. |
| 26 | Provider retry / failover | **YELLOW** | Failover works and is ADR 0017's decision: the chain is walked until one succeeds. **Retry against the same provider does not exist** — `operations.provider_retry_attempts` is configured and read by nothing, because retrying a send that may already have arrived is a duplicate-delivery hazard nobody has ruled on. Decision 3. |

### AI

| # | Capability | State | Evidence and what is missing |
| --- | :--- | :---: | :--- |
| 10 | AI | **RED** | **Zero references.** No module, no service, no contract, no route. |
| 11 | AI providers | **RED** | `IntegrationCapability` has exactly two cases: `sms`, `captcha`. ADR 0017 names AI as a capability the manager pattern could carry; nothing implements it. |
| 12 | AI settings | **RED** | No `ai` settings group, no credentials, no model, no timeout, no limits. |
| 15 | AI-assisted translation | **RED** | Depends on all three rows above. |

### Localization

| # | Capability | State | Evidence and what is missing |
| --- | :--- | :---: | :--- |
| 13 | Languages | **GREEN** | List, create, update, activate/deactivate, set default; deactivating the default refused; the console's own language list comes from the platform (ADR 0015). |
| 14 | Translation management | **GREEN** | A workshop at `/translations` over `GET /admin/translations` and `PUT /admin/translations/{source}/{id}`: source language beside target, per-source completeness counted in fields, and a filter to what is outstanding. Content declares itself through `TranslationSource` registered in Core (ADR 0043), so Localization serves a workshop over content it may not import and a fourth translatable module needs no edit here. Permissions are per source and are the owning module's — an operator with every settings permission is answered 404 for notification wording. Nothing falls back inside the workshop, deliberately: a fallback would put English in the Arabic column and make an untranslated item look finished. Eighteen backend tests, eight Admin tests, and a role label translated in a browser with the outstanding count moving 4 → 3. |
| 16 | Manual language creation | **GREEN** | `POST /admin/languages` with name, native name, code, direction, activation, default and order, and the Admin form that drives it. |
| 37 | RTL / LTR | **GREEN** | Direction comes from the active language, logical properties throughout, swept for horizontal overflow at 375 in both directions across all ten routes. |

### Configuration and platform

| # | Capability | State | Evidence and what is missing |
| --- | :--- | :---: | :--- |
| 17 | General settings | **GREEN** | Sixty-six typed settings with grouping, per-locale values, secrets, history, rollback, backup/restore, optimistic concurrency, a label and help sentence for every one in both languages, and — new — a declared **reach**: every definition says whether the platform reads it, a client does, or nothing does yet, and the console renders that as a badge and a sentence on the row. The capability is complete; what remains is row 33, which is a statement about how much of the catalogue has a reader rather than about this screen. |
| 18 | Branding | **YELLOW** | Seven media-typed settings — logos in four combinations, favicon, social image, watermark image — each holding a validated `MediaFile` id, assigned through a picker. Nothing renders any of them: the console ships its own mark, and the public site that would read these is not started. `max_image_dimension` is not enforced and the watermark is not applied. Every one of them now says so on its own row rather than in its help text. |
| 19 | Media | **GREEN** | Upload, scan, storage, delivery, listing with server-side filters, detail, soft delete, purge job, access policy seam, and — new — the upload ceiling read from configuration rather than hard-coded. |
| 20 | Image processing | **RED** | `MediaProcessorContract` exists with one implementation, `GenericFileProcessor`. The container has no `gd`, `imagick` or `ffmpeg`: extensions are `pdo_pgsql, pgsql, pcntl, posix, bcmath, opcache, intl, zip, exif, redis, fileinfo`. |
| 21 | Image variants | **RED** | ADR 0024's extension specifies the whole design — named vocabulary, policy seam, resolution rule — and states none of it is implemented. Blocked on row 20 and on a consumer. Decision 4. |
| 22 | Watermarking | **RED** | Six settings configure it; nothing applies it. Watermarking is a step in deriving an image, so it is blocked behind rows 20 and 21. |
| 31 | Operations | **GREEN** | Audit trail with eight server-side filters, archival with export-verify-remove, configuration export/restore, mail test. |
| 32 | Maintenance mode | **GREEN** | 503 with the platform envelope and a localized message, `admin:access` bypass, fail-open when settings are unreadable, dashboard finding, browser-verified both ways. |
| 33 | Configuration consumption | **YELLOW** | The earlier count of nine was wrong, and the correction is the point of the row. A full audit — every reference in `app/` and `admin/src/`, including the rate limits that are read through a constructed key and would be missed by a naive search — puts it at **thirty of sixty-six**: twenty waiting on the public website, seven on the image pipeline, one on public registration, one on provider retry, one (`security.api_secret_key`) on a machine-to-machine caller that does not exist, and two (`localization.timezone`, `localization.date_format`) that are published for a client to format with and that nothing here will ever read. The secret was the one a search missed — a grep finds what reads a setting by name, and a secret's value never is. Each declares its reach in the definition, publishes it in the catalogue and shows it on the row; a test pins the list, so a thirtieth cannot be added by not noticing. It stays YELLOW because a classified gap is still a gap — the resting place is a reader, not a badge. **Eleven mail settings left this list on 2026-09-10**: they configured only the "send a test message" button, and every real message went out through the deployment's environment file; they now configure the mailer the whole platform resolves. |

### Surface

| # | Capability | State | Evidence and what is missing |
| --- | :--- | :---: | :--- |
| 34 | Dashboard | **GREEN** | Findings derived from published fields only, checks that could not run reported as not run, destinations gated on the permission the target needs. No invented metrics, no decorative charts. |
| 35 | Admin navigation | **GREEN** | One Settings section over General settings, Users, Roles, Permissions; permission-filtered; direct URLs open their section; drawer at narrow widths. |
| 36 | Theme / visual branding | **GREEN** | `--brand-600` is `#335C67`, and every step of the ramp is that hue at another lightness, so the family reads as one colour. Five named tokens — `--brand`, `--brand-hover`, `--brand-active`, `--brand-subtle`, `--brand-foreground` — sit above the ramp and are what every brand-coloured thing reads, so the identity moves in one place. Chosen by measurement: white on `--brand-600` is 7.32:1, brand on the page ground 6.68:1, and in dark the fill is `--brand-300` carrying `--slate-950` at 10.37:1. Info was a desaturated teal one shade away and would have been the same colour under a different name, so it moved to a true blue at hue 224 — 31° from the brand, 34° from `pending` — and is far more saturated at every step, so the two differ in more than hue. Zero radius throughout; Tajawal for both languages. |
| 38 | Dark / light | **GREEN** | Both themes, every screen verified in both, and the contrast re-measured for the brand rather than assumed — the three figures are recorded in row 36. The chrome is near-black in both themes, which is why the active navigation rail and the wordmark read `--brand-on-chrome` rather than the page-ground brand: a defect the rebrand inherited and fixed rather than carried. |
| 39 | Responsive behaviour | **GREEN** | Structural switches rather than CSS hiding, verified at 375 and desktop in both directions with no page-level horizontal overflow. |

### Out of scope, by record

| Capability | State | Record |
| :--- | :---: | :--- |
| SEO contracts and metadata store | **GRAY** | ADR 0032: deliberately unphased until a consumer exists whose requirements can test it. |
| Application authorization for regular users | **GRAY** | ADR 0029 item 8: no consumer; must stay conceptually separate from admin RBAC. |
| Public frontend | **GRAY** | Explicitly excluded from this run. |
| Tenancy / workspace scoping | **GRAY** | ADR 0034: single-tenant deployment model. |

---

## Implementation map

Ordered by dependency, then by how much of the product each unblocks.

### Track A — the visual reset (no backend dependency)

1. Replace the plum primitive family with a brand family built from `#335C67`; move
   `info` off teal so semantic colours stay distinct; rebalance the semantic layer
   rather than substituting a hex.
2. Re-verify contrast: light, dark, RTL, LTR, at the sizes actually used (muted text
   runs to 10px, and the existing `--slate-550` half-step exists because of exactly
   that measurement).
3. Rework the shell, dashboard, tables and panels toward an operational control centre:
   density, hierarchy, typography, intentional whitespace. Zero radius stays.

### Track B — decisions already put to the user

4. Notification producer seam (decision 1) → unblocks rows 24, and makes rows 5 and 6
   worth completing.
5. Provider retry semantics (decision 3) → resolves row 26 and one setting in row 33.
6. Image pipeline scheduling (decision 4) → unblocks rows 20, 21, 22 and part of 18.

### Track C — greenfield, needs its own architecture

7. **AI** (rows 10–12, 15). Nothing exists. The natural shape is ADR 0017's manager
   pattern — a capability, a contract, drivers, usage logging, credentials on a
   provider row — which is the pattern SMS and CAPTCHA already follow and which
   ADR 0033's extension explicitly permits ahead of a consumer. Needs an ADR.
8. **Firebase, push, device registration** (rows 8, 9). Nothing exists. A device
   registry is a new table and a new channel; ADR 0019 anticipates the channel and
   ADR 0017 the transport. Needs an ADR.

### Track D — completion of what is already partly built

9. ~~OTP configuration (row 6)~~ — done 2026-09-10. Four settings, one `OtpPolicy`,
   both flows, each setting proven to change behaviour.
10. ~~Phone verification (row 5)~~ — done 2026-09-10. End to end, browser-verified.
11. Role-definition auditing (row 30).
12. ~~Translation workspace (row 14)~~ — done 2026-09-10. ADR 0043, three sources,
    per-source permissions, browser-verified.
13. The remaining unread settings (row 33). Thirty, not nine, and each now
    declares what is missing rather than being presented as a working control. Thirty, and they
    are unblocked by the capabilities they wait on — the public site, the image
    pipeline, provider retry — not by more settings work.
