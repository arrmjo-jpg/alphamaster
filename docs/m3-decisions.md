# M3 — four decisions, with recommendations

Written 2026-09-10. Not an ADR: nothing here is decided. Each section states the
question, why the work stopped at it, the options with their real costs, and a
recommendation. Whichever way each goes, the answer belongs in an ADR afterwards.

---

## 1. Nothing in the platform may raise a notification

**The state.** The Notification module has templates, per-locale rendering, channels,
preferences, an admin editor, an inbox and an announcement sender. Three notification
types are seeded, active and translated. Two of them — `security.alert` and
`account.updated` — have never been raised once, because no module is allowed to raise
them.

**Why.** `NotifierContract` lives in `App\Modules\Notification` and takes a
`NotificationType`, also a Notification enum. The architecture rules forbid every
module that would produce a notification from importing that namespace:

| Module | May import Notification? |
| :--- | :--- |
| Auth | no |
| User | no |
| Settings | no — Core and Framework only |
| Authorization | no — Core, User and Framework only |
| Localization | no — Core and Framework only |
| Core | no |
| Integration | no — may not depend on its consumers |

Announcements were the one case that needed no decision: the Notification module raises
them itself, so no boundary is crossed. Everything else is blocked here.

### Options

**A. A Core-owned contract taking a string identifier.** Core declares the seam;
Notification implements it; a producer depends only on Core.

```php
// App\Modules\Core\Contracts\PlatformNotifierContract
public function notify(object $recipient, string $type, array $placeholders = []): void;
```

This is the pattern the platform already uses twice for exactly this shape.
`SmsRecipientResolverInterface` is declared in Core, implemented by Auth and consumed by
Notification — "so the module that knows the number and the module that sends the
message need not know about each other". `LocaleResolverInterface` is the same
inversion. Both take `object`, so Core names no domain model.

*Cost.* A producer writes `'security.alert'` rather than an enum case, losing type
safety at the call site. Mitigated the way route-file permissions were just mitigated:
a test asserting every type literal in the codebase names a real `NotificationType`.
That guard exists and works.

*Not required.* Moving `NotificationType` into Core. ADR 0019 makes it contract-grade —
written into `notifications.type`, matched by preference rows, asserted by tests — and
moving it would touch twenty-six files to solve a problem a string plus a guard already
solves.

**B. Core-owned domain events, Notification listens.** Producers dispatch
`AccountDeactivated`; Notification decides that it is worth telling somebody about.
Producers stay entirely ignorant that notifications exist, which is the strongest form
of the boundary the rules are protecting. It is also the largest change: an event class
per occasion, a listener registry, and a second indirection to follow when debugging why
a message did or did not arrive.

**C. Relax the rules.** Permit a one-way dependency on Notification. Cheapest to write
and the least defensible: the rules exist because a foundation whose modules may reach
each other stops being modular, and this would be the first exception.

### Recommendation

**A.** It follows a precedent this codebase set twice, needs no enum to move, and the
one thing it gives up — a typed call site — is recoverable with a guard the project
already uses. B is the more principled design and worth revisiting if producers ever
multiply; today there would be perhaps six.

### What it unblocks

`security.alert` on second-factor changes, `account.updated` on an administrator
editing an account, and any future producer. Roughly a day's work once decided.

---

## 2. Pre-authentication rate limiting

**The state.** ADR 0029 item 22, open, and it already says an ADR is required. The
central limiter is `api`-group middleware and Laravel hoists `Authenticate` ahead of the
group, so a request carrying an invalid or expired token is answered 401 before the
limiter is reached. Those requests are unlimited. The endpoint throttles cover login,
MFA challenge and MFA delivery — not every authenticated route rejected at the door.

**The question, unchanged since Phase 14.** A limiter that runs before authentication
cannot identify a caller, so it has only the address. What to do with that is the
decision: keying on an address alone lets one caller behind a shared address exhaust the
bucket for everyone behind it, and doing nothing leaves an unmetered path into the API.

**Recommendation.** Take it as its own piece of work rather than folding it into a
feature phase. It is security-shaped, it needs its own ADR, and it interacts with
routing in ways that want deliberate design rather than an implementation written
alongside something else. Nothing else is blocked by it.

---

## 3. Retrying the same provider

**The state.** `operations.provider_retry_attempts` is configured, validated between 0
and 10, defaults to 2, and is read by nothing. `provider_timeout_seconds` beside it now
is read; this one deliberately still is not.

**Why it is not obvious.** ADR 0017 decides *failover*: providers form a chain and the
dispatcher walks it until one succeeds. It says nothing about retrying the same
provider, and the two are not the same thing. A retry against a vendor that may already
have accepted the request is a duplicate-delivery hazard: an SMS send that times out
after the vendor queued it becomes two messages to one recipient, and the caller cannot
tell the difference.

**What a decision would have to settle.** Which failures are retryable — a connection
error is, an HTTP 500 might be, a vendor rejection is not; whether retries happen before
or after the chain moves on; and whether a capability declares itself idempotent rather
than the platform assuming it.

**Recommendation.** Either decide it properly — retry only on connection-level failure,
never on a response the vendor actually returned, with the chain taking over afterwards
— or remove the setting. A configured control that nothing honours is the defect this
phase spent its time removing; leaving one behind because it is hard is the worst of the
three outcomes. Its help text currently says it is not applied, which is honest but is
not a resting place.

---

## 4. Image variants and watermarking

**The state.** ADR 0024's extension specifies the whole thing — named variants, the
closed vocabulary, watermark configuration, the policy seam, what happens when a
variant does not exist — and states plainly that none of it is implemented. Seven
branding settings configure a watermark that nothing applies, and
`branding.max_image_dimension` cannot be enforced without it.

**Two things stand in the way, and only one is technical.**

*The container has no image extension.* Verified again: `pdo_pgsql, pgsql, pcntl, posix,
bcmath, opcache, intl, zip, exif, redis, fileinfo`. Adding `gd` is a Dockerfile change
and a rebuild.

*Nothing would use it.* ADR 0024 gives an asset its variant set through a policy
registered by the module that owns it, and says an asset no policy claims has the
original alone. Branding registers no policy — deliberately, because logos and favicons
are original-only by design. No content module exists. So with the pipeline built,
this deployment would derive nothing.

**Recommendation.** Schedule it with the first module that attaches media to content,
and build it as part of that work rather than ahead of it. That is what ADR 0033's
"a capability with no consumer is speculative surface" already asks for, and the
alternative is a processing pipeline whose only test coverage is its own unit tests.

If the watermark settings are uncomfortable in the meantime, the honest interim is what
is already there: their help text says the platform does not apply them.

---

## Not decisions, but worth knowing

**Role definitions are unaudited.** Creating a role, changing what it grants, deleting
one — none of it reaches the trail, while every account operation now does. ADR 0037's
amendment names it as the acknowledged gap and the argument for closing it is the same
one that closed accounts. This needs no decision, only the work.

**`general.registration_enabled` is a setting with no endpoint.** The platform publishes
no registration route, so the value is a statement of intent a client may read. Either a
registration flow arrives and honours it, or the setting should go.
