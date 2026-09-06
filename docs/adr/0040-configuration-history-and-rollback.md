# ADR 0040: Configuration History and Rollback

* **Status**: Accepted
* **Date**: 2026-09-06

## Context

ADR 0038 guaranteed that reverting a configuration would stay possible, and named the substrate: *"a versioned write plus an audit record of the change is the substrate a later phase would need"*.

Reviewing the implementation before building on it found that this is not true, and the reason is that ADR 0037 was also right.

An audit record for a non-secret setting carries the change, not the value:

```php
['type' => $setting->type->value, 'localized' => $setting->is_localized]
```

That is exactly what an audit trail should hold — it answers who changed what and when, and it holds no value to leak. But **you cannot restore a configuration from records that deliberately do not contain one**. The two records are individually correct and jointly insufficient, and the gap was invisible until something tried to use it.

So history is its own store. This record builds it, and ADR 0038 is amended to stop claiming otherwise.

## Decision

**A revision store records what a setting was, so a rollback has something to restore. It is separate from the audit trail, it holds no secret in any form, and rolling back is a new change rather than an undoing of an old one.**

### The revision store is not the audit trail

Two stores, two purposes, and conflating them is what produced the gap this record fixes:

| | Audit trail (ADR 0037) | Revision store (here) |
| :--- | :--- | :--- |
| Answers | who did what, when | what was it before |
| Holds values | **never** | non-secret values only |
| Lifecycle | append-only, archival retention | pruned with its setting |
| Permission | `audit.view` / `audit.manage` | `settings.view` / `settings.rollback` |

A revision is written when a non-secret setting changes, recording the value **as it was before the change**, the version it belonged to, the locale where the setting is localized, and who changed it. The audit trail records that the change happened. Neither is derived from the other.

### A secret writes no revision at all

Not a redacted revision, not a null-valued row, not a placeholder. **Nothing.**

A row that exists but is empty is an invitation: the next person to read the schema sees a column that could hold the value and a gap where it does not, and closing that gap looks like an improvement. The only durable way to keep secret material out of history is for history to have no row to put it in.

This is the same reasoning ADR 0037 uses for the audit trail, applied to a store that *does* hold values — which makes the rule more important here, not less. No plaintext, no ciphertext, no hash, no length, no fingerprint, no derived material of any kind.

### The consequence, stated rather than discovered

**A rollback restores configuration and can never restore a credential.**

An operator rolling back a group that contains a secret gets every non-secret value restored and an explicit statement of which secrets were not, named by key so they can be re-supplied. The rollback does not fail because of this, and it does not silently succeed either: the response says what it did and what it could not do.

This is a deliberate limit, and it is preferable to the alternative. A history table that could restore credentials is a history table full of recoverable credentials, with a longer retention and a broader read permission than the settings table it copied them from.

### Rollback is a new change, never a rewrite

Rolling a group back to an earlier version writes a **new** version with the old values. It does not delete revisions, does not decrement the counter, and does not restore the group to a state where the intervening changes never happened.

History that can be rewritten is not history. And an operator needs to be able to see that a rollback occurred — including rolling a rollback back — which is only possible if the rollback is itself a recorded change.

### What a rollback must do, in order

1. **Require a precondition.** `If-Match` against the group's current version, exactly as an ordinary write does (ADR 0038). A rollback built on a stale read is refused with `412` — it is the operation most likely to be attempted from a page somebody left open.
2. **Refuse a stale target.** The revision being rolled to must belong to the group being rolled back. A rollback naming a revision from elsewhere is a programming error, not a request to be interpreted.
3. **Revalidate against the current declarations.** A value that was valid when written may not be valid now: its type may have changed, its validation rules may have tightened, a media id it points at may have been deleted. Every restored value is validated against the definition **as it exists today**, and one that fails is reported and skipped rather than written. Restoring a value that the current platform would reject is how a rollback produces a configuration nothing can read.
4. **Revalidate dependencies.** A group's `dependsOn` declarations are checked after the restored values are applied, so a rollback cannot leave a capability switched on with a prerequisite that is no longer satisfied.
5. **Apply transactionally.** All of it or none, in one transaction, per ADR 0038.
6. **Audit the operation**, recording the group, the target version, the resulting version, and which settings were skipped and why — carrying no values, per ADR 0037.
7. **Invalidate after commit**, through the scoped invalidation of ADR 0035, never inside the transaction.

### Permissions

Rollback is not `settings.update`. It changes many values at once, from a state the operator may not have inspected, and it is the operation most likely to be run under pressure.

It requires **`settings.rollback`**, distinct from ordinary settings administration. Reading history requires `settings.view` — seeing what a value used to be is no more privileged than seeing what it is now, and making it harder would only push operators toward reading the database directly.

Where a group contains a setting whose declaration names a permission of its own (ADR 0018's `requiredPermission`), rolling that setting back requires that permission too. A rollback must not become a way to change a guarded value without holding the permission that guards it — which is the most obvious privilege-escalation route this feature could open, and it is closed by checking the same declarations an ordinary write checks.

### Retention of revisions

A revision belongs to its setting. When a setting is deleted, its revisions go with it, by foreign key.

Revisions are otherwise kept. They are small, they hold no secret, and the question they answer — what was this before — is asked most often about changes made long enough ago that nobody remembers. A deployment that needs to bound the table can prune by age against the database; unlike the audit trail, nothing here is evidence, so pruning it needs no ceremony.

## Alternatives considered

**Widen the audit context to carry values, and use it for both.** Rejected, and it is the tempting one because it needs no new table. It would put values into a store whose entire design premise is that it holds none, and the first secret setting to pass through it would either need a special case in the audit path or would leak. ADR 0037's redaction is structural precisely so there is no code path that could carry a value; adding one to enable rollback would remove the property that makes the trail safe to keep and to read.

**Snapshot the whole group on every write.** Simpler to restore from, and rejected as wasteful in a way that compounds: a group of twenty settings writes twenty values every time one changes, and the store grows with the number of settings rather than the number of changes. Per-setting revisions restore just as well and are queryable per key, which is the question actually asked.

**Store revisions as a JSON diff.** Rejected for the reason ADR 0018 gives about settings themselves: a JSON blob cannot be validated, indexed or queried per key, and history is read far more often than it is written.

**Let rollback restore secrets from an encrypted revision.** Rejected. It would mean a second encrypted copy of every credential the platform has ever held, under a permission designed for reading configuration. The inability to restore a credential is a feature of this design, not a shortfall in it.

## Consequences

An operator can see what a setting was and put it back, which is the ordinary need this platform could not previously serve at all — the alternative was restoring a database backup to read one value.

Rollback is deliberately unable to restore credentials, and says so per key rather than failing opaquely. Operators will meet this limit, and meeting it with a checklist is the intended outcome.

Every non-secret write now writes a second row. That is a real cost at settings-write frequency and would not be at request frequency, which is why it is acceptable here and would not be elsewhere.

The revision store is the substrate ADR 0038 believed the audit trail already was. That record is amended rather than left standing, because a decision that names a mechanism which cannot work is worse than one that names none.
