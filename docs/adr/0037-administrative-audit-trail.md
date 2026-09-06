# ADR 0037: Administrative Audit Trail

* **Status**: Accepted
* **Date**: 2026-09-06
* **Revised**: 2026-09-06 — retention settled: archival, as the single permitted removal path

## Context

There is no audit trail. A search for one finds only the word appearing in unrelated comments; no table, no service, no recorded administrative action anywhere in the platform.

That has been survivable while the administrative surface was small and its operations were reversible. Phase 16 ends both conditions. It adds operations that change how the platform behaves for everyone, that reach outside it, and that cannot be undone by editing a value back:

* a secret rotated, cleared or replaced — the previous value is unrecoverable by design;
* a provider enabled, disabled or made default — delivery moves to a different vendor;
* a CDN purge, and particularly a purge of everything — an origin-load event with no rollback;
* security, authentication and rate-limit settings changed — the platform's own defences;
* a definition orphaned by a registry synchronisation (ADR 0018), which is a change nobody performed deliberately.

The Phase 15 review found a privilege gap in Settings that had been open since Phase 4: every administrator could write every setting, including `security` and `rate_limit`, because the permissions existed and were never checked. It was found by reading routes. **With an audit trail it would also have been visible in what the platform recorded** — and, more to the point, an operator would have been able to answer what had actually been changed while it was open. Nobody can answer that question today.

This record decides what is written down, and what must never be.

## Decision

**Every administrative operation that changes platform behaviour or reaches an external system is recorded, immutably, before it is considered done.**

### What is recorded

* **Who** — the acting user, by identifier, resolved at the time of the action rather than by later lookup.
* **What** — the operation, as a stable identifier (`setting.updated`, `secret.rotated`, `provider.enabled`, `cdn.purge.all`), never a sentence assembled for display.
* **Which** — the subject: the setting key, provider id, or namespace acted on.
* **When** — `timestamptz`, per ADR 0029 item 7.
* **Outcome** — success or failure, and for an external operation what the vendor returned. A CDN purge that failed is recorded as a failure; a green interface over a failed purge is exactly the state that leaves stale content behind.
* **Change context** — enough to answer *what changed*, subject to the redaction rules below, and the version the change was applied against (ADR 0038).
* **Request correlation** — the identifier ADR 0023's `Context` already attaches, so an audit row and its log lines can be joined.

### What is never recorded

**No secret value, in any form, at any point.**

That means no plaintext, no ciphertext, no partial value, no hash, no length, and no "old" value in a change record. A ciphertext in an audit table is a second copy of the secret with a longer retention period and weaker access control than the one in `settings`, and a hash of a short credential is a value that can be recovered.

What is recorded is that the event happened:

```
secret.set        provider=twilio field=auth_token
secret.rotated    key=mail.password
secret.cleared    key=cdn.api_token
```

The rule extends past the obvious cases: a service-account document, a private key, an OAuth client secret and a signed URL are all secrets. Where an operation changes a mixture of secret and non-secret fields, the non-secret changes are recorded and the secret ones are named without values.

### The trail is append-only

An audit record is never updated and never deleted through the application. There is no administrative endpoint that edits or removes one — a trail whose subjects can rewrite it is not a trail, and the accounts with the most reason to alter it are exactly the ones with administrative access.

Retention is an operational decision made against the database, not an application feature, and it is bounded by how far back an operator may need to answer a question rather than by table size. Backup and recovery semantics for configuration are not yet recorded anywhere, and the retention window should be revisited when they are.

### Recording is part of the operation, not a side effect of success

An operation that must be audited is not complete until its record is written. For a database change the record is written in the same transaction, so a configuration change and its audit row commit or fail together. For an external operation — a purge, a provider test — the attempt is recorded with its outcome, including when the outcome is a failure or a timeout.

**An audit failure is not swallowed.** A system that cannot record what it is doing has lost the property this record exists to provide, and it says so rather than proceeding quietly.

### Reading the trail is itself privileged

An audit record names who did what and when, and taken together the trail describes the platform's security configuration and the habits of its administrators. Reading it requires its own permission, distinct from the permission to perform the operations it records — an administrator who may change settings does not automatically inherit the ability to review everyone's changes.

### What this is not

It is not versioning, and not a rollback mechanism. It records that a change happened and enough context to understand it; reconstructing a previous configuration is ADR 0038's concern. It is not application logging: logs are diagnostic, rotated and sampled, while this is a durable record of administrative intent. And it is not an approval workflow — nothing here gates an action on another person's consent.

## Extension — 2026-09-06: retention is archival, and it is the only way a record leaves

The rule above says an audit record is never updated and never deleted through the
application, and that retention is an operational decision taken against the database.
That was the right place to stop while nothing needed to act on it. Phase 16A then
declared `operations.audit_retention_days` with a 365-day default and deliberately
built nothing to enforce it, which leaves a setting that describes an intention the
platform ignores — worse than having no setting at all.

This section decides how retention is enforced. It grants exactly one way for a record
to leave the active store, and closes every other.

### Archival, not deletion

A record leaves the active store only as part of an **archival operation**, in this
order and no other:

```
active trail  →  export  →  integrity verification  →  removal from active store
```

Each step gates the next. Nothing is removed that has not first been exported and then
verified as readable from the archive. An export that cannot be verified removes
nothing, and says so.

The intent is that retention bounds what the *active* store carries, not what the
platform can still answer. A record that has aged out has moved, not vanished.

### It is triggered, never scheduled

An operator runs it. There is no scheduled job, no queue worker, and no synchronisation
side effect that removes an audit record.

This is the specific thing the rule above forbids and it stays forbidden. A silent
periodic cleanup is indistinguishable, from the outside, from evidence disappearing —
and the moment it matters is exactly the moment nobody can prove which it was. Requiring
a person to ask makes the removal an act with an author.

`operations.audit_retention_days` therefore describes **eligibility**, not automation:
it says which records an archival operation may take, and never causes one to run.

### The archival is itself audited, and that record does not age out

An archival operation writes its own audit record: who ran it, the window taken, how
many records moved, where the archive was written, and the outcome.

**A record describing an archival is never itself eligible for archival.** Otherwise a
sufficiently patient sequence of operations erases the evidence that any of them
happened, one window at a time, and the trail ends up complete-looking and false.

### It has its own permission

Reading the trail and removing from it are different powers, and holding the first is
not a reason to hold the second — the accounts most interested in removal are the ones
being recorded.

* **`audit.view`** — read the trail. Unchanged.
* **`audit.manage`** — run an archival operation. New, and granted to nobody by default.

### The archive is a file on a configured disk, not a download

The export is written to a configured storage disk and its location is recorded in the
operation's audit record.

Not returned as a download: an endpoint that streams the security trail to whoever
called it is an exfiltration path with an access log entry that looks like maintenance.
Writing to a disk an operator has configured keeps the artefact where the deployment's
own access controls apply, and leaves a reference behind rather than a copy in a browser.

### The archive holds no secret, because the trail never did

Nothing changes here — the archive is a faithful copy of records that already contain
no plaintext, ciphertext, hash or length. It is stated because an archive is a file that
travels, and a reader deciding where to put it should not have to re-derive whether it
is safe to move.

### What is still forbidden

* No scheduled or automatic removal, under any name.
* No endpoint that edits or deletes an individual record.
* No removal that has not been exported and verified first.
* No archival of an archival record.
* No secret entering the archive, by the same rule that keeps it out of the trail.

### Not implemented

Decided here, built in Phase 16B. The retention setting stays advisory until it is.

## Alternatives considered

**A package — `owen-it/laravel-auditing` or `spatie/laravel-activitylog`.** Rejected for the reason ADR 0017 gives for vendor SDKs. Both record model attribute changes generically, which is precisely wrong here: the default behaviour of an attribute-diffing auditor is to record the old and new value of every changed column, and `settings.value` holds ciphertext. Getting the redaction right would mean configuring against the package's defaults on every model that ever holds a secret, and the failure mode is silent — a secret in a table nobody thought to check. A small, explicit recorder that cannot write a value it was not given is safer than a general one taught what to omit.

**Log to the application log and be done.** Rejected. Logs are rotated, sampled and shipped to places with different access control, and answering *who changed the rate limit last month* by grepping a log is not answering it.

**Audit everything, including reads.** Rejected as noise that would hide the signal. Reading a settings group is not an event worth recording; changing one is. Reading the audit trail itself is the exception, and it is recorded.

## Consequences

An operator can answer who changed what and when, which is what an incident requires and what the Phase 15 privilege gap could not be answered with.

Every audited operation costs a write, and one inside the transaction it belongs to. That is acceptable at administrative frequencies and would not be at request frequencies, which is part of why reads are not audited.

Secret operations become visible without secrets becoming visible — the trail shows a credential was rotated, and offers no way to learn what it was rotated to. That is the property that makes the trail safe to retain and to read.

CDN purge and provider test operations gain the record they need to be permitted at all: ADR 0036 makes purge-all conditional on being audited, and this is the record it is conditional on.
