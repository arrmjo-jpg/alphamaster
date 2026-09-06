# ADR 0034: Single-Tenant Deployment Model

* **Status**: Accepted
* **Date**: 2026-09-06

## Context

The Phase 16 architecture review was handed a brief built throughout on workspaces: workspace-scoped settings, global defaults with workspace overrides, workspace identity inside every cache key, workspace-scoped CDN purge, and a split between global and workspace administration. Reviewing it against the codebase found none of it, and found nothing partially built toward it either:

```
grep -rli "workspace|tenant" backend/{app,database,routes,config}  →  0 files
```

Thirty-three records, nine modules, and no tenancy anywhere. `settings` has one row per `(group, key)` with no scope column. Media, Notifications and Localization each resolve against one platform. ADR 0028 defines a single administrative perimeter by `account_type`, not by membership of anything.

The confusion has a specific source. **ADR 0006 is titled "React Admin as a Workspace Application Platform", and its "workspace" is a user-interface metaphor** — the contextual, Linear-and-Notion-style shell that replaces Sidebar → Table → Modal → Form. It describes how an administrator's screen is arranged. It has never meant a tenant, a customer, or a data boundary, and nothing in that record implies one.

So the platform is coherently single-tenant, and had never said so. That is the problem this record exists to fix: an unrecorded assumption is one every reader is free to resolve differently, and the Phase 16 brief is what that looks like in practice — a phase scoped against a boundary that does not exist. ADR 0033 already draws the line between foundation and project; this draws the one nobody had written down.

## Decision

**AlphaMaster is single-tenant. One deployment serves one project.**

A project that needs its own configuration, media, users and content gets its own deployment: its own database, its own Redis, its own container stack. The foundation is reused across projects by being deployed again, not by partitioning one running instance between them. This is what the repository layout, the Docker Compose stack and the connection configuration have always assumed.

The consequences below are not deferrals. They are the design.

**No tenant scope in Settings.** A setting is identified by `(group, key)` and holds one value — or one value per locale, where ADR 0018 classifies it as localized content. There is no scope column, no `GLOBAL_ONLY` / `WORKSPACE_ONLY` classification, and no second dimension on the primary key.

**No workspace overrides.** There is no override table, no inheritance chain, and no `source` or `inherited` member on any settings payload. The effective-value chain has three steps and no branch:

```
Global Stored Value  →  Validated / Typed Value  →  Effective Runtime Value
```

A client asking for a setting is told what the value is. It is never told where the value came from, because there is only one place it can come from.

**No workspace cache identity.** A cache key is composed of what actually varies the entry: the resource, and the locale where the payload can differ by language (ADR 0018). No key carries a tenant segment, because no response varies by one. Cache correctness on this platform is therefore a question about locale and authentication, not about tenancy.

**No global-versus-workspace permissions.** `settings.view` and `settings.update` are the complete pair, and they mean what they say. There is no `settings.global.update`, no `settings.workspace.update`, and no rule about which administrators may cross between them, because there is no boundary to cross.

**This is a decision about the platform, not only about Settings.** Media, Notifications, Localization, Authorization and rate limiting are all single-scope for the same reason, and none of them should acquire a tenant dimension without this record being superseded first.

### What reversal would cost

Recorded so that a future decision to become multi-tenant SaaS is taken with its price visible, rather than discovered halfway through.

It would be a phase of its own, and it would have to land **before** any tenant-scoped feature, not alongside one:

* **A tenant model and its resolution.** The table, its identifier, and how a request is attributed to a tenant — subdomain, header, path, or token claim. Every one of those choices has different consequences for caching and for CSRF, and the choice cannot be deferred to the modules.
* **A tenant boundary that is enforced, not filtered.** A `where tenant_id = ?` that a developer can forget is not a boundary. It needs to be structural — global scopes, a resolved context that queries cannot bypass, and tests that try to cross it deliberately.
* **Settings.** A scope column, an override table, a resolution chain, and a migration attributing every existing row to a tenant. The effective-value payload gains `source` and `inherited`, which is an API change for every consumer.
* **Cache.** Tenant identity in every key that can vary by tenant, and an audit of every existing key to decide which those are. A key that misses the segment serves one tenant's data to another, and it fails silently.
* **Media.** `MediaAccessPolicyContract` becomes tenant-aware, storage paths partition, and signed URLs must not be transferable between tenants.
* **Authorization.** Roles and permissions become tenant-scoped, with a separate notion of platform-level administration above them. ADR 0014's catalogue and ADR 0028's `account_type` both need revisiting.
* **Rate limiting.** ADR 0022's limiter keys gain a tenant dimension, or one tenant exhausts another's budget.
* **Audit and observability.** Every record gains the tenant it happened in, or an operator cannot answer who did what.

The reason this is expensive is not the schema. It is that a tenant boundary is a security boundary, and retrofitting a security boundary means proving the absence of leaks across code that was written without one.

## Alternatives considered

**Build tenancy now, speculatively.** Rejected on the rule this project has already learned twice, recorded in ADR 0024 and made general in ADR 0033: the foundation does not ship a capability that has no consumer. There is no second tenant, no requirement describing how tenants would differ, and nothing to test a boundary against. A tenancy model built against no requirement would be wrong in ways nobody could detect, while making every module more complicated in ways everybody would pay for.

**Leave it unrecorded and decide when it comes up.** This is the status quo, and the Phase 16 brief is the evidence against it. An unrecorded assumption gets re-derived by every reader, and the cost lands as a scoping conversation at the start of a phase rather than as a paragraph in a record.

**Keep workspace scaffolding in Settings "for later" — a scope column defaulted to global.** Rejected. It carries the complexity of tenancy with none of its benefit, and it would be scaffolding nothing is holding up: a column no query filters on, an override table with no rows, and a `source` field that always says the same word. Worse, it would read as a boundary to anyone who found it later, which is precisely the confusion this record is closing. If tenancy arrives, it arrives as the phase described above, and a migration adding the column then is a smaller cost than four years of maintaining a lie.

## Consequences

The Settings architecture of Phase 16A is materially simpler: three steps in the resolution chain rather than five, one identity for a cache entry rather than two, and one pair of permissions rather than four.

Phase 16's original brief loses roughly half its surface — workspace scope, overrides, workspace cache identity, workspace-scoped purge and the global/workspace permission split are all removed from the roadmap rather than deferred to a later phase. What remains is smaller and buildable.

A future reader who finds "workspace" in ADR 0006 has this record to tell them it means a screen layout. That disambiguation is most of the value here.

The risk is the ordinary one for a decision like this: that the platform is later sold as SaaS and this record is treated as an obstacle rather than as a starting point. It is not an obstacle. It is a statement of what is true today plus a costed description of what changing it requires, which is more than the platform had before.
