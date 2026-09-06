# ADR 0036: HTTP Cache Semantics and the CDN Boundary

* **Status**: Accepted
* **Date**: 2026-09-06

## Context

The platform emits no HTTP caching of any kind. Verified rather than assumed:

```
grep -rniE "etag|last-modified|cache-control|if-none-match|if-modified-since|304" app/ routes/ config/  →  0 hits
```

No `ETag`, no `Cache-Control`, no `Vary`, no conditional-request handling. Every response is uncacheable by default, which is wrong for a public settings payload and exactly right for an authenticated one — and neither outcome was decided.

A CDN half-exists. `CdnUrlResolverContract` and `SettingsCdnUrlResolver` rewrite storage URLs to a CDN base and read `cdn.enabled` and `cdn.base_url` — **settings that are provisioned nowhere.** ADR 0018 says the admin API never creates settings, so those keys cannot come into existence, and the path has been permanently disabled since it was written. That is a defect this record's implementation closes, and a warning: a CDN that fronts an origin emitting no cache headers caches nothing, or caches by its own defaults, which is worse.

This record is written before any of it is built, because HTTP caching is the layer where a mistake is not slow but wrong: a response served to the wrong person, in the wrong language, or long after it changed.

## Decision

**Correctness first. A response is cacheable only where it has been shown to be safe, never by default.**

### The layers are separate and own different things

```
Browser / HTTP cache   →  representations, validated by ETag
CDN / edge cache       →  public representations, invalidated by purge
Application cache      →  values and queries, invalidated by namespace (ADR 0035)
Database               →  the source of truth
```

Each has its own key, lifetime, invalidation mechanism and failure mode. **A CDN is not Redis.** Application-cache rules do not propagate outward: a value invalidated in Redis is still sitting in an edge node until it is purged, and treating the two as one system is how stale content outlives the change that was supposed to remove it.

### Nothing is cacheable until it is classified

There is no global rule for `/api/*`, and no assumption that a `GET` is safe to store.

| Response | Browser | CDN | Basis |
| :--- | :--- | :--- | :--- |
| Immutable assets (digest in the filename) | long | long | content cannot change under the name |
| Public settings and languages | short, revalidated | yes | anonymous, identical for everyone in a locale |
| Public media (delivered variants) | long | yes | addressed by id and variant |
| Localized public payloads | short, revalidated | **only keyed by locale** | see below |
| Any authenticated response | **no-store** | **never** | varies by identity |
| Admin API | **no-store** | **never** | authenticated and sensitive |
| Signed or private media URLs | **no-store** | **never** | the URL is the grant |
| Errors — 401, 403, 429, 5xx | **no-store** | **never** | a cached refusal is a denial of service |
| 404 | short, or not at all | only by explicit policy | a mistaken negative outlives the fix |

**Authenticated means `no-store`, not `private`.** `private` still permits a shared browser profile or an intermediary that ignores it; `no-store` is the instruction that a response must not be written down at all. Nothing behind `auth:sanctum` is ever given a public `Cache-Control`.

### Locale is part of the cache identity, not a `Vary` afterthought

A localized response is identified by its locale in the URL or the cache key. `Vary: Accept-Language` is emitted where content negotiation genuinely occurred, because it is true and because an intermediary needs it — but it is not the mechanism relied on for correctness. `Vary` handling in real caches is uneven, and a platform whose language set is administrator-managed (ADR 0015) cannot rest correctness on a header some caches collapse.

**`Vary: Authorization` is not the fix for an authenticated response.** Reaching for it means the earlier question was skipped: whether that response should be cached at all. The answer above is that it should not.

### Validators identify a representation, exactly

An `ETag` is derived from what the response actually contains — its payload, its locale, and the version of any application-cache namespace that produced it. Two different representations never share a validator, and the same representation always produces the same one.

`304 Not Modified` is returned only when the validator the client presented matches the representation that would otherwise be sent. A 304 for a representation the client does not have is a wrong response that looks like a fast one, and it is the failure mode this section exists to prevent.

`Last-Modified` is emitted only where a meaningful modification timestamp exists. A fabricated one is worse than none.

### Query parameters are part of the identity unless proven otherwise

`?page=2` and `?page=3` are different representations. A parameter is dropped from the cache identity only after it is shown not to affect the response — never for the convenience of a higher hit rate.

### CDN configuration is deployment configuration

Under ADR 0034 there is one deployment per project, so the CDN domain, zone and credentials are site configuration and live in the settings engine, encrypted where they are secrets. No part of it is tenant-scoped.

**No "Cache Everything" rule is ever applied to the application.** The CDN caches what the origin declares cacheable, and the origin declares it per the table above.

### Purge is scoped, and purging everything is a privileged, audited operation

Invalidation names the objects it invalidates. **Purge-all is not an invalidation strategy** — it is an incident tool, and it is treated as one:

* a dedicated permission, distinct from ordinary settings administration;
* an audit record of who purged, what, when, and what the provider returned (ADR 0037);
* explicit confirmation, never a side effect of saving a setting;
* rate limiting, because a purge storm is an origin outage;
* the provider's result recorded rather than assumed — a purge that failed silently leaves stale content behind a green interface.

**A purge failure must never be reported as success**, and a failed purge leaves the system in a known-stale state that an operator can see.

### Failure behaviour

* **CDN unreachable** — the origin serves. Availability does not depend on the edge.
* **Purge fails** — the operation reports failure, records it, and the objects stay stale. It is not retried silently in a way that hides the state.
* **Application cache unavailable** — ADR 0035's fail-open rule applies; responses are still correct, and validators are still derived from the payload actually produced.

**No failure path may turn a `no-store` response into a cacheable one.** Degradation reduces performance, never confidentiality.

## Alternatives considered

**Cache `/api/*` at the edge with a short TTL and rely on `Vary: Authorization`.** The common shortcut, and rejected outright. It makes correctness depend on every intermediary honouring `Vary` on a header that varies per user, and the failure mode is one user receiving another's data. There is no TTL short enough to make that acceptable.

**Defer HTTP caching until a performance problem exists.** Tempting, and it is why the platform has none today. Rejected because the classification above is the expensive part, not the headers, and it is far cheaper to decide before endpoints exist than to audit them afterwards. The headers themselves are then a small implementation.

**Let the CDN decide, using its own defaults.** Rejected. A CDN's default is a guess about a generic origin, and the one thing it cannot know is which of these responses is authenticated.

## Consequences

Public payloads become genuinely cacheable, and conditional requests let an unchanged one cost a validator comparison instead of a rendered response.

Authenticated and administrative endpoints get slower than they might otherwise be, because they are never cached anywhere. That is the intended trade, and it is not revisited for performance.

Every new endpoint acquires a question it must answer — which row of the table it belongs to — and an endpoint that has not answered it is uncacheable, which is the safe default.

The CDN resolver's dead configuration path is closed by provisioning `cdn.*` through the definition registry (ADR 0018 as revised), which is the first time that resolver becomes reachable at all.

The risk this record carries is that a later change alters what a response contains without altering its validator, producing a stale 304. The mitigation is that validators are derived from the payload rather than maintained beside it, and that the correctness tests attempt this deliberately rather than assuming it cannot happen.
