# ADR 0014: Spatie RBAC with Module-Scoped Permissions

* **Status**: Accepted
* **Date**: 2026-09-03
* **Revised**: 2026-09-03 — permission key format fixed at two segments; admin-only scope recorded
* **Revised**: 2026-09-04 — display labels for permissions and roles added after the foundation gap audit

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

## Extension — 2026-09-04: display labels for permissions and roles

This section adds a decision the original record did not make. Nothing above changes: keys stay two-segment, the module column stays, the catalogue stays enumerated in `AdminPermission`, and a role may still carry only catalogued permissions.

**What the audit found.** `permissions` has `name`, `guard_name` and `module`; `roles` has `name` and `guard_name`. Neither has a label, and no translation table exists for either. `AdminRbac::rolesFor()` returns `getRoleNames()` and `permissionsFor()` returns `pluck('name')`, so an administrator sees `super_admin` and `users.update`. `RoleRequest` enforces `regex:/^[a-z][a-z0-9_]*$/` and tells the administrator to type a lowercase identifier, so creating a role means inventing its technical key by hand.

That is a presentation problem, not an identifier problem. The keys are correct and this record keeps them.

### Permissions carry labels in language files

The permission catalogue is code-defined. This record already requires that a permission string is never invented at a call site and that anything outside `AdminPermission` fails at the type level, so the set changes only with a deployment.

Labels therefore live in `lang/{locale}.json` under a key derived from the permission, per ADR 0030:

```
"permission.users.update": "تعديل المستخدم"
"permission.media.delete": "حذف الوسائط"
```

A `permission_translations` table was considered and rejected: it could only mirror a constant that already exists in code, and would need seeding on every catalogue change, with nothing to detect a row that was never added.

**Administrators do not create permissions.** That follows from this record rather than from ADR 0030 — a permission the code never checks authorizes nothing, so a runtime-created permission would be a string the platform enforces nowhere. The administrative interface presents the catalogue as a labelled list to choose from, grouped by the `module` column this record already defines. What an administrator composes is a role.

### Roles carry labels in a translation table

Roles are created at runtime, so their labels cannot ship in a language file. They follow the relational pattern of ADR 0015 and ADR 0030:

```
role_translations
  id         ULID
  role_id    → roles.id
  locale
  label
  UNIQUE (role_id, locale)
```

`roles.name` is unchanged and remains the technical identifier authorization is evaluated against.

### Role creation stops asking for the identifier

The administrator supplies a label per active language. The system derives `roles.name` from the label in the platform default locale — lowercased, non-alphanumerics collapsed to underscores, uniqueness enforced — and the administrator may override it where a generated name would be unclear.

The generated name is **immutable after creation**. A role name appears in seeders, tests and any external integration that assigns roles; renaming the identifier to match a renamed label would silently break those. Editing a label is always allowed and never touches the identifier.

This is a deliberate change to behaviour that exists today, not a description of it. `RoleAdminController::update()` currently writes `$role->update(['name' => ...])`, so an administrator can rename a role and its identifier moves with it. Implementing this decision means the update endpoint stops accepting a new name, the validation rules change accordingly, and any test asserting that a rename succeeds is updated to assert that it is refused.

```
label (en) "Content Editor"  →  name: content_editor
label (ar) "محرر المحتوى"     →  name: content_editor   (unchanged)
```

A label is never an input to authorization: nothing is granted, revoked or evaluated by label. ADR 0031 fixes the payload shape, which pairs `name` with `label` rather than replacing it.

### Implementation status

Decided here; **not implemented**. `role_translations` does not exist, `RoleRequest` still requires a hand-typed identifier, and no permission labels exist. Tracked in ADR 0029.

## Consequences

Standardizes permission conventions and integrates natively with Laravel Gates and Policies. Keys stay short enough to read in a route definition.

Because ownership lives in a column rather than in the name, a permission can be reassigned to a different module without renaming it and breaking every role that holds it.

Holding a permission never makes an account an administrator, and being an administrator never grants a permission. `super_admin` is an ordinary role carrying every permission explicitly, so administrative omnipotence is a row that can be asserted rather than an implicit consequence of an account type.
