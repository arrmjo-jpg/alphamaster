# ADR 0046: Pre-Authentication Rate Limiting

* **Status**: Accepted
* **Date**: 2026-09-11
* **Closes**: ADR 0029 item 22

## Context

ADR 0022 requires composite rate limiting, and Phase 14 built it: `ApplyRateLimit`, on the `api` middleware group, classes each request by its resolved route and identifies it by its authenticated user where there is one, by its address where there is not.

Laravel sorts `Authenticate` ahead of the `api` group by middleware priority. A request carrying a missing, expired or forged token is therefore answered 401 by `Authenticate` before `ApplyRateLimit` runs, and those requests were unlimited. ADR 0029 item 22 recorded this as open, with no decision behind it, because the fix is a design question:

> a limiter that runs before authentication cannot identify a user, so it has only the address to key on

and keying on an address alone lets one caller behind a shared address — an office, a mobile carrier's NAT — exhaust the bucket for every other caller behind it. For anonymous traffic the platform already accepts that trade: the `public-read` class keys on the address because there is nothing else. The hazard is specific to refusing *authenticated* traffic on the strength of an address.

What had to be preserved, unchanged: CAPTCHA, MFA, the login identifier throttle (`LoginThrottle`, keyed on identifier and address), the `auth` class ceiling on the login and challenge routes, and authentication failing closed.

## Decision

### 1. Meter refusals, never admissions

`Core\Middleware\LimitRejectedAuthentication` runs as **global** middleware, appended after `SetLocale`, so it wraps routing and therefore wraps `Authenticate` regardless of how the route's middleware is sorted.

It does nothing on the way in. On the way out it asks one question: was this request refused at authentication? If so, it counts the refusal against the address; once the address is past the ceiling, further refusals are answered **429 `TOO_MANY_ATTEMPTS` with `Retry-After`** instead of 401.

A request that authenticates is never touched. A request that did not was going to be refused anyway, and still is — the only thing that changes is which refusal it receives. Nothing in this middleware can turn a refusal into an admission, so authentication stays exactly as fail-closed as it was.

The alternative — refusing on the way *in* once an address is over the limit — was rejected because it is the shared-address hazard itself: a hostile caller behind an office's address could lock every signed-in colleague out of the platform by sending forged tokens.

### 2. What counts as "refused at authentication"

Only an `AuthenticationException` — the exception `Authenticate` raises for a missing or invalid credential. The exception renderer in `bootstrap/app.php` is the one place that knows a given 401 is that, so it marks the request (`LimitRejectedAuthentication::REJECTED`) as it renders the response.

Deliberately **not** counted, although each answers 401:

| Response | Why not |
| :--- | :--- |
| `INVALID_CREDENTIALS` from login | A wrong password, not a rejected credential. It has the identifier throttle and the `auth` class ceiling already; counting it here would add an address-only lockout to login, which is the hazard above. |
| `MFA_CHALLENGE_FAILED` | A second-factor failure by a caller who may already be authenticated. MFA's own throttles own it. |

### 3. The ceiling is the anonymous one

A caller that failed authentication is anonymous, so its refusals are counted against `rate_limit.public_read_per_minute` — the number an operator already reads as "what one address may do unauthenticated" — over a one-minute window, in its own bucket (`rejected-auth:ip:<sha1>`), so reading public endpoints and provoking refusals do not spend each other's allowance.

No new setting. The existing one's help text now says that it bounds rejected credentials too, in both languages.

The address is hashed in the key, as every limiter key in this platform is.

### 4. An outage costs the metering, never the refusal

The count lives in Redis. If Redis cannot be read, the middleware logs a warning and returns the 401 it was given. `ApplyRateLimit` fails *open* on the same outage because it would otherwise refuse admitted traffic; this one has nothing to fail open *to* — the request is refused either way.

## Consequences

The path ADR 0029 item 22 described is metered: a client hammering the API with forged or stale tokens is told to back off, with a machine-readable wait, and the edge (ADR 0041) sees the 429s it keys its own limits on.

The platform still does the work of rejecting each credential — one indexed token lookup — before the count is consulted. That is the price of never refusing a valid credential on an address's account, and it is small: Sanctum's lookup is by primary key followed by a hash comparison. A deployment that needs to shed that load before it reaches PHP does it at the reverse proxy, where the same 429s are now visible to key on.

Requests that match no route are still not counted: there is no authentication to reject. They are answered 404 cheaply, before any database access, and are the edge's to limit.

Covered by `tests/Feature/Core/RejectedAuthenticationLimitTest.php`: the ceiling and the 429 shape, a request with no credential at all, a valid credential served from an address that is over the ceiling, per-address isolation, failed logins not spending the count, and a counter outage leaving the 401 standing.
