# ADR 0033: Foundation versus Project-Specific Boundary

* **Status**: Accepted
* **Date**: 2026-09-04
* **Revised**: 2026-09-06 — the no-consumer rule refined: configuration and provider abstraction may precede a consumer; business drivers may not

## Context

AlphaMaster is a foundation: a platform that other applications are built on rather than an application in its own right. Eleven phases have produced Auth, Authorization, Core, Integration, Localization, Media, Notification, Settings and User — none of which name a business domain, which is the property that makes them a foundation.

The audit of 2026-09-04 raised the first questions where that property is at risk. SEO, branding, footer content and social links are all things a real project needs; each could be implemented generically or with one project's shape baked in. Without a written boundary the answer gets decided by whichever project is built first, and a foundation that has absorbed a news site's assumptions cannot host a competition platform without being unpicked.

No existing record draws this line. ADR 0002 establishes the modular structure and ADR 0009 the module registry, but both describe *how* modules are organised rather than *what belongs in the platform at all*.

## Decision

**The foundation owns capabilities. A project owns its domain and the meaning of its content.**

The test is a single question: *would a different kind of application need this, in this shape?* If yes, it is foundation. If it only makes sense once you know what the application is about, it is project-specific.

### Foundation

| Capability | Record |
| :--- | :--- |
| Settings engine — typed, grouped, encrypted, cached | ADR 0018 |
| Localization — dynamic languages, resolution, relational translations | ADR 0015 |
| Display labels — identifier/label separation and its stores | ADR 0030 |
| API presentation contract | ADR 0031 |
| Media contracts — storage, delivery, scanning, processing | ADR 0024 |
| Named media variants and watermarking | ADR 0024 |
| SEO contracts, per-locale metadata store, fallback resolution | ADR 0032 |
| Branding assets as settings referencing media | ADR 0018 |
| Mail configuration and transport abstraction | ADR 0018, ADR 0017 |
| Notifications — templates, channels, preferences | ADR 0019 |
| Authentication, MFA, token abilities | ADR 0012, ADR 0013 |
| Administrative authorization | ADR 0014, ADR 0028 |
| Security baseline, rate limiting, audit posture | ADR 0022 |
| Queues and scheduling | ADR 0020, ADR 0025 |
| Quality gates and static analysis | ADR 0021 |

### Project-specific

Articles, posts, pages, categories, tags. Editorial workflow and publication states. Competition rules, scoring, entries, judging. Commerce entities. Any SEO that presumes a page type. Any notification whose meaning is domain knowledge. Any permission outside the administrative catalogue ADR 0014 defines. Any report or dashboard describing domain data.

### How a project extends the foundation

A project adds its own modules under the same structure (ADR 0002) and consumes foundation contracts: it implements `MediaAccessPolicyContract` for its models, attaches SEO metadata through ADR 0032's morph store, registers notification types, and declares translatable entities through `HasTranslations`. It does not modify foundation modules to make room for itself.

Where a foundation capability needs a domain-specific decision, the foundation exposes a contract and the project implements it. The pattern is already in use: Media asks the attaching module who may view a file rather than deciding itself, and refuses access when no policy is registered.

### The rule that keeps the line honest

**A foundation module may not name a business entity.** Not in a class, a column, a permission key, a setting key, a notification type, or a comment describing intent. The moment `article` appears in a foundation module, the foundation has a domain.

The corollary, learned twice already in ADR 0024: **the foundation does not ship a capability that has no consumer** merely because a future project might want it. Six MediaLibrary packages were installed for a dependency the code never called, and an attachment trait shipped ahead of any model that attaches media. A contract settles a shape and is cheap; an implementation with no consumer is speculative surface that nothing tests.

## Extension — 2026-09-06: what may precede a consumer, and what may not

The rule below — *the foundation does not ship a capability that has no consumer* — was written after two real failures and it stays. This section says what it was actually protecting against, because taken literally it also forbids something the platform now needs.

### The rule was about dependencies and dead surface, not about configuration

Both failures ADR 0024 records are the same shape. Six MediaLibrary packages were installed for a dependency the code never called: a dependency with no code path. An attachment trait shipped ahead of any model that attaches media: an abstraction with no shape to hold. Neither had configuration, an interface, or a test that could fail. They were surface nobody could see and nobody would maintain.

That is a different thing from a setting that has no reader yet.

### The refinement

**Configuration and provider abstraction may precede a consumer. Business drivers may not.**

*May precede a consumer:*

* **Configuration** — a setting definition, its type, validation, default, encryption, masking, authorization and admin API. Configuration necessarily comes first: nothing can be configured before somewhere exists to configure it, and an operator cannot supply credentials to a system with no field for them.
* **Provider abstraction** — a capability, its manager, its contract, its failure semantics and its usage logging, under ADR 0017.
* **Vendor drivers** — written against Laravel's HTTP client and tested with `Http::fake()`, as ADR 0017 already requires. A driver has real request construction, real response parsing, real error and timeout handling, and tests that fail when any of it breaks. It is unverified against the live vendor until an operator supplies credentials; it is not untested, and it is not speculative.

*May not precede a consumer, and may not exist in the foundation at all:*

* **A driver that understands a business domain.** A driver that knows what a football fixture, a weather forecast or a news article is names a business entity, which the rule below already forbids in any form. The foundation ships a generic external-API capability; the project ships the driver that gives it meaning.
* **Business identifiers as configuration keys.** A prompt-template *mechanism* is foundation. A setting keyed `ai.prompt.news` is not, for exactly the reason the rule below gives: the moment `news` appears in a foundation module, the foundation has a domain. Templates are created as data, which is also why a project adding a fourth one needs no deploy.

### The line, stated once

```
foundation  →  capability, configuration, abstraction, adapter
project     →  meaning
```

An operator can configure a capability the day it ships. What that capability *is for* arrives with the project.

### Why this is not a licence to build anything

The three tests the rule below already implies still apply, and a capability that fails any of them is still speculative surface:

1. **Can it be configured?** If there is nothing an operator would set, there is no configuration to ship.
2. **Can it fail?** If no test can make it fail, it is not implemented.
3. **Is it free of business meaning?** If it names an entity, it belongs to a project.

## Alternatives considered

**No boundary; extract commonality once a second project exists.** The pragmatic path, and how most platforms actually evolve. Rejected here because the first project is not yet built: the boundary can be stated now at no cost, or discovered later at the cost of unpicking it.

**A stricter boundary — foundation ships contracts only, no implementations.** Rejected as too pure to be useful. Settings, Localization and Notifications are implemented capabilities that every project would otherwise rebuild identically, and rebuilding them is exactly what this platform exists to avoid.

## Consequences

A capability's placement is answerable by a question rather than by argument, and the answer is recorded rather than remembered.

Some foundation capabilities will look over-general to the first project that uses them. That is the intended trade: the second project is what the generality is for, and ADR 0032 is deliberately unimplemented for exactly this reason.

This record governs future ADRs: a new capability states which side of the line it sits on, and a foundation module that needs to name a business entity is a signal that the wrong module is being edited.
