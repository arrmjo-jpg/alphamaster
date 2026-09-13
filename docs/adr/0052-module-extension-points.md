# ADR 0052: Module Extension Points

* **Status**: Accepted
* **Date**: 2026-09-14
* **Extends**: ADR 0002 (modular architecture), ADR 0014 (module-scoped permissions), ADR 0018 (settings registry), ADR 0033 (foundation boundary)
* **Amends**: ADR 0035 ("a namespace is declared here")

## Context

AlphaMaster is a foundation. Domains that do not exist yet (news, competitions, sports, reels, media-heavy applications) are meant to arrive as modules of their own. They should reuse authentication, accounts, permissions, settings, localization, cache, CDN, media, notifications, audit, rate limiting, jobs and the Admin, without anyone rebuilding or editing those.

A review of the module boundaries against that goal found that some extension already works by registration. Each of these lets a module contribute from its own service provider, with no central list to edit:

* `ConfigurationPortability` (ADR 0039).
* `TranslationRegistry` (ADR 0043).
* `SettingRegistry` (ADR 0018). Its singleton accepts `registerCatalogue()` from any provider.

Three other extension points did not work that way. Each would have forced a new domain module to edit a foundation module:

1. **Cache namespaces** were the cases of one Core enum, `CacheNamespace`. A module owning cached data had to add a case to Core, and ADR 0035 said so ("a namespace is declared here").
2. **Administrative permissions** were the cases of one enum, `AdminPermission`. The seeder, role validation and super_admin's grant all read that enum, so a module's permissions could not exist without editing Authorization.
3. **The cache Admin** hardcoded which namespaces were protected.

## Decision

**A foundation module exposes a registry. A domain module registers with it from its own service provider. No foundation module lists, imports or knows the domain modules that use it.**

### 1. Cache namespaces are declared by their owners

* `CacheNamespaceDefinition` (Core) is the interface: `namespace()`, `policy()`, `flushable()`.
* `CacheNamespace` implements it and remains the set of the platform's own namespaces. Every existing call site is unchanged.
* `CacheNamespaceRegistry` (Core) holds every declared namespace. Core registers its own cases, and a module registers its enum against the singleton.
* `PlatformCacheContract` accepts any `CacheNamespaceDefinition`. It **refuses a namespace nobody registered** before any fail-open handling runs, so an undeclared namespace is a loud programming error, never a silent cache bypass.
* Two different declarations under one identifier are refused.
* The Admin's cache screen lists the registry, and a namespace declares for itself whether an operator may invalidate it.

ADR 0035's rule survives intact: no call site passes a namespace as a string. What changes is only *where* a declaration lives. It moves from one enum in Core to the owning module.

### 2. Permissions are declared by their owners

* `PermissionDefinition` (Authorization) is the interface: `key()`, `module()`.
* `AdminPermission` implements it and remains the platform's own permissions.
* `PermissionCatalogue` (Authorization) holds every declared permission. A module registers a backed enum implementing the interface.
* The seeder provisions the whole catalogue. **super_admin is granted all of it**, which keeps ADR 0014's rule that omnipotence is explicit. The other seeded roles keep the platform's baseline, and a module's permissions reach them only when an operator grants them.
* Role validation accepts exactly the catalogue.
* Keys keep the `{resource}.{action}` grammar and are refused otherwise. A duplicate key is refused.

### 3. Settings catalogues are registered, as they already could be

No code change. A module that owns settings registers its catalogue against the `SettingRegistry` singleton from its own provider, and `settings:sync` materialises its definitions. The Settings module's own list is its own catalogues only. This record makes that the documented extension point, and a test proves it.

### 4. What a new module owns

A domain module owns, inside `app/Modules/<Name>`:
* routes, controllers, requests, resources, models, policies, services, events, jobs and migrations;
* its settings catalogue, cache namespaces and permission enum, each registered as above;
* its audit action names. The recorder takes a string, and the label lives in the module's translations;
* its tests.

In the Admin it owns a screen directory and one manifest entry in the module registry (ADR 0009).

It may depend on Core and on the foundation modules' contracts. No foundation module may depend on it, and the architecture test enforces that direction for every existing module.

### Couplings that remain, deliberately

* **Vendor capabilities** (`IntegrationCapability`) stay a closed enum with a database constraint. A capability is a class of external service the *platform* consumes (SMS, CDN, push). Adding one is a foundation change with a migration, not something a domain module does. A domain module consumes capabilities through their contracts.
* **The Admin module registry** is one static manifest file, by ADR 0009's choice of a static registry over runtime plugin discovery. Adding a module adds one entry.
* **`ArchitectureRouteFileTest`** pins which modules have route files, so a new module updates that test. That is a check, not a coupling.

## Alternatives considered

**Discover modules by scanning directories.** Rejected for the reason ADR 0033 gives. A registration someone wrote is an extension point; a directory scan is a plugin system, with the ordering, trust and failure questions that come with one.

**Permissions and namespaces as free strings.** Rejected. It would bring back the typo failure both enums were introduced to prevent, and it would make an unregistered permission indistinguishable from a misspelt one.

## Consequences

A module can own cached data, permissions and settings without a change to Core, Authorization or Settings. Those foundation modules can now be reviewed and versioned without re-reading every domain module's needs.

The cost is that a namespace or permission enum that is never registered fails at first use rather than at compile time. The platform cache and the seeder make that failure immediate and explicit, and the tests for this record exercise it.
