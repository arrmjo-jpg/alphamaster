# ADR 0053: Edge Delivery — HTTP Cache Profiles and CDN Invalidation

* **Status**: Accepted
* **Date**: 2026-09-14
* **Implements**: ADR 0036 (HTTP cache semantics and the CDN boundary)
* **Extends**: ADR 0017 (vendor drivers), ADR 0035 (application cache), ADR 0037 (audit), ADR 0052 (module extension points)

## Context

ADR 0036 decided how HTTP caching and a CDN must behave, but none of it was built:
* the platform emitted no cache headers of its own;
* `cdn.enabled` and `cdn.base_url` rewrote media URLs;
* nothing could purge an edge.

Every future domain module will need the same three things:
* a way to mark its public responses cacheable;
* a way to label them for the edge;
* a way to purge those labels when content changes.

It must get all three without knowing which vendor fronts the platform.

Operational experience from an earlier news platform showed what goes wrong when the capability is built inside one domain:
* Purge URLs were hand-built from article routes and silently went stale when the routes changed.
* A shared, non-atomic buffer lost purges under concurrent writes.
* A worker that swallowed vendor failures reported stale content as fresh.
* The operator chose the vendor plan by hand, and nothing used it.
* Two implementations of the same settings drifted apart.

This record is written so none of those can recur.

## Decision

### 1. Three layers, three contracts

| Layer | Contract (Core) | Invalidated by | Owner |
| :--- | :--- | :--- | :--- |
| Application cache | `PlatformCacheContract` | namespace and key (ADR 0035) | the module owning the namespace |
| HTTP representation | `http.cache:<profile>` route middleware | expiry and validators | the module owning the route |
| CDN edge cache | `EdgeCacheContract` | a purge request | the module owning the content |

They are never collapsed. Forgetting an application cache key does not reach an edge node, and an edge purge does not touch Redis. A change that affects all three calls all three.

### 2. HTTP cache profiles

**A route is uncacheable unless it names a profile.**
* `ClassifyHttpCaching` is the outermost API middleware. It marks every unclassified response `no-store, private`, errors included.
* A controller that sets `private` with a lifetime has already kept shared caches out, and is left alone. This is how a media file behind authentication is served.
* A `public` header without a profile is not trusted and is overwritten.

A profile (`HttpCacheProfile`) declares a browser lifetime, an edge lifetime, `stale-while-revalidate`, `stale-if-error`, and whether the payload is localized. Core registers `public-configuration`, which the public settings and language endpoints use. A module registers its own profiles with `HttpCacheProfileRegistry`.

`ApplyHttpCachePolicy` makes the decision from the actual request and response:
* **Public only when the response is anonymous and successful.** That means a GET or HEAD, status 200, no `Set-Cookie`, and no Authorization header, cookie or resolved user on the request. Anything else is `no-store`.
* **Separate lifetimes.** `Cache-Control` governs the browser, and the standard `CDN-Cache-Control` (RFC 9213) governs the edge. The edge copy can be long because it can be purged; the browser copy stays short because it cannot.
* **Locale belongs in the address.** A localized response is stored at the edge only when the language came from the `?locale=` query parameter. When it came from `X-Locale` or `Accept-Language`, the edge gets `no-store` and the browser gets a `Vary`. This is ADR 0036's rule, applied because edges key on the URL.
* **Validators come from the bytes.** A weak ETag is derived from the payload and the locale, and a matching `If-None-Match` is answered with 304.

### 3. Edge cache tags

A tag is built with `EdgeCacheTag::for(owner, ...parts)`, the same way a cache key is built:
* A route declares the tags it always carries, and a controller or service can add more to the request-scoped `ResponseCacheTags`.
* The policy writes them only for a response the edge may store, in the header the configured edge reads (`EdgeCacheContract::tagHeader()`). With no edge configured, no tag header is sent.
* The code that tags a response and the code that purges it use the same builder, so the two cannot drift into different spellings.

The platform's own tags today are `settings:public` and `localization:languages`.

### 4. The CDN capability

CDN is an Integration capability (`cdn`) behind ADR 0017's manager. Cloudflare is the first driver; Bunny, Fastly or CloudFront would each be one more driver class.

**The driver (`CdnProviderContract`) owns everything vendor-specific:**
* the configuration fields it needs, and which required ones are missing;
* its limits per plan;
* verifying the configured scope;
* the purge call;
* the tag header it reads.

**Nothing is purged inline.** `CdnEdgeCache::invalidate()`:
1. splits the invalidation to the vendor's per-call limit;
2. records one `cdn_purge_requests` row per call inside a transaction;
3. dispatches `ProcessCdnPurgeRequest` after commit;
4. returns `queued`, `not_configured` or `unsupported`, and never "done".

**The row is the state.** A worker claims a row with one conditional `pending → processing` update, so two workers cannot call the vendor for the same row. Then:
* **A spent plan budget** defers the row without counting an attempt.
* **A retryable failure** (429, 5xx, a connection that never opened) is deferred by the vendor's `Retry-After`, or by a doubling backoff, for up to five attempts.
* **Anything else** is final: the row is `failed` with the vendor's code and message until an operator retries it.
* **Success** is recorded only when the vendor accepted the purge.
* Every attempt is also written to the integration usage log.

**Lost work is recovered.** `SweepCdnPurgeRequests` runs every five minutes. It resets rows stuck in `processing` and re-dispatches `pending` rows whose dispatch never arrived. Finished rows are pruned after the integration retention window; unfinished ones never are.

**Limits come from the vendor.** Verification reads the scope's name, status and plan from the vendor and stores them on the provider row as `detected_*`. Changing the configured zone discards them. Until a scope is verified, the most restrictive plan's limits apply.

**No failover.** A zone belongs to one vendor, and purging another vendor's cache does not remove what the first one serves.

### 5. Permissions and audit

| Permission | Allows |
| :--- | :--- |
| `cdn.view` | Reading the workspace and the purge list |
| `cdn.purge` | Purging URLs, tags, prefixes or hosts, and retrying a failed purge |
| `cdn.purge_everything` | Purging everything. It also needs the verified scope's name typed back, and is limited to three per operator per hour |
| `integrations.update` | Configuring the vendor and verifying the scope, as for every other vendor |

`cdn.purge_everything` is held by super_admin only until an operator grants it.

Audited actions:
* `cdn.purge_requested`
* `cdn.purge_everything_requested`
* `cdn.purge_retried`
* `cdn.scope_verified`
* `cdn.purge_everything_completed` — written by the worker when a purge of everything is final, succeeded or failed, with the vendor's reference or error. The actor is empty because a worker has none; `requested_by` names the operator. This is ADR 0036's requirement that the provider's result be recorded.

The trail records who asked for what, and what the vendor answered to a purge of everything. For every other purge, the rows record the vendor's answer. Automatic invalidations are not audited, because they would bury the trail.

### 7. The path, end to end

```
public request → ClassifyHttpCaching → http.cache profile
  → Cache-Control / CDN-Cache-Control / ETag / Vary / Cache-Tag → edge
mutation → transaction commit → afterCommit invalidation (settings, languages; media on delete)
  → cdn_purge_requests row(s) → dispatch after commit → worker claims row
  → vendor API → succeeded | deferred (retry) | failed
  → outcome persisted on the row + usage log (+ audit for everything)
  → Admin /cdn history
```

### 6. How a domain module uses this

A future module, for example one publishing articles:

1. Registers a profile for its public endpoints, such as `article` with its own lifetimes.
2. Marks those routes `http.cache:article` and adds `EdgeCacheTag::for('articles', $id)` to `ResponseCacheTags` while rendering.
3. After committing a change, calls `EdgeCacheContract::invalidate(EdgeInvalidation::tags([...]))`.

It never names a vendor, never builds a purge URL from its own routes, and never needs to know whether a CDN is configured.

## Alternatives considered

**Purge synchronously during the write.** Rejected. A vendor's latency and rate limit would become the content editor's, and a vendor outage would become a failed save.

**A shared cache buffer of URLs flushed by a job.** Rejected. Without atomic append and drain it loses entries under concurrency, and its contents vanish with a key expiry. A database row is atomic and visible.

**Vendor-specific headers such as `Cloudflare-CDN-Cache-Control`.** Rejected in Core. `CDN-Cache-Control` is standard and understood by the vendors in view. Only the tag header is vendor-specific, and the driver names it.

**"Cache Everything" edge rules.** Rejected by ADR 0036. The origin declares what is cacheable, per route.

## Consequences

Public configuration is cacheable at the edge, and changes to settings or languages purge it. A public media file that is deleted is purged from the edge.

A domain module gains edge delivery by registration and three calls, with no change to Core or Integration.

An operator can see every purge, its attempts and the vendor's answer. A failed purge stays visible until it is retried.

The worker and the scheduler are now part of edge correctness. A deployment that runs neither leaves purges queued, and the workspace shows them as queued.

**External configuration required:**
* a Cloudflare account and a zone proxying the public host;
* the zone id;
* an API token with Zone → Cache Purge and Zone → Zone → Read;
* `cdn.base_url` for media;
* a running queue worker and scheduler.
