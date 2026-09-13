# AlphaMaster — product capability matrix

Audited 2026-09-10 against the repository and the running platform; revised the same
day as rows 5, 6, 14 and 17 were completed, and again on 2026-09-11 as AI, push and
the M3 decisions were built. Every row is
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
| GREEN | 30 |
| YELLOW | 8 |
| RED | 3 |
| GRAY | 4 |

On 2026-09-11: AI settings (12), notifications (23), notification producers (24),
provider retry (26) and the audit trail (30) moved to GREEN, and pre-authentication
rate limiting (41) was added GREEN. AI (10), AI providers (11), AI-assisted translation
(15), Firebase (8) and push (9) moved from RED to YELLOW, and the device registry (40)
was added YELLOW.

**Every one of those YELLOW rows is complete up to an external boundary, and the row
names it.** Five need one real vendor call that cannot be made without the operator's
own account: an OpenAI or Anthropic API key (rows 10, 11, 15), or a Firebase service
account and a device holding a real registration token (rows 8, 9). Each is otherwise
built end to end — capability, contract, drivers, encrypted credentials, permissions,
settings, Admin, failure states — and proven against a faked vendor at the wire; AI was
also run in a browser against a local stub. Row 40 needs a browser check of one Admin
section. None of them is YELLOW for a missing feature.

The three RED rows are the image pipeline (20, 21, 22): no image extension in the
container and, by ADR 0024's own rule, no consumer to derive anything for. They are
built with the first module that attaches media to content, not ahead of it.

The counts above are recomputed from the table rather than kept beside it, because the
previous ones had drifted from what the rows actually said.

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
| 30 | Audit trail | **GREEN** | Configuration, secrets, rollback, archival, backup, mail test, the full account lifecycle including role assignment, and — new — role definitions: `role.created` (identifier and grant), `role.updated` (which locale's label moved, never the wording), `role.permissions_changed` (added and removed, not the resulting set), `role.deleted` (identifier, what it granted, how many accounts held it). A save that changes nothing records nothing, and a first label in a new language is detected against that language's own row rather than the fallback. Labelled in both languages. Seven tests. |
| 41 | Pre-authentication rate limiting | **GREEN** | ADR 0046, closing ADR 0029 item 22. Requests refused at authentication — missing, expired or forged credentials — were answered before the central limiter ran and were unlimited. A global middleware now counts only those refusals, per hashed address, against the anonymous ceiling, and answers 429 with `Retry-After` once past it. It never refuses on the way in, so a hostile caller behind a shared address cannot lock out an authenticated colleague; a wrong password and a failed second factor are not counted, so the login identifier throttle, CAPTCHA and MFA are untouched; a counter outage leaves the 401 standing. Six tests. |

### Messaging

| # | Capability | State | Evidence and what is missing |
| --- | :--- | :---: | :--- |
| 7 | SMS | **GREEN** | Twilio driver over the HTTP client, provider chain with failover, usage logging, timeout now read from configuration. |
| 8 | Firebase / mobile integration | **YELLOW** | ADR 0045: Firebase is a push transport and nothing else — no Firebase Auth, Firestore, Storage or BFF. `IntegrationCapability::PUSH` (constraint widened by migration), an `fcm` driver over the HTTP client against FCM v1, the service-account credential encrypted on the provider row and entered by paste in the Admin (only `project_id`, `client_email`, `private_key` are sent; nothing is echoed), an RS256 access token minted with `openssl` and cached in the `integration` namespace keyed by the provider row and its `updated_at`, so a rotated key never reuses a stale token. Status in the Admin: configured, provider, drivers, last attempt, recent failures. **Missing: one send against real Firebase** — a Google service account for a real project and a device holding a real registration token. Everything short of that is proven against a faked vendor. |
| 9 | Push notifications | **YELLOW** | `NotificationChannel::PUSH` and a `PushChannel`; the payload is **data-only and carries exactly the notification type and the record id** — no subject, no body, no `notification` block — asserted on the wire. Delivery walks the provider chain; `UNREGISTERED`, `INVALID_ARGUMENT` and `NOT_FOUND` delete the device row; a transient failure is logged and the row kept; success stamps `last_seen_at`. Preferences can add push to a mandatory type, and a push preference is accepted by the database (constraint widened). Eighteen backend tests. **Missing: the same real-vendor send as row 8.** |
| 40 | Device registry | **YELLOW** | `push_devices`: many devices per account, one row per handset (`user_id` + client-kept `device_id`, so a rotated token replaces rather than duplicates), the token hidden from every response with a six-character hint instead, the registering session recorded so signing out stops delivery to that handset only. `GET/POST/DELETE /notifications/devices` act only on the caller; the Admin registry (`notifications.view`, removal needs `notifications.update`) shows platform, last seen, stale devices and provider health, and cannot register a device. Six Admin tests. **Missing: the Admin Devices section has not been seen in a browser** — the browser pane's session expired and signing in is not something done on the operator's behalf. |
| 23 | Notifications | **GREEN** | Templates, per-locale rendering, preferences, the in-app inbox, mark-read, and administrator announcements all work end to end and are browser-verified; the push channel joins mail, SMS and in-app (row 9), and the types the platform declares are now raised by the modules that have something to say (row 24). |
| 24 | Notification producers | **GREEN** | Decision 1, option A. `Core\Contracts\PlatformNotifierContract` takes a string type and an `object` recipient, so a producer depends on Core alone and no module boundary moved; Notification implements it. **`security.alert`** is raised when a second factor is added and when it is turned off — not when enrolment merely starts, and not on a failed attempt; **`account.updated`** when an administrator's edit actually changes an account, saying whether the address now needs confirming, and never for a save that changed nothing. A placeholder may be a `Core\Translation\Phrase`, rendered on the queue in the *recipient's* language rather than the producer's — proven with an English-working producer and an Arabic reader. An unknown type, a non-account recipient, or a delivery that fails outright — a missing template on a sync queue, say — is reported and dropped, never thrown into the operation that already happened. A test reads every producer in `app/` and fails unless its type is a string literal naming a real `NotificationType`. Fifteen tests. |
| 25 | Integrations | **GREEN** | Provider list, capability/status/active/default, credential state without ever exposing a secret, usage window, make-default with `PROVIDER_INACTIVE` refusal. |
| 26 | Provider retry / failover | **GREEN** | ADR 0047. Failover is unchanged (ADR 0017). Retry against the same provider now exists and is safe by construction: only a connection that never opened — host not resolved, connection refused, curl 5/6/7 — is retried, because only then did the vendor never see the request. A timeout, an SSL failure and every vendor answer, a 500 included, go to the next provider after exactly one attempt, so no SMS is sent twice by a retry; none of the integrated vendors offers an idempotency key that would make the ambiguous cases safe. `operations.provider_retry_attempts` is read on every call through `ProviderHttp`, the one client every driver uses (a test fails if any driver calls `Http::` directly); backoff doubles from 100 ms, capped at one second; one usage row per provider attempt. Twelve test cases, including an SMS surviving a DNS failure and an SMS that timed out going to the next provider. |

### AI

| # | Capability | State | Evidence and what is missing |
| --- | :--- | :---: | :--- |
| 10 | AI | **YELLOW** | ADR 0044. `Core\Ai\TextGeneratorContract`, so a module asks for text without importing Integration; the default provider only, **no automatic failover** (a second model answers differently, which is not a transport failure); prompts owned by code; usage recorded with token counts and never with the prompt or the answer. The AI Control Centre at `/ai` shows configured/unconfigured, provider, model, drivers, credential state without the credential, last attempt and recent failures, and runs a health check behind `ai.use`. Verified in a browser end to end against a local OpenAI-compatible stub: check answered, suggestion generated on the queue, edited, accepted, persisted. **Missing: one call against a real vendor account** — an OpenAI or Anthropic API key. |
| 11 | AI providers | **YELLOW** | `IntegrationCapability::AI`; `openai` (chat completions, base URL a provider setting so any compatible gateway works) and `anthropic` (messages API, pinned version header) behind `AiManager`, credentials encrypted on the row, seeded inactive. Success, vendor error, empty answer, timeout, missing credential and unconfigured are each proven with `Http::fake()` at the wire. **Missing: the same real-vendor call as row 10.** |
| 12 | AI settings | **GREEN** | `ai.translation_model`, `ai.max_output_tokens` (32–4096) and `ai.timeout_seconds` (5–300), each labelled and explained in both languages, each shown to change the request that reaches the vendor. No `ai.enabled` switch: a configured provider is what enables AI, so there is one control rather than two that can disagree. |
| 15 | AI-assisted translation | **YELLOW** | The workshop proposes; a person decides. A request queues one job per field on the `integrations` queue; outstanding requests are not duplicated; a translated field is skipped unless asked for; the suggestion is never written to the content — accepting is a separate action, refused with `TRANSLATION_MOVED` if somebody changed the field since, and records whether the text was edited. The panel distinguishes waiting, AI-suggested, edited and not generated. Without a provider the manual workflow is untouched and the AI action says why it is unavailable. Sixteen backend tests, fifteen Admin tests, browser-verified against the stub. **Missing: the same real-vendor call as row 10.** |

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
| 17 | General settings | **GREEN** | Sixty-nine typed settings with grouping, per-locale values, secrets, history, rollback, backup/restore, optimistic concurrency, a label and help sentence for every one in both languages, and — new — a declared **reach**: every definition says whether the platform reads it, a client does, or nothing does yet, and the console renders that as a badge and a sentence on the row. The capability is complete; what remains is row 33, which is a statement about how much of the catalogue has a reader rather than about this screen. |
| 18 | Branding | **YELLOW** | Seven media-typed settings — logos in four combinations, favicon, social image, watermark image — each holding a validated `MediaFile` id, assigned through a picker. Nothing renders any of them: the console ships its own mark, and the public site that would read these is not started. `max_image_dimension` is not enforced and the watermark is not applied. Every one of them now says so on its own row rather than in its help text. |
| 19 | Media | **GREEN** | Upload, scan, storage, delivery, listing with server-side filters, detail, soft delete, purge job, access policy seam, and — new — the upload ceiling read from configuration rather than hard-coded. |
| 20 | Image processing | **RED** | `MediaProcessorContract` exists with one implementation, `GenericFileProcessor`. The container has no `gd`, `imagick` or `ffmpeg`: extensions are `pdo_pgsql, pgsql, pcntl, posix, bcmath, opcache, intl, zip, exif, redis, fileinfo`. |
| 21 | Image variants | **RED** | ADR 0024's extension specifies the whole design — named vocabulary, policy seam, resolution rule — and states none of it is implemented. Blocked on row 20 and on a consumer. Decision 4. |
| 22 | Watermarking | **RED** | Six settings configure it; nothing applies it. Watermarking is a step in deriving an image, so it is blocked behind rows 20 and 21. |
| 31 | Operations | **GREEN** | Audit trail with eight server-side filters, archival with export-verify-remove, configuration export/restore, mail test. |
| 32 | Maintenance mode | **GREEN** | 503 with the platform envelope and a localized message, `admin:access` bypass, fail-open when settings are unreadable, dashboard finding, browser-verified both ways. |
| 33 | Configuration consumption | **YELLOW** | The earlier count of nine was wrong, and the correction is the point of the row. A full audit — every reference in `app/` and `admin/src/`, including the rate limits that are read through a constructed key and would be missed by a naive search — puts it at **thirty-one of sixty-nine**, counted from the registry itself on 2026-09-11 rather than by hand: twenty waiting on the public website, seven on the image pipeline, one on public registration, one (`security.api_secret_key`) on a machine-to-machine caller that does not exist, and two (`localization.timezone`, `localization.date_format`) that are published for a client to format with and that nothing here will ever read. The secret was the one a search missed — a grep finds what reads a setting by name, and a secret's value never is. Each declares its reach in the definition, publishes it in the catalogue and shows it on the row; a test pins the list, so a thirty-second cannot be added by not noticing. It stays YELLOW because a classified gap is still a gap — the resting place is a reader, not a badge. **Eleven mail settings left this list on 2026-09-10**: they configured only the "send a test message" button, and every real message went out through the deployment's environment file; they now configure the mailer the whole platform resolves. |

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

4. ~~Notification producer seam (decision 1)~~ — done 2026-09-11. Row 24.
5. ~~Provider retry semantics (decision 3)~~ — done 2026-09-11, ADR 0047. Row 26.
6. Image pipeline scheduling (decision 4) → unblocks rows 20, 21, 22 and part of 18.
   Unchanged: scheduled with the first content module.
7. ~~Pre-authentication rate limiting (decision 2)~~ — done 2026-09-11, ADR 0046. Row 41.

### Track C — greenfield, needs its own architecture

8. ~~**AI** (rows 10–12, 15)~~ — built 2026-09-11, ADR 0044. Remaining: one call
   against a real vendor account.
9. ~~**Firebase, push, device registration** (rows 8, 9, 40)~~ — built 2026-09-11,
   ADR 0045. Remaining: one send through a real Firebase project; the Admin Devices
   section seen in a browser.

### Track D — completion of what is already partly built

9. ~~OTP configuration (row 6)~~ — done 2026-09-10. Four settings, one `OtpPolicy`,
   both flows, each setting proven to change behaviour.
10. ~~Phone verification (row 5)~~ — done 2026-09-10. End to end, browser-verified.
11. ~~Role-definition auditing (row 30)~~ — done 2026-09-11.
12. ~~Translation workspace (row 14)~~ — done 2026-09-10. ADR 0043, three sources,
    per-source permissions, browser-verified.
13. The remaining unread settings (row 33). Thirty-one, not nine, and each now
    declares what is missing rather than being presented as a working control. They
    are unblocked by the capabilities they wait on — the public site, the image
    pipeline — not by more settings work. Provider retry left the list on 2026-09-11.
