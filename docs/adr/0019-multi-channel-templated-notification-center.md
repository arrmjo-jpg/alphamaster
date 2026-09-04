# ADR 0019: Multi-Channel Templated Notification Center

* **Status**: Accepted
* **Date**: 2026-09-03
* **Revised**: 2026-09-03 — implemented for database, mail and SMS
* **Revised**: 2026-09-04 — notification identifiers confirmed as technical; display labels referred to ADR 0030

## Context

Notifications must dispatch across Database, Email, SMS, and WhatsApp with translatable templates and user preference controls.

## Decision

Extend Laravel Notifications with `notification_templates` and `notification_preferences`. Notification jobs are queued on the dedicated `notifications` Redis queue (ADR 0020), so a burst of them cannot delay user-facing work on the default queue.

**Templates.** Wording lives in the database, not in code: one template per registered notification type, with subject and body translated relationally per ADR 0015 — a locale is a row in `notification_template_translations` keyed `UNIQUE(template_id, locale)`, not a JSON key. A missing locale falls back to the platform default rather than to an empty message, and a notification with no active template raises rather than delivering nothing, because sending an empty message would leave the recipient uninformed and nobody aware the template was missing. Placeholders are substituted literally and never re-interpreted, so a value cannot itself be treated as a placeholder.

**Channels.** Database, mail and SMS. SMS dispatches through the Integration module (ADR 0017), so the channel owns no transport and a vendor change is invisible to it. The recipient's number is the one confirmed during MFA enrolment, reached through a Core contract that Auth implements: neither module depends on the other, which keeps Auth's existing dependency on User from becoming a cycle. A recipient with no reachable number is skipped rather than failing the notification, so one unavailable route does not cost the others. WhatsApp and push arrive when the Integration capabilities they need do.

**Preferences.** Per notification type and per channel, so a recipient can decline account updates by SMS while still receiving security alerts everywhere. Absence of a row means the notification's own defaults apply — a user who has never opened the settings screen is not represented by rows asserting the obvious.

Two things are not preferences. The in-app record is always written: it is the audit trail of what the platform decided to tell someone, and silencing it would leave no evidence a notification was raised. A security alert is never silenced on any channel: a setting that lets someone mute the message telling them their account was compromised exists only to be regretted. Both rules are evaluated at delivery rather than merely enforced when a preference is written, so a row stored before the rules tightened cannot suppress a message that is now mandatory.

**Locale.** A notification is read by its recipient, not by whoever triggered it, so it renders in the recipient's own `preferred_locale` and falls back to the platform default — never to the locale of the request that happened to cause it.

## Extension — 2026-09-04: identifiers, labels, and this module as precedent

This section adds no new mechanism. It records what this module already demonstrates, because the foundation gap audit found the pattern implemented here and nowhere else.

**Notification content was already solved correctly.** `notification_templates.type` holds a technical identifier — `security.alert`, `account.updated`, `admin.announcement` — and the human text lives in `notification_template_translations`, one row per locale, keyed `UNIQUE(template_id, locale)`. A technical key and translated human content, stored separately. That is precisely the separation ADR 0030 now states as a platform-wide principle, and this module is where it first shipped.

**What is still raw.** The type and channel identifiers themselves reach clients unlabelled. A preferences interface built on the current API would render `security.alert` and `sms` as the names a person chooses between.

These identifiers are code-defined — `NotificationType` and `NotificationChannel` are enums — so by ADR 0030 their labels belong in `lang/{locale}.json` and not in a table:

```
"enum.notification.type.security.alert": "تنبيه أمني"
"enum.notification.channel.sms":         "رسالة نصية"
```

The identifiers themselves do not change. `type` is written into `notifications.type`, matched by preference rows and asserted by tests; it is contract. ADR 0031 fixes the payload shape that pairs each with its label.

**Template subject and body are unaffected.** They are runtime-authored content and stay exactly where they are. Nothing in ADR 0030 moves them.

**Implementation status.** Template translation is implemented. Type and channel labels are **not implemented**; tracked in ADR 0029.

## Consequences

Separates notification template management from code and respects user communication channels. Adding a notification is an enum case plus a template row rather than another notification class, and adding a channel is a driver plus a preference column value.

The generic `HasTranslations` trait ADR 0015 prescribed but which was never built now exists in Core, resolving the active locale through `LocaleResolverInterface`. Any module can make an entity translatable without depending on the Localization module — the same inversion ADR 0015 established for `SetLocale`. Notification templates are its first consumer.
