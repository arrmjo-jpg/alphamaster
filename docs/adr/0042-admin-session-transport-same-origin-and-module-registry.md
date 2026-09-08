# ADR 0042: Admin Session Transport, Same-Origin Deployment, and the Module Registry

* **Status**: Accepted
* **Date**: 2026-09-08

## Context

The Admin frontend is about to be built, and three questions have to be settled before any of it is written, because each one is expensive to change afterwards and cheap to state now.

**How does a browser hold a credential?** Every existing consumer of this API is a test. The perimeter of ADR 0012 is built on Sanctum token abilities, and a token has so far travelled in an `Authorization` header put there by whoever made the request. A browser is a different kind of caller: it has no safe place to keep a bearer token, and the two conventional answers — `localStorage` and `sessionStorage` — both hand the credential to any script that runs on the page.

**Where does the Admin live relative to the API?** A separate origin means CORS, credentialed cross-origin requests, and a build-time API base URL. The same origin means none of those.

**How do modules reach the shell?** ADR 0009 chose a typed static module registry. It described the idea and not the contract, and the shell cannot be written against an idea.

## Decision

### 1. The access token travels in an HttpOnly cookie, and remains a Sanctum token

**The token does not change. Only its transport does.**

Sign-in continues to mint a real `PersonalAccessToken` carrying exactly one ability, exactly as ADR 0012 requires. The change is that the plaintext token is additionally set in an `HttpOnly` cookie rather than being expected to travel only in a header the client writes.

The mechanism is `Sanctum::getAccessTokenFromRequestUsing()`, a published extension point that the guard consults before falling back to `bearerToken()`. Precedence is cookie first, then bearer.

The consequence that matters: `$request->user()->currentAccessToken()` still returns a real `PersonalAccessToken`, so `tokenCan()` reads real abilities and **every stage of the ADR 0012 perimeter continues to work unchanged**.

#### Why not Sanctum's SPA mode

Sanctum ships a first-party answer to this question — `EnsureFrontendRequestsAreStateful` — and this platform deliberately does not use it. **It is not registered anywhere and must not be.**

The reason is one line of vendor code:

```php
// Illuminate: Laravel\Sanctum\TransientToken
public function can($ability) { return true; }
```

A session-authenticated request carries a `TransientToken`, and `CheckForAnyAbility` asks it `tokenCan()`. It answers `true` for every ability without looking at it. Turning on SPA statefulness would therefore make `ability:admin:access` a no-op — and, worse, would delete the scoping of `mfa:enrol` and `email:verify`, which is the entire mechanism by which ADR 0013 and ADR 0012 stop an un-enrolled or unverified administrator from holding administrative access.

That is not a trade-off to weigh. It silently removes two security boundaries while every test that checks a *route* still passes.

#### Cookie attributes

| attribute | value | why |
| :--- | :--- | :--- |
| `HttpOnly` | yes | Script cannot read it. This is the whole reason for the change. |
| `Secure` | yes | It is a credential; it does not travel in clear. |
| `SameSite` | `Strict` | The primary CSRF defence. A cookie the browser will not attach to a cross-site request cannot be used by one. |
| `Path` | `/api` | It is sent to the API and to nothing else, including the static assets served from the same origin. |

`SameSite=Strict` is what makes this safe without a CSRF token. It is available *because* of the same-origin decision below: a cross-origin Admin could not use `Strict` at all, and would have needed `None` plus a synchroniser token — more machinery, and weaker.

#### What does *not* go in the cookie

The MFA challenge token (`mfa_token`) stays in the response body. It is not a Sanctum token and grants nothing on its own; it identifies an in-progress challenge. Putting it in the authentication cookie would confuse a credential with a correlation identifier and would make the challenge survive in a place the client cannot deliberately discard.

The scoped credentials — `mfa:enrol` and `email:verify` — are real Sanctum tokens and travel like any other.

#### Bearer stays

The header path is unchanged and untouched. Removing it would break every existing test and every non-browser consumer for no benefit; the cookie is additive. A request may present either, and cookie wins when both are present.

### 2. The Admin is served from the same origin as the API

Nginx serves the Admin application at `/` and proxies `/api` to the backend. One origin, one cookie domain.

This follows from the transport decision rather than being independent of it. `SameSite=Strict` requires it. It also removes, rather than configures, a set of problems: no CORS policy to publish, no credentialed cross-origin preflight, no `supports_credentials`, and no build-time API base URL — the Admin calls `/api/v1/...` relatively, so one built image is correct in every environment.

The Admin container joins the edge network and nothing else, which is what ADR 0041 already reserved for it: *"the future admin and public frontend containers belong here and nowhere else."* It has no route to the data tier by construction.

`config/cors.php` stays unpublished. The framework default is never exercised, because nothing is cross-origin.

### 3. The module registry is a typed manifest

ADR 0009's registry becomes a contract the shell can be written against. A module exports one manifest declaring:

* an identifier and a route path;
* a label resolved through i18n, never a literal string;
* an icon;
* the permission a viewer must hold for it to appear;
* its ordering.

The shell imports the registry; it never imports a module. Adding a workspace is adding a manifest.

**Navigation is filtered by the permissions on `/auth/me`, and that is presentation only.** The API remains the authorization boundary. Hiding an item the caller may not use is a courtesy — showing it and letting the request 403 is a worse interface — but nothing about the frontend's filtering is relied upon by anything. A module that renders because the client was wrong still meets the perimeter of ADR 0012 on every request it makes.

The word *workspace* in ADR 0006 means a screen layout. It does not mean a tenant, and ADR 0034 remains in force: no `tenant_id`, no `workspace_id`, no tenancy of any kind.

## Consequences

The perimeter of ADR 0012 and the MFA invariant of ADR 0013 hold under the new transport without modification, because the transport does not touch what they inspect. That is the property this design was chosen for, and it is re-proven test by test rather than asserted: every ability refusal, both scoped credentials, and all five perimeter stages are exercised cookie-borne as well as bearer-borne.

An XSS on the Admin can still *act* as the user for as long as the page is open — an HttpOnly cookie is attached by the browser to requests the page makes. What it can no longer do is read the credential and exfiltrate it for use elsewhere and later. That is a real reduction and it is not total, and it is worth being exact about which.

Sign-in responses continue to carry the token in the body as well. Removing it would break the bearer flow every test uses, and there is no benefit in withholding from a client something it has just been given by the same response.

The same-origin decision ties the Admin's deployment to the API's. A future need to host them apart means revisiting `SameSite=Strict` and publishing a CORS policy, and this record is the place that starts.

## Alternatives considered

**A bearer token in `localStorage`.** The conventional SPA answer. Rejected: any script on the page can read it, and this application's credential opens an administrative API. The convenience is real and the exposure is unbounded in time.

**A bearer token held only in memory.** Genuinely safe from exfiltration, and it makes every page refresh a re-authentication — through a captcha and, for an administrator, a second factor. Rejected as a usability cost with no matching security gain over an HttpOnly cookie for this threat model.

**Sanctum SPA statefulness.** Rejected for the `TransientToken` reason above. It is the framework's recommendation for a first-party SPA and it is incompatible with a perimeter built on token abilities.

**A separate origin with CORS.** Rejected: it forces `SameSite=None`, requires a CSRF token to replace what `Strict` gives for free, and adds a build-time base URL for no benefit this deployment needs.
