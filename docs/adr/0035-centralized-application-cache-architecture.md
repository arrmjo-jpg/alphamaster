# ADR 0035: Centralized Application Cache Architecture

* **Status**: Accepted
* **Date**: 2026-09-06

## Context

The platform caches in four places and has never decided how. An inventory taken before this record, rather than assumed:

| Owner | Keys | TTL | Invalidation | Varies by locale |
| :--- | :--- | :--- | :--- | :--- |
| `SettingService` | `settings:public`, `settings:group:{group}:public`, `settings:internal:group:{group}`, the public-groups index | constant | explicit `forget`, after commit | **no — and ADR 0018 has already decided it must** |
| `LocaleResolver` | `localization:languages:active`, the default-language key | 24h | explicit `forget` | n/a |
| `AuthService` | `mfa_challenge:{sha256(token)}` | short | `forget` on use | no |
| Spatie permissions | `config('permission.cache.key')` | vendor | `forget` in a migration | no |

Seventeen `Cache::` call sites in three services, plus the vendor key Spatie manages. **There is no sprawl to clean up**, and this record is not a rescue. It exists because Phase 16 multiplies the cached surface — settings become localized, branding resolves media, providers resolve configuration on every dispatch — and four independently invented key conventions become forty if nothing decides the shape first.

Two properties of the existing code are load-bearing and this record keeps them. `SettingService` invalidates inside `DB::afterCommit()`, so a concurrent reader cannot repopulate a key from uncommitted state and pin it for the full TTL. And `LocaleResolver` distinguishes *cached empty* from *cache miss*, because a platform with no active languages is a legitimate state that a naive `Cache::get()` would re-query forever.

The single-tenancy of ADR 0034 removes a dimension that would otherwise dominate the design: **no cache key carries a tenant, because no response varies by one.**

## Decision

**Every cache entry is produced through a central key builder and a declared policy. No module composes a cache key from a string literal.**

### A key is built, never written

A key is composed from named parts, in one place:

```
namespace : resource : discriminators : version
```

* **Namespace** — the owning domain (`settings`, `localization`, `media`, `providers`). One namespace per owner, so invalidation can be scoped to a domain without knowing its keys.
* **Resource** — what is cached (`public`, `group:{group}`, `languages:active`).
* **Discriminators** — *only* what actually changes the value. Today that means locale, and nothing else.
* **Version** — a namespace-level integer, incremented when a cached shape changes.

**A discriminator is added for a correctness reason or not at all.** A key segment that cannot be justified by a value that would otherwise be wrong is a cache miss multiplier and a maintenance cost, and it hides the real reasoning. Locale qualifies today because ADR 0018 makes settings localizable; user identity does not, because no cached entry varies by user; tenancy does not, because ADR 0034 says there is one.

### A policy is declared, not passed at the call site

TTL, staleness, and locking are properties of a cached resource, declared once beside its namespace. A caller asks for a value; it does not pass a TTL. Two call sites that cache the same resource cannot then disagree about how long it lives, which is how a value comes to expire at two different times depending on who asked for it.

### Invalidation is scoped and follows the commit

Invalidation names a namespace, a resource, or a key — never the whole store. **`Cache::flush()` is not an invalidation strategy**, and no administrative operation performs one: on a shared Redis it empties the entire logical database, including entries this platform did not write. Phase 15 demonstrated that concretely, when a test run destroyed the development cache, which is why `tests/bootstrap.php` now redirects a test run to its own logical databases.

Invalidation runs **after** the database transaction commits, extending the rule `SettingService` already follows to every namespace.

Where a change makes an entire namespace stale — a shape change, a bulk import — the namespace version is incremented rather than its keys enumerated. Old entries expire unreferenced.

### A cached miss is a value

A resource whose legitimate answer is *nothing* stores that answer. The alternative re-queries on every request and calls it a cache.

### Failure is declared per namespace, and defaults to fail-open

Redis being unavailable must not turn a readable page into an error. A namespace declares what happens when the store fails:

* **fail-open** — bypass the cache and read the source. The default, and correct for settings, localization and media, where the source of truth is a database that is still there.
* **fail-closed** — refuse the operation. Correct only where the cache *is* the source of truth for a security decision, and there is exactly one such case today: the MFA challenge, where a challenge that cannot be verified must not be treated as satisfied.

**A cache failure is never a security downgrade.** No rate limit, permission or challenge is treated as satisfied because the store that recorded it could not be reached.

### Locking only where a stampede has a cost

A lock is added where regeneration is expensive enough that concurrent regeneration would hurt, not by default. Nothing cached today qualifies. This is recorded so the decision is deliberate rather than absent.

### There is no administrative raw-key interface

An operator manages policies and invalidates scopes. There is no endpoint that reads, writes or deletes an arbitrary cache key: it would be an authorization hole with no audit trail and no meaning to anyone reading it.

## Alternatives considered

**Cache tags.** Rejected for the reason ADR 0018 already gives: tags would let a secret's plaintext share a tagged set with public values, and the segmentation the platform needs is a property of the key layout rather than of tag membership. Namespaces give scoped invalidation without that coupling, and without depending on a store feature not every driver supports.

**Leave it as it is.** Defensible today at seventeen call sites, and the reason this record is short. Rejected because Phase 16 adds cached surface across settings, media and providers simultaneously, and four conventions becoming forty is not a problem that announces itself — it is found later, as a wrong response.

**A general-purpose cache framework.** Rejected. Warming strategies, tiered stores and cache-aside abstractions solve problems this platform does not have. What is decided here is a key builder, a policy declaration, scoped invalidation and a failure rule — which is what the inventory above justifies and no more.

## Consequences

Cache keys become greppable by namespace, and invalidation can be reasoned about without reading every call site.

The cost is indirection: a developer adding a cached value declares a namespace and a policy instead of calling `Cache::remember` with a literal. That is the intended friction, and it is where the question *what actually varies this value* gets asked.

**Cache correctness outranks hit rate.** A design that serves the wrong locale quickly is worse than one that serves the right locale slowly, and where the two conflict this record resolves in favour of correctness.

Existing entries are not migrated. The four current owners move to the builder as they are touched, and the namespace version exists to make a shape change safe when they are.

Nothing here governs HTTP responses. Application cache and HTTP representation are different layers with different keys, different lifetimes and different failure modes; ADR 0036 covers that one.
