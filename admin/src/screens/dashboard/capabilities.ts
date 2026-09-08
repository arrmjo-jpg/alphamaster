import type { StateTone } from '@/ui/state';

import type { IntegrationProvider, IntegrationUsage } from './api';

/**
 * What each vendor capability is doing, rather than what it was configured to do.
 *
 * Two sources, deliberately. The provider rows say what an operator set up; the usage
 * log says what actually happened. A capability with a perfectly configured provider
 * and nothing but failures behind it looks healthy in the first and is not — and that
 * gap is the whole reason this exists.
 *
 * Pure, and in its own file, so the judgement can be tested against fixtures without
 * rendering anything.
 */

export interface CapabilityRow {
    capability: string;
    label: string;
    active: IntegrationProvider | null;
    providerCount: number;
    /** Attempts in the window the usage endpoint returns. */
    attempts: number;
    failures: number;
    lastFailure: IntegrationUsage | null;
    tone: StateTone;
    state: 'failing' | 'degraded' | 'healthy' | 'idle' | 'unconfigured';
}

/**
 * A capability's condition, decided in one place.
 *
 * `unconfigured` is not a failure — a platform that does not send SMS has no SMS
 * provider and that is correct. `idle` says configured but unexercised, which is
 * the state most easily mistaken for working.
 */
export function assessCapability(
    capability: string,
    label: string,
    providers: IntegrationProvider[],
    usage: IntegrationUsage[],
): CapabilityRow {
    const active = providers.find((provider) => provider.is_active) ?? null;
    const attempts = usage.length;
    const failures = usage.filter((entry) => entry.status === 'failure');
    const lastFailure = failures[0] ?? null;

    const base = {
        capability,
        label,
        active,
        providerCount: providers.length,
        attempts,
        failures: failures.length,
        lastFailure,
    };

    if (active === null) {
        return { ...base, tone: 'neutral', state: 'unconfigured' };
    }

    if (attempts === 0) {
        return { ...base, tone: 'info', state: 'idle' };
    }

    if (failures.length === attempts) {
        return { ...base, tone: 'danger', state: 'failing' };
    }

    if (failures.length > 0) {
        return { ...base, tone: 'warning', state: 'degraded' };
    }

    return { ...base, tone: 'success', state: 'healthy' };
}

export function summarise(
    providers: IntegrationProvider[],
    usage: IntegrationUsage[],
    labelFor: (capability: string) => string,
): CapabilityRow[] {
    const capabilities = [
        ...new Set([
            ...providers.map((provider) => provider.capability),
            ...usage.map((entry) => entry.capability),
        ]),
    ].toSorted();

    return capabilities.map((capability) =>
        assessCapability(
            capability,
            providers.find((provider) => provider.capability === capability)?.capability_label ??
                labelFor(capability),
            providers.filter((provider) => provider.capability === capability),
            usage.filter((entry) => entry.capability === capability),
        ),
    );
}
