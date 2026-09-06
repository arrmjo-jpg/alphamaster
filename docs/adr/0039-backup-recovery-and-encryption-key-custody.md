# ADR 0039: Backup, Recovery and Encryption-Key Custody

* **Status**: Accepted
* **Date**: 2026-09-06

## Context

The platform has no backup or recovery architecture. Not a partial one — nothing: no tooling, no documented procedure, and no record of what recovery would even require. ADR 0037 noticed the gap while deciding audit retention and deferred its own retention window to whatever this record would say.

That was survivable while configuration was seventeen seeded rows an operator could retype. Phase 16A ended that. Configuration is now 59 declared settings across 8 groups, with per-locale values in a second table, a definition registry, an audit trail, and credentials in three separate encrypted stores. Losing it is no longer an inconvenience; and restoring it is no longer obvious.

**Three stores encrypt with `APP_KEY`, not one.** This is the fact that shapes everything below, and it is easy to miss because only the first is what anyone thinks of as "settings":

| Store | What it holds |
| :--- | :--- |
| `settings.value` where `is_secret` | SMTP password, internal API key, future provider credentials |
| `integration_providers.credentials` | vendor credentials behind ADR 0017 |
| `mfa_methods.secret` / `.destination` | every administrator's second factor |

`config/app.php` already reads `APP_PREVIOUS_KEYS`, so Laravel can *decrypt* with a retired key. Nothing re-encrypts, so a key can never actually be retired — the old one is required forever.

## Decision

**Infrastructure owns the database backup. AlphaMaster owns a configuration export and restore contract. Neither can recover an encrypted value without the key that encrypted it, and this record says so rather than implying otherwise.**

### The split, and why

Backing up a PostgreSQL database is a solved problem that `pg_dump`, WAL archiving and every managed platform do better than an application can. Reimplementing it would mean owning consistency, retention, encryption at rest and off-site storage — four things with mature answers — in order to have written them ourselves.

So:

* **Infrastructure** takes the database backup, on its own schedule, to its own destination, with its own retention. That includes every table this record names, because a database backup is not selective.
* **AlphaMaster** provides an export and restore contract for *configuration specifically*: a portable, inspectable artefact for moving configuration between environments, seeding a new deployment, and answering "what was this set to" without restoring a whole database.

The two are not alternatives. A database backup is how you survive losing the database; a configuration export is how you move or inspect configuration deliberately.

### What the configuration export contains

* `settings` rows — group, key, type, flags, value
* `setting_translations` — per-locale values
* the registry definition set and its shape version, so a restore can detect that it is reading an export written by a different release
* `integration_providers` configuration metadata — capability, driver, label, settings, active and default flags, priority
* the export's own metadata: platform version, export time, actor, and the key fingerprint described below

### What it does not contain

* **Audit records.** They are evidence with their own retention and their own permission (ADR 0037, as amended). Putting them in a configuration export makes an ordinary configuration transfer a way to carry the security trail somewhere it does not belong.
* **`mfa_methods` in any form.** A second factor belongs to a person and to one deployment. Exporting them would let a restore into a second environment reproduce every administrator's authenticator, which is a credential-theft primitive wearing a maintenance task's clothes.
* **Users, roles or permission assignments.** Configuration is not identity.
* **Decrypted secret values, ever.** See below.

### Secrets in an export: ciphertext or nothing

An export carries a secret as the **ciphertext already stored**, never decrypted in flight, or it omits the secret and records that it did.

Which of the two is a choice the operator makes when exporting, because the right answer depends on where the export is going:

* **Same deployment, same key** — ciphertext is portable and the restore is complete.
* **A different environment** — ciphertext is undecryptable there, so exporting it achieves nothing and increases what a leaked file is worth. Omitting is better, and the export instead lists **which secrets were omitted, by key**, so an operator has a checklist of what to re-supply rather than discovering it when an integration fails.

**An export never decrypts.** A file containing plaintext credentials is a worse artefact than no backup at all, because it will be copied to a laptop, attached to a ticket, and kept.

### Key fingerprint, so a restore can refuse

Every export records a fingerprint of the encryption key in use: a truncated one-way digest of the key, sufficient to compare and insufficient to attack.

On restore the fingerprint is compared:

* **Match** — encrypted values are restorable.
* **Mismatch** — the restore **refuses to write any encrypted value**, restores the non-secret configuration, and reports which secrets it declined and why.

Without this, a restore into an environment with a different key writes ciphertext that decrypts to nothing, and the failure surfaces later as an integration that stopped working for reasons nobody can trace. A refusal at restore time is the same information delivered while it is still cheap.

### The dependency, stated plainly

```
encrypted configuration backup  →  APP_KEY recovery  →  successful restore
```

**If `APP_KEY` is lost, every encrypted value in all three stores is unrecoverable.** Not degraded, not partially readable — gone. No amount of database backup changes this, because the backup faithfully preserves ciphertext nobody can open.

The practical consequences, which belong in a runbook and are recorded here so the runbook has something to be written from:

* `APP_KEY` must be backed up **separately from the database**, by a different mechanism, with different access. A key stored beside the data it protects is not custody.
* A restore into a new environment without the original key means **re-supplying every credential**: every secret setting, every provider's credentials, and every administrator re-enrolling MFA.
* MFA re-enrolment is the sharpest of the three, because it locks out exactly the people who would perform the recovery. A deployment needs a documented break-glass path before it needs a restore, not during one.

### Key rotation is a phase, not a flag

`APP_PREVIOUS_KEYS` lets Laravel decrypt with a retired key, which is half of a rotation. The other half — reading every encrypted value with the old key and writing it back with the new one, across all three stores, restartably and without ever holding plaintext anywhere but memory — does not exist.

This record does not build it. It records what it would have to do, so the design is not foreclosed:

* iterate all three stores, not just `settings`;
* be resumable, because it will be interrupted;
* never write plaintext to disk, a log, or an audit record;
* update the key fingerprint when it completes;
* be an explicit operator action with its own permission and its own audit record.

Until that exists, a key can be added to `APP_PREVIOUS_KEYS` but never actually retired.

### Restore is ordered, validated, and refuses rather than half-applies

Restore order follows the dependencies: languages, then setting definitions synchronised from the registry of the *running* code, then setting values, then translations, then provider metadata. Definitions come from the running code rather than the export, because the code is what will read them — an export from an older release describes settings this deployment may no longer have.

A restore validates before it writes: the export's shape version, the key fingerprint, and every value against its current declared type. A value that no longer validates is **reported and skipped**, never coerced. A partial restore is a state an operator can see and finish; a coerced one is a corruption nobody notices.

Restore is transactional per store and audited as one operation with its outcome, per ADR 0037.

### Permissions

Exporting configuration reads every non-secret value and lists which secrets exist. Restoring rewrites configuration wholesale. Neither is `settings.update`.

Both require **`settings.backup.manage`**, a permission distinct from ordinary settings administration, and both are audited.

### RPO and RTO are deployment decisions, not platform ones

The platform cannot state a recovery point or recovery time objective, because both are properties of the infrastructure's backup schedule and of how quickly an operator can obtain the key — neither of which the application knows or controls.

What this record fixes is the *floor*: the minimum a deployment needs to state its own objectives honestly.

* **RPO** is bounded by the infrastructure backup interval, and by nothing the application does.
* **RTO** is bounded by database restore time **plus key retrieval time plus credential re-supply time**, and the last two are usually larger than the first. A deployment that measures only its database restore has measured the smallest part.

## Alternatives considered

**Application-managed scheduled backups to object storage.** Rejected. It duplicates infrastructure tooling, requires the platform to hold storage credentials whose loss is its own incident, and makes the application responsible for a schedule it cannot observe. The one thing it would add over `pg_dump` is selectivity, which the export contract provides without owning the backup.

**Export plaintext secrets so a restore is complete.** Rejected outright. It converts a maintenance artefact into the highest-value file in the organisation, and the failure mode is not exotic — it is somebody attaching it to a support ticket.

**Store an escrow copy of `APP_KEY` in the database.** Rejected. The key exists to protect that database; putting it inside means a single compromise yields both. Custody is an operational problem and stays one.

**Derive history from database backups instead of building a revision store.** Rejected, and it belongs here because it looks superficially attractive. Restoring a backup to read one previous value is not a feature, it is an outage; ADR 0040 builds the revision store for that reason.

## Consequences

An operator can move configuration between environments deliberately and can see what is configured without restoring a database.

The cost is honesty about a limit that was previously unstated: this platform cannot recover encrypted values without the key, and no amount of backup engineering changes that. Saying so is the point — a recovery procedure that assumes otherwise fails at the moment it is needed.

The largest remaining risk is the one this record cannot close: `APP_KEY` custody is operational, and a deployment that keeps the key alongside its database backup has a single point of failure that looks like two. The MFA store makes it worse, because losing the key locks out the administrators who would perform the recovery.

Key rotation stays unbuilt and now has a written specification, so the next phase that needs it starts from a design rather than from a discovery.
