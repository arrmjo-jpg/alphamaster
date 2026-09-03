# ADR 0014: Spatie RBAC with Module-Scoped Permissions

* **Status**: Accepted
* **Date**: 2026-09-03
* **Revised**: 2026-09-03 — permission key format fixed at two segments; admin-only scope recorded

## Context

Administrative authorization needs roles and permissions that modules can contribute to independently, without a central list every module has to be edited into.

The original record specified permission names of the form `{module}.{resource}.{action}` alongside a `module` column. Implementing it showed the module segment to be dead weight: it repeated in the name what the column already stated, produced keys like `user.users.view` that read poorly at every call site, and gave two places for the owning module to be recorded — which is two places for them to disagree.

## Decision

Use `spatie/laravel-permission` v6. Permissions are keyed by **two segments** and carry their owning module in a dedicated column:

```
key    = users.view          resource and action
module = user                the module that owns and seeds the permission
```

The key expresses resource and action; the column expresses ownership. Neither repeats the other, so a permission has exactly one name and exactly one owner. Three-segment names are not used, and the module segment is not reintroduced into the key.

Keys are enumerated in `AdminPermission`, so a permission string is never invented at a call site and an unknown one fails at the type level rather than silently authorizing nothing. A role may be given only catalogued permissions, so it cannot carry a string the platform enforces nowhere.

Module values name the owning module as the codebase names it — `user`, `settings`, `authorization` — which is what lets a module seed and query its own permissions without reading a global list. That the `user` module owns the `users.*` keys is the usual case rather than a rule: the `authorization` module owns both `roles.*` and `permissions.*`.

**Scope.** This is administrative authorization infrastructure and nothing else. Only accounts whose `account_type` is `admin` participate (ADR 0028). Spatie's `HasRoles` sits on the authenticatable model because the package requires it, which puts `assignRole()` and `hasPermissionTo()` on every account; the `AdminRbac` service is what makes them unreachable, by passing the account-type check before any grant, revocation or evaluation. An architecture test confines `Spatie\Permission` to the Authorization module so no call site can route around that service.

Spatie's morph key is a ULID, matching `users.id` (ADR 0004).

Permission checks are cached through the default cache store, which is Redis.

## Consequences

Standardizes permission conventions and integrates natively with Laravel Gates and Policies. Keys stay short enough to read in a route definition.

Because ownership lives in a column rather than in the name, a permission can be reassigned to a different module without renaming it and breaking every role that holds it.

Holding a permission never makes an account an administrator, and being an administrator never grants a permission. `super_admin` is an ordinary role carrying every permission explicitly, so administrative omnipotence is a row that can be asserted rather than an implicit consequence of an account type.
