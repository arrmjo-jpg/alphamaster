# ADR 0037: Administrative Audit Trail

* **Status**: Accepted
* **Date**: 2026-09-06
* **Revised**: 2026-09-06 — retention settled: archival, as the single permitted removal path
* **Revised**: 2026-09-10 — scope widened: the trail covers accounts, not only configuration
* **Built**: 2026-09-07 — Phase 16B-4 implements the archival operation, its permission, and the trail's first read endpoint

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

## Extension — 2026-09-10: who may sign in is part of the security configuration

The decision above says *every administrative operation that changes platform
behaviour*, and then illustrates it entirely with configuration: settings, secrets,
providers, purges, rollbacks, archival. Every operation built against it since has been
a configuration operation, and the scope quietly narrowed to match the examples rather
than the rule.

`feature/access-completion` made the narrowing visible by adding four account
operations — create, edit, activate, deactivate — beside two that had been unaudited
since Phase 6, promotion and demotion. None of the six wrote anything to the trail. So
the platform could answer *who changed the rate limit last month* and could not answer
*who made this account an administrator*, which is the more serious of the two
questions.

This section settles that the trail's subject is the platform's security posture, and
that accounts are part of it.

### Why this is a widening and not an oversight being patched

It is worth being explicit, because the original record can be read either way.

The Context above argues from operations that are *irreversible or outward-reaching* —
a secret whose previous value is gone, a purge with no rollback, a provider change that
moves delivery to another vendor. An account edit is none of those: it is reversible by
editing back, it reaches nothing outside, and every value it touches is still readable
in the `users` table afterwards. On that reading, account operations were correctly
excluded.

The Decision, though, is not argued from irreversibility. It is argued from *changes
platform behaviour*, and the section on reading the trail says plainly that the trail
"describes the platform's security configuration". Who may sign in, and who may sign in
as an administrator, is that configuration — more directly than the rate limit is. An
administrator promoted is a change to the set of people who can perform every other
operation this record already covers, which makes it the precondition of the trail
rather than something outside it.

The second reading wins, and the record says so here rather than leaving the two
readings both defensible.

### What is recorded

Six actions, named for what happened rather than for the endpoint that did it:

```
account.created       an account exists that did not
account.updated       identity changed
account.activated     sign-in allowed
account.deactivated   sign-in stopped, and tokens revoked
account.promoted      the account crossed the administrative boundary
account.demoted       it crossed back, and its roles were stripped
account.roles_changed which roles an administrator gained and which were taken away
```

The subject is the account's identifier, never its address. The identifier survives an
address change and an address does not, so a trail keyed on the address would report
two different subjects for one account and answer "what happened to this account" wrong.

### The redaction rule extends unchanged, and it now covers more than secrets

The trail holds no plaintext, ciphertext, partial value, hash or length of a secret.
Account operations add a second category the rule has to reach: **contact details are
not credentials and they are still not recorded.**

A telephone number is a sign-in identifier on this platform and a means of reaching a
person off it; an address is both of those and the route through which an account is
recovered. The trail is readable by anyone holding `audit.view`, is exported to a file
that travels (see the archival section above), and is retained for a year by default.
A record of every operator's current and former number and address, under those three
properties, is a directory — and building one as a side effect of recording that a name
was corrected is not a trade this record is willing to make.

So `account.updated` answers *what changed* the way the secret actions do: **by naming
the fields and not their values.**

```
account.updated  <account-id>  changed=[name, phone]  email_verification_cleared=false
account.updated  <account-id>  changed=[email]        email_verification_cleared=true
```

Nothing is lost that the platform can still answer. The current values are in the
`users` table, where they are readable under the permission that governs accounts
rather than the one that governs the trail. What only the trail can say is that
somebody changed them, and when, and who.

`email_verification_cleared` is carried because it is the security consequence, not
the mechanics: an administrator moved an account onto an address nobody has confirmed,
and for an administrative account that is the difference between a verified identity
and an asserted one (ADR 0012).

A password never appears in any form. `account.created` records that an account was
created and whether it could sign in; it does not record that a password was set,
because there is no creation without one and a field that is always present carries no
information.

### Two facts that survive nowhere else

Most of what these records carry is recoverable from the account afterwards. Two things
are not, and both are recorded for that reason:

* **`tokens_revoked`** on deactivation, promotion and demotion — how many live sessions
  the operation actually ended. After it runs the rows are gone, so the count exists
  only if it was written down. It also distinguishes an operation that reached
  something from one that reached nothing.
* **`roles_revoked`** on demotion — demotion strips every admin role, and once it has,
  nothing in the platform remembers what they were. Restoring an account to what it had
  is impossible from any other source. Role names are a public catalogue (ADR 0014) and
  carry no secret, so this is recordable without qualification.

### Only a change is recorded

Activating an account that is already active, or promoting an account that is already
an administrator, changes nothing about who may sign in. Those write no record.

An edit is held to the same test, and it has to be, because it is the one an interface
will trip over: a console that submits the whole form on every save sends every field
back whether or not anybody touched it. So `account.updated` reports the fields whose
values actually moved, and an edit where none did writes nothing at all. A number
retyped with different separators is not a change either — the comparison is against
the canonical stored form, not the characters submitted.

This follows the rule as stated — *operations that change platform behaviour* — rather
than being an efficiency. The original record rejects auditing reads as "noise that
would hide the signal", and a row asserting that an account which could sign in can
still sign in is that same noise wearing a write's clothing.

By the same reasoning, a refused operation writes nothing: an administrator attempting
to deactivate themselves is answered with a `422`, and nothing about the platform
changed. This is not the failure case the original record insists on capturing. That
one is an operation that *was attempted against something outside the platform* and did
not take effect — a purge that failed, a provider that timed out — where the trail is
the only place the discrepancy between the interface and reality is visible. A refused
request never reached anything, and the request log already has it.

### Recording is inside the transaction, as it already was

Each of the six records commits with the change it describes or not at all. For
promotion and demotion this means the call into `AccountTypeManager` is wrapped rather
than followed: the manager runs its own transaction, and recording after it returned
would leave a window in which the boundary crossing is durable and the record of it is
not.

### What this does not extend to

**Role definitions** — creating a role, changing the permissions it carries, deleting
one — are not covered here. They change what a role *grants*, which reaches every
account holding it at once, and they belong in the trail by the same argument as
everything above. They are named as an acknowledged gap rather than folded silently
into a section about accounts, and they are the next thing to add.

**Authentication events** — sign-in, sign-out, a failed attempt, an MFA challenge — stay
out, and not for scope reasons. They occur at request frequency rather than
administrative frequency, which is the same reason reads are not audited, and they
belong to an authentication log with its own retention rather than to a record of
administrative intent.

**Account deletion** has no endpoint. If one is ever added it is the single most
important thing on this list to record, and it must be recorded before it is built
rather than after.

## Alternatives considered

**A package — `owen-it/laravel-auditing` or `spatie/laravel-activitylog`.** Rejected for the reason ADR 0017 gives for vendor SDKs. Both record model attribute changes generically, which is precisely wrong here: the default behaviour of an attribute-diffing auditor is to record the old and new value of every changed column, and `settings.value` holds ciphertext. Getting the redaction right would mean configuring against the package's defaults on every model that ever holds a secret, and the failure mode is silent — a secret in a table nobody thought to check. A small, explicit recorder that cannot write a value it was not given is safer than a general one taught what to omit.

**Log to the application log and be done.** Rejected. Logs are rotated, sampled and shipped to places with different access control, and answering *who changed the rate limit last month* by grepping a log is not answering it.

**Audit everything, including reads.** Rejected as noise that would hide the signal. Reading a settings group is not an event worth recording; changing one is. Reading the audit trail itself is the exception, and it is recorded.

## Consequences

An operator can answer who changed what and when, which is what an incident requires and what the Phase 15 privilege gap could not be answered with.

Every audited operation costs a write, and one inside the transaction it belongs to. That is acceptable at administrative frequencies and would not be at request frequencies, which is part of why reads are not audited.

Secret operations become visible without secrets becoming visible — the trail shows a credential was rotated, and offers no way to learn what it was rotated to. That is the property that makes the trail safe to retain and to read.

CDN purge and provider test operations gain the record they need to be permitted at all: ADR 0036 makes purge-all conditional on being audited, and this is the record it is conditional on.
