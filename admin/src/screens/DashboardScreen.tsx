import { useQueries, useQuery } from '@tanstack/react-query';
import { useTranslation } from 'react-i18next';

import { platformHealth } from '@/api/health';
import { useCurrentUser } from '@/auth/AuthProvider';
import { ActivityPanel } from '@/screens/dashboard/ActivityPanel';
import { assessAttention } from '@/screens/dashboard/attention';
import { AttentionPanel } from '@/screens/dashboard/AttentionPanel';
import {
    integrationProviders,
    integrationUsage,
    maintenanceState,
    recentAudit,
} from '@/screens/dashboard/api';
import { summarise } from '@/screens/integrations/capabilities';
import { IntegrationsPanel } from '@/screens/dashboard/IntegrationsPanel';
import { NextActionsPanel } from '@/screens/dashboard/NextActionsPanel';
import { StatePanel } from '@/screens/dashboard/StatePanel';
import { users } from '@/screens/access/api';

/**
 * Operations, in the order an operator actually asks the questions.
 *
 * What needs me now; what is this platform; what changed; what should I do. Four
 * regions, and the first one is empty on a good day — a screen where the first thing
 * you read is a row of totals trains people to skim past the row where the alarm
 * eventually appears.
 *
 * Every query is gated on the permission its endpoint requires, so a request certain
 * to be refused is never sent: the API would answer 403, a panel would render an
 * error, and an operator would be told something is wrong when nothing is. What that
 * costs is coverage, and the attention panel says which checks it could not make
 * rather than presenting a narrower all-clear as a complete one.
 *
 * There is no chart. The two datasets that could carry one are the audit trail, which
 * is paginated rather than aggregated, and the integration log, which is the last
 * hundred attempts rather than a period — so any time axis drawn from either would be
 * an axis the data does not have. The counts below are what the platform actually
 * published.
 */
export function DashboardScreen() {
    const { t } = useTranslation();
    const user = useCurrentUser();

    const may = (permission: string) => user.permissions.includes(permission);
    const mayReadUsers = may('users.view');
    const mayReadIntegrations = may('integrations.view');
    const mayReadAudit = may('audit.view');

    const health = useQuery({
        queryKey: ['platform-health'],
        queryFn: ({ signal }) => platformHealth(signal),
        refetchInterval: 30_000,
        staleTime: 10_000,
    });

    const [closed, accounts, providers, usage, failures] = useQueries({
        queries: [
            {
                queryKey: ['maintenance-state'],
                // Public, so it needs no permission and is never skipped. An
                // administrator's own token bypasses maintenance, which is precisely
                // why the board has to say the platform is closed to everyone else.
                queryFn: ({ signal }: { signal: AbortSignal }) => maintenanceState(signal),
                refetchInterval: 60_000,
            },
            {
                queryKey: ['admin-users'],
                queryFn: ({ signal }: { signal: AbortSignal }) => users(signal),
                enabled: mayReadUsers,
            },
            {
                queryKey: ['integration-providers'],
                queryFn: ({ signal }: { signal: AbortSignal }) => integrationProviders(signal),
                enabled: mayReadIntegrations,
            },
            {
                queryKey: ['integration-usage'],
                queryFn: ({ signal }: { signal: AbortSignal }) => integrationUsage(signal),
                enabled: mayReadIntegrations,
            },
            {
                queryKey: ['recent-audit-failures'],
                // The endpoint's own filter. A failure several pages back is exactly
                // the one worth surfacing, and sifting one page would never find it.
                queryFn: ({ signal }: { signal: AbortSignal }) => recentAudit(5, 'failed', signal),
                enabled: mayReadAudit,
            },
        ],
    });

    const capabilities =
        providers.data !== undefined && usage.data !== undefined
            ? summarise(providers.data, usage.data, (capability) => capability)
            : null;

    const attention = assessAttention({
        reachable: health.isPending ? null : health.isSuccess && health.data.status === 'healthy',
        closed: closed.data ?? null,
        // `null` where the check could not run, and only where it could not. A query
        // still in flight is undefined rather than forbidden, so it stays out of both
        // the findings and the "not checked" list until it lands.
        accounts: mayReadUsers ? (accounts.data ?? null) : null,
        capabilities: mayReadIntegrations ? capabilities : null,
        failedActions: mayReadAudit ? (failures.data?.records ?? null) : null,
    });

    const settling =
        health.isPending ||
        closed.isPending ||
        (mayReadUsers && accounts.isPending) ||
        (mayReadIntegrations && (providers.isPending || usage.isPending)) ||
        (mayReadAudit && failures.isPending);

    return (
        <div className="flex flex-col gap-(--section-gap)">
            <header className="flex flex-col gap-1">
                <p data-eyebrow>{t('dashboard.eyebrow')}</p>
                <h1 className="text-(length:--text-2xl) text-(--text-primary)">
                    {t('modules.dashboard')}
                </h1>
                <p className="max-w-prose text-(--text-secondary)">{t('dashboard.description')}</p>
            </header>

            <AttentionPanel
                attention={attention}
                loading={settling}
                permissions={user.permissions}
            />

            <Region title={t('dashboard.regions.state')}>
                <div className="grid grid-cols-1 gap-(--section-gap) xl:grid-cols-2">
                    <StatePanel headingLevel={3} mayReadAudit={mayReadAudit} />
                    {mayReadIntegrations ? <IntegrationsPanel headingLevel={3} /> : null}
                </div>
            </Region>

            {mayReadAudit ? (
                <Region title={t('dashboard.regions.changes')}>
                    <ActivityPanel headingLevel={3} />
                </Region>
            ) : null}

            <NextActionsPanel
                attention={attention}
                capabilities={capabilities}
                loading={settling}
                permissions={user.permissions}
            />
        </div>
    );
}

/**
 * A named band of the page.
 *
 * Only where a region holds more than one panel or would otherwise be unnamed — the
 * attention and next-action panels are their own region and carry their own title, so
 * wrapping them here would put the same words on the screen twice.
 */
function Region({ title, children }: { title: string; children: React.ReactNode }) {
    return (
        <section className="flex flex-col gap-2">
            <h2 data-eyebrow>{title}</h2>
            {children}
        </section>
    );
}
