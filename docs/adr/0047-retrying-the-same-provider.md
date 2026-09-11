# ADR 0047: Retrying the Same Provider

* **Status**: Accepted
* **Date**: 2026-09-11
* **Amends**: ADR 0017

## Context

ADR 0017 decides *failover*: the providers configured for a capability form a chain, and the dispatcher walks it until one succeeds. It says nothing about retrying the *same* provider, and the two are not the same thing.

`operations.provider_retry_attempts` has existed since Phase 16A — validated between 0 and 10, defaulting to 2 — and nothing read it. Its help text said so. M3 decision 3 put the choice plainly: decide it properly, or remove the setting; a configured control that nothing honours is not a resting place.

The reason it was not obvious is duplicate delivery. A retry against a vendor that may already have accepted the request sends it twice. An SMS send that times out after the vendor queued it becomes two messages to one recipient, and the caller cannot tell the difference from outside.

## Decision

### 1. Retry only a connection that never opened

A request to a vendor is retried if and only if the transport reports that **no connection was established**:

| curl code | Meaning | Retried |
| :--- | :--- | :--- |
| 5 | Could not resolve the proxy | **Yes** |
| 6 | Could not resolve the host | **Yes** |
| 7 | Could not connect (refused, unreachable) | **Yes** |
| 28 | Timed out | **No** |
| 35 | SSL handshake failed | No |
| 52, 56 | Empty reply, receive failure | No |
| — | Any HTTP response, a 5xx included | **No** |

In the three retried cases no byte of the request reached the vendor, so repeating it cannot deliver it twice. That is the whole of the safety argument, and it is why the list is short.

**A timeout is not retried** because curl reports a connect timeout and a read timeout with the same code, and a read timeout is the vendor holding a request it may already have acted on.

**No response is retried**, a 500 included, because an answer proves the request arrived, and whether a vendor's 500 left a message queued behind it is not knowable from outside.

**An SSL failure is not retried** although repeating it would be safe: it is almost always configuration, and retrying would only delay reporting it.

### 2. Idempotency is not available, so ambiguity is not retried

The usual way to make an ambiguous retry safe is an idempotency key the vendor deduplicates on. None of the vendors this platform integrates accepts one on the calls it makes (Twilio's Messages API, reCAPTCHA's siteverify, FCM's `messages:send`, OpenAI's and Anthropic's generation endpoints). So the ambiguous cases are not made safe by deduplication — they are not retried at all, rather than retried hopefully.

### 3. Retries happen inside one provider attempt; the chain takes over after

The order is: attempt provider A, retrying only never-connected failures up to the configured count; if A still failed, the dispatcher moves to provider B exactly as ADR 0017 says.

`integration_usage_logs` records **one row per provider attempt**, not per retry. The retry is part of the attempt: a DNS blip that the second try survived is a successful attempt, and a failure after all retries is one failed attempt carrying the last error.

Failover itself is unchanged, and its existing duplicate hazard is recorded rather than altered here: if provider A times out after accepting an SMS and the chain moves to B, the recipient can receive two. For an OTP both carry the same code; for a notification both carry the same text. Changing failover so an ambiguous SMS failure does *not* advance the chain would trade a possible duplicate for a possible non-delivery of a sign-in code — a different decision, not taken here.

### 4. Where it lives, and how many

`Integration\Services\ProviderHttp::client()` is the one HTTP client every vendor driver uses. It applies `operations.provider_timeout_seconds` (or a capability's own ceiling — AI passes `ai.timeout_seconds`) and the retry policy above. A test fails if any file in the Integration module calls `Http::` directly, so a new driver cannot quietly skip the policy.

* **Attempts**: `operations.provider_retry_attempts` extra tries after the first, 0–10, default 2. Read on every request, guarded like every operational read: a missing or out-of-range value falls back to the default rather than to zero or to unbounded. The setting's reach moves from *awaiting* to *platform*, and its help text now says what it does, in both languages.
* **Backoff**: 100 ms doubling per retry, capped at one second. Never-connected failures are fast, so ten retries of an OTP send still answer in a few seconds rather than keeping a person waiting on a sign-in.

### 5. Every capability, one rule

The rule is applied uniformly — SMS, CAPTCHA, AI, push, and the FCM token exchange — because it is safe for all of them by construction: a request that never reached the vendor has no side effect to duplicate. CAPTCHA still fails closed after the last attempt (ADR 0017's refusal on transport error is unchanged); it simply gets the chance to survive a resolver blip first.

## Consequences

The setting does what its label says, and only the part of what it says that is safe. An operator raising it makes the platform more tolerant of DNS and connection flaps; it cannot make the platform send anything twice.

What it does not buy is recovery from a slow or overloaded vendor. Those fail as timeouts and go to the next provider — which is what a chain is for.

Covered by `tests/Feature/Integration/ProviderRetryTest.php`: a never-resolved host and a refused connection each retried to success; a timeout and a 500 each attempted once; attempts bounded by the setting at 0, 1 and 3 with the matching number of waits; the backoff curve; the curl code deciding, and only for a connection failure (Guzzle's curl handler carries the code only in the message, `cURL error N: …`, so that is where it is read — an unrecognised failure is never retried); an SMS surviving a DNS failure through Twilio with one usage row; an SMS that timed out going to the next provider after exactly one request; and the guard that no driver bypasses the policy.
