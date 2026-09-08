import type { AdminUser } from '@/screens/access/api';

import type { AuditRecord } from './api';
import type { CapabilityRow } from './capabilities';

/**
 * What, out of everything the platform publishes, an operator should look at now.
 *
 * The judgement is here rather than in a component so it can be tested against
 * fixtures, and so there is exactly one definition of "needs attention" instead of
 * one per panel.
 *
 * Two rules hold throughout. Nothing is inferred that the API did not say — every
 * finding below traces to a published field. And a check that could not run is
 * reported as not having run: an operator reading a clear board must be able to tell
 * "nothing is wrong" from "I was not allowed to look", and those are the same picture
 * unless the difference is stated.
 */

/** The checks this screen knows how to make, whether or not it may make them. */
export type CheckId = 'platform' | 'administrators' | 'integrations' | 'actions';

export type FindingKind =
    | 'platform-silent'
    | 'administrator-blocked'
    | 'capability-failing'
    | 'capability-degraded'
    | 'action-failed';

export interface Finding {
    /** Stable across refetches, so React keys do not shuffle. */
    id: string;
    kind: FindingKind;
    check: CheckId;
    severity: 'critical' | 'warning';
    /** How many subjects are in this state. */
    count: number;
    /**
     * The subjects themselves, as the platform named them. Not a summary — an
     * operator's next question after "three of them" is always "which three".
     */
    subjects: string[];
}

export interface Attention {
    findings: Finding[];
    /** Checks that ran. A clear board means these and nothing more. */
    checked: CheckId[];
    /** Checks that did not run, because the account may not read what they need. */
    skipped: CheckId[];
}

export interface AttentionInput {
    /** `null` while the probe has not answered either way yet. */
    reachable: boolean | null;
    /** `null` where the account may not read what the check needs. */
    accounts: AdminUser[] | null;
    capabilities: CapabilityRow[] | null;
    failedActions: AuditRecord[] | null;
}

/**
 * Why an administrator cannot get in.
 *
 * These are the perimeter's own stages, not a policy invented here: an account that
 * is suspended, has an unconfirmed address, or has no confirmed second factor is
 * refused at sign-in (ADR 0012, ADR 0013). So this is not a security recommendation
 * an operator may weigh — it is a list of people who will call support today.
 */
export type BlockedReason = 'suspended' | 'unverified' | 'no-second-factor';

export function blockedReasons(account: AdminUser): BlockedReason[] {
    const reasons: BlockedReason[] = [];

    if (!account.is_active) {
        reasons.push('suspended');
    }

    if (!account.email_verified) {
        reasons.push('unverified');
    }

    if (!account.mfa_enrolled) {
        reasons.push('no-second-factor');
    }

    return reasons;
}

export function assessAttention(input: AttentionInput): Attention {
    const findings: Finding[] = [];
    const checked: CheckId[] = [];
    const skipped: CheckId[] = [];

    // The probe is public, so this check always runs — but only once it has answered.
    // A pending probe is not a silent platform.
    if (input.reachable !== null) {
        checked.push('platform');

        if (!input.reachable) {
            findings.push({
                id: 'platform-silent',
                kind: 'platform-silent',
                check: 'platform',
                severity: 'critical',
                count: 1,
                subjects: [],
            });
        }
    }

    if (input.accounts === null) {
        skipped.push('administrators');
    } else {
        checked.push('administrators');

        const blocked = input.accounts.filter(
            (account) => account.account_type === 'admin' && blockedReasons(account).length > 0,
        );

        if (blocked.length > 0) {
            findings.push({
                id: 'administrator-blocked',
                kind: 'administrator-blocked',
                check: 'administrators',
                severity: 'critical',
                count: blocked.length,
                subjects: blocked.map((account) => account.name),
            });
        }
    }

    if (input.capabilities === null) {
        skipped.push('integrations');
    } else {
        checked.push('integrations');

        const failing = input.capabilities.filter((row) => row.state === 'failing');
        const degraded = input.capabilities.filter((row) => row.state === 'degraded');

        // Failing and degraded are separate findings rather than one "unhealthy"
        // count: every attempt failing and some attempts failing call for different
        // urgency, and merging them would hide the worse one inside the commoner one.
        if (failing.length > 0) {
            findings.push({
                id: 'capability-failing',
                kind: 'capability-failing',
                check: 'integrations',
                severity: 'critical',
                count: failing.length,
                subjects: failing.map((row) => row.label),
            });
        }

        if (degraded.length > 0) {
            findings.push({
                id: 'capability-degraded',
                kind: 'capability-degraded',
                check: 'integrations',
                severity: 'warning',
                count: degraded.length,
                subjects: degraded.map((row) => row.label),
            });
        }
    }

    if (input.failedActions === null) {
        skipped.push('actions');
    } else {
        checked.push('actions');

        if (input.failedActions.length > 0) {
            findings.push({
                id: 'action-failed',
                kind: 'action-failed',
                check: 'actions',
                severity: 'warning',
                count: input.failedActions.length,
                subjects: input.failedActions.map((record) => record.action_label),
            });
        }
    }

    // Critical first. Within a severity the order above stands, which is roughly
    // reach: the platform, then who can get in, then what it can talk to.
    return {
        findings: findings.toSorted((a, b) =>
            a.severity === b.severity ? 0 : a.severity === 'critical' ? -1 : 1,
        ),
        checked,
        skipped,
    };
}

/**
 * Where a finding is acted on.
 *
 * Only routes that exist, and only where the account may reach them. A finding with
 * nowhere to go keeps its `null` and the interface says as much, rather than offering
 * a button that lands on a screen this product has not built.
 */
export interface Destination {
    path: string;
    permission: string;
}

export function destinationFor(finding: Finding): Destination | null {
    switch (finding.kind) {
        case 'administrator-blocked':
            return { path: '/access/users', permission: 'users.view' };

        case 'capability-failing':
        case 'capability-degraded':
            // The mail group is the one place this console can act on a capability: it
            // holds the delivery settings and the test-message control. Providers
            // themselves have an API but no screen yet, so every other capability gets
            // no destination rather than a misleading one.
            return finding.subjects.some((label) => /mail/i.test(label))
                ? { path: '/settings/mail', permission: 'settings.view' }
                : null;

        case 'action-failed':
        case 'platform-silent':
            return null;
    }
}
