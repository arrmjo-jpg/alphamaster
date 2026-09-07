# ADR 0038: Configuration Concurrency and Atomicity

* **Status**: Accepted
* **Date**: 2026-09-06
* **Revised**: 2026-09-06 — the revert substrate named here was wrong and is corrected; rotation may verify before committing

## Context

The settings admin API accepts a batch update for a group and applies it in a transaction, so a batch that fails partway leaves the group untouched — there is a test that proves it. That is where the platform's concurrency story currently ends. There is no version column, no conditional update, and no `lockForUpdate` anywhere in Settings.

The consequence is last-write-wins with no signal. Two administrators open the mail configuration; the first changes the host; the second, working from what they loaded a minute earlier, changes the sender name and writes the old host back. Both requests succeed, both interfaces report success, and the platform is left in a state neither person chose. Nothing detects it, and nobody is told.

Phase 16 makes this materially worse in two ways. It widens configuration from seventeen settings to several hundred across many domains, so the chance of two operators in the same group at once stops being theoretical. And it introduces operations that are not database writes at all: rotating a credential, testing a provider, purging a CDN. An external call cannot be rolled back by a transaction, and pretending otherwise is how a configuration ends up half-applied — a provider marked active with credentials that were never accepted.

## Decision

**A configuration write states what it believes it is changing. A write built on a stale read is refused, not applied.**

### Optimistic concurrency, keyed on the version the client read

Every settings group and every provider configuration carries a version that changes on each successful write. A client reads it with the configuration and returns it with the update, as an `If-Match` precondition:

* the versions agree — the write proceeds and the version advances;
* they disagree — the write is refused with `412 Precondition Failed`, an error code naming the conflict, and the current state, so the client can show what changed rather than merely reporting a failure.

**Optimistic, not pessimistic.** Configuration is read far more than it is written and conflicts are rare; a lock held across an administrator's editing session would be held across a coffee break, and releasing it safely means inventing timeouts and stealing rules for a problem that occurs a handful of times a year.

**The simplest correct mechanism.** The version is the group's `updated_at`, or a counter where a timestamp's resolution is not sufficient to distinguish two writes — the implementation chooses, and the API expresses it as an opaque validator that a client compares but never interprets. Clients must not construct or reason about it, which keeps the choice reversible.

**A missing precondition is a conflict, not a bypass.** An update that carries no version is refused. If it were accepted, every client that had not been updated would keep silently overwriting — which is the behaviour this record exists to end, reachable by omitting a header.

### Validation completes before anything is written

A batch is validated in full — types and ranges through the strict conversion ADR 0018 already requires, plus the required-together dependencies a capability declares — before the first row is written. Partial validity is not a state the database is allowed to enter and then be rescued from, and it is not what a transaction rollback is for.

### The transaction covers the database, and only the database

A group update — its rows, its translations, its version, and its audit record (ADR 0037) — commits or fails as one unit. Cache invalidation runs *after* the commit, extending the rule `SettingService` already follows: invalidating inside the transaction lets a concurrent reader repopulate the key from uncommitted state and pin it for the entire TTL.

### External effects sit outside the transaction, and are never treated as reversible

A vendor call cannot participate in a database transaction. So the boundary is explicit:

* **Validation is local.** Storing a credential does not require the vendor to accept it. Requiring a live call before every write makes configuration unusable when the vendor is slow, and untestable without credentials.
* **Testing is a separate, explicit operation.** An operator asks the platform to verify a configuration. Its result is recorded (ADR 0037) but does not gate the write.
* **Enablement is a distinct step from configuration.** A provider is stored, then validated, then enabled. A configuration that is stored is not thereby effective.

The distinction is worth naming as separate states, because collapsing them is what lets a half-configured provider be treated as working:

```
stored  →  valid  →  configured  →  enabled  →  effective
```

**A provider never becomes effective in a partially configured state.** Enablement checks that everything its capability declares as required is present and valid; a provider missing a credential cannot be switched on, whatever order the fields were saved in.

### Rotation replaces, and never leaves the platform with neither credential

Rotating a secret writes the new value and retires the old one in a single database transaction. If the new credential is later rejected by the vendor, the platform holds a credential that does not work — a recoverable state an operator can see and fix — rather than holding none, which is unrecoverable without going back to the vendor. **A failed rotation never leaves the field empty.**

### Reconstructing a previous configuration is possible, and is not built now

The version and the audit trail together record that a change happened and what it changed. Nothing here implements revert, and no approval workflow is introduced. What this record guarantees is that revert stays *possible*: a versioned write plus an audit record of the change is the substrate a later phase would need, and neither is being designed in a way that forecloses it.

**Any future revert obeys ADR 0037's redaction rule.** History holds no secret values, so a revert can restore a configuration but never a credential — the credential must be re-supplied. That is a deliberate limit, not an oversight, and it is preferable to a history table full of recoverable secrets.

## Extension — 2026-09-06: a correction, and a rotation contract

Two changes. The first is a mistake in this record, found by trying to build on it. The
second is a decision it deliberately left for later.

### The revert substrate named above does not exist

The section above says revert stays possible because *"a versioned write plus an audit
record of the change is the substrate a later phase would need"*.

That is wrong, and it is wrong because ADR 0037 is right. An audit record for a
non-secret setting carries the fact of the change and not its value:

```php
['type' => $setting->type->value, 'localized' => $setting->is_localized]
```

Which is exactly what an audit trail should hold — and means **a configuration cannot be
restored from it**. The version counter says a change happened; the audit record says who
made it; neither says what the value was. Two correct records, jointly insufficient, and
the gap stayed invisible until something needed to read it.

The guarantee this record made — that revert stays possible — is honoured by **ADR 0040**,
which builds a revision store for the purpose and keeps it separate from the trail. The
claim about the substrate is withdrawn. Everything else in this record stands.

The wording is left above rather than edited away, because a decision that named a
mechanism which could not work is worth being able to find later.

### Rotation may verify with the vendor before committing

The section above draws a firm line: *validation is local, testing is a separate explicit
operation, and storing a credential does not require the vendor to accept it.* That line
was drawn to keep configuration usable when a vendor is slow, and to keep it testable
without credentials.

It is now narrowed for one operation only. **Rotating a secret may verify the new
credential with the vendor before committing it**, where a verifier exists.

The reason is that rotation is the one write whose failure is silent and delayed. An
ordinary setting saved wrongly is visible on the next screen; a credential saved wrongly
looks identical to one saved correctly and surfaces later as an integration that stopped
working, frequently to somebody who was not the person who changed it.

The contract:

* **A verifier is optional, and most secrets have none.** `security.api_secret_key` is
  internal — there is nothing to call. Where a capability declares a verifier, rotation
  verifies; where none exists, rotation is local and **the result says explicitly that
  live verification was unavailable**, rather than implying it passed.
* **The new credential is never persisted mid-flight.** It is held for the duration of
  the request, sent to the vendor, and then either committed or discarded. There is no
  pending state, no second column, and no row holding a credential that is not yet in
  use.
* **A failed verification leaves the stored credential exactly as it was.** Not cleared,
  not replaced, not partially applied. The section above already requires that a failed
  rotation never leaves the field empty; verification does not weaken it.
* **No secret material enters history or the audit trail**, old or new. ADR 0037's
  redaction and ADR 0040's exclusion both apply unchanged: the trail records that a
  rotation happened, and nothing about what was rotated to.
* **Rotation requires `settings.secrets.manage`**, as any write to a secret does.

### What this costs, said plainly

Rotation now depends on vendor availability. A correct credential cannot be rotated in
while the vendor is unreachable, because the platform will not commit what it could not
verify.

That is a real operational limit and it is accepted deliberately: an operator blocked by
an outage knows they are blocked, whereas an operator who committed an unverified
credential finds out days later. Where the trade is unwanted, the answer is a capability
that declares no verifier, not a flag that skips one.

### Not implemented

Decided here, built in Phase 16B.

**Built — Phase 16B-3.** `POST /admin/settings/{group}/secrets/{key}/rotate`, behind
`settings.secrets.manage` and the ordinary `If-Match` precondition. Verifiers are
registered per setting reference and most secrets have none, so the outcome distinguishes
`verified`, `failed` and `unavailable` rather than collapsing the last two.

The candidate is never persisted mid-flight: it arrives as a parameter, is handed to a
verifier that holds it for one call, and is then committed or dropped. There is no
pending column and no staging row, which is what makes "a failed verification leaves the
stored credential exactly as it was" a property of the shape rather than a rule to
remember.

One verifier exists, for `mail.password`, built on the configuration tester that already
delivers a real message. A refused rotation is recorded as a failed attempt — recording
only successes would leave the trail unable to show a credential being guessed at.

The ordinary settings write still stores a secret without verifying it, which is how a
credential is first supplied to a vendor that cannot yet be reached. Rotation is the
verified path, not the only one.

## Alternatives considered

**Last-write-wins, as today.** Rejected. It is not that conflicts are frequent; it is that the loss is silent, and the values most likely to be quietly reverted are the ones an operator changed most recently and is least likely to re-check.

**Pessimistic locking on a settings group.** Rejected as above: the lock would span human editing time, and every mechanism for releasing it safely is more complex than detecting a conflict at write time.

**Per-setting versions rather than per-group.** Rejected. The group is the unit the API already updates and the unit an operator edits, and per-setting versions would let two administrators each change half of a pair of settings that must agree — passing both preconditions while producing a combination neither intended.

**Two-phase configuration with a staging area.** Rejected as the over-engineering ADR 0033 warns about. It solves coordinating a change across systems, which is not a problem this platform has.

## Consequences

A conflicting update fails loudly with the information needed to resolve it, instead of succeeding and discarding someone's work.

Every configuration client must read a version and send it back. That is a real API obligation, and it is why the version is opaque — the contract is *return what you were given*, which a future Admin UI can satisfy without understanding it.

The five states make a genuine distinction visible that was previously collapsed: configuration that exists is not configuration that works, and neither is configuration that is switched on. An interface can show which of the three a provider is in.

Rotation gains a defined failure behaviour, which matters most in the case with no recovery — a cleared credential that nobody kept a copy of.

The residual risk is a change that bypasses the API entirely: a console command or a raw query-builder write advances no version and writes no audit record. That is why the invariants ADR 0018 keeps at the database level stay there, and why this record governs the administrative path rather than claiming to govern every write.
