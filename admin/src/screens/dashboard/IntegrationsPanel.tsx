import { useQueries } from '@tanstack/react-query';
import { useTranslation } from 'react-i18next';

import { absoluteTime, relativeTime } from '@/lib/time';
import { useDirection } from '@/shell/DirectionProvider';
import { Panel } from '@/ui/Panel';
import { StateRail } from '@/ui/StateRail';
import { StatusBadge } from '@/ui/StatusBadge';

import { integrationProviders, integrationUsage } from './api';
import { summarise } from './capabilities';

/**
 * What each vendor capability is doing, rendered.
 *
 * The judgement itself lives in `capabilities.ts`; this asks for both sources and
 * shows what that judgement says.
 */

export interface IntegrationsPanelProps {
    headingLevel?: 2 | 3;
}

export function IntegrationsPanel({ headingLevel }: IntegrationsPanelProps = {}) {
    const { t } = useTranslation();
    const { locale } = useDirection();

    const [providers, usage] = useQueries({
        queries: [
            {
                queryKey: ['integration-providers'],
                queryFn: ({ signal }: { signal: AbortSignal }) => integrationProviders(signal),
            },
            {
                queryKey: ['integration-usage'],
                queryFn: ({ signal }: { signal: AbortSignal }) => integrationUsage(signal),
            },
        ],
    });

    const rows =
        providers.data !== undefined && usage.data !== undefined
            ? summarise(providers.data, usage.data, (capability) => capability)
            : [];

    const retry = () => {
        void providers.refetch();
        void usage.refetch();
    };

    return (
        <Panel
            empty={rows.length === 0}
            emptyMessage={t('dashboard.integrations.none')}
            error={providers.error ?? usage.error}
            {...(headingLevel === undefined ? {} : { headingLevel })}
            loading={providers.isPending || usage.isPending}
            onRetry={retry}
            title={t('dashboard.integrations.title')}
        >
            <ul className="flex flex-col gap-3">
                {rows.map((row) => (
                    <li key={row.capability}>
                        <StateRail tone={row.tone}>
                            <div className="flex flex-wrap items-center gap-2">
                                <span className="font-medium text-(--text-primary)">
                                    {row.label}
                                </span>
                                <StatusBadge tone={row.tone}>
                                    {t(`dashboard.integrations.state.${row.state}`)}
                                </StatusBadge>
                                {row.active !== null ? (
                                    <span
                                        className="text-(length:--text-sm) text-(--text-muted)"
                                        data-technical
                                    >
                                        {row.active.driver}
                                    </span>
                                ) : null}
                            </div>

                            <p className="text-(length:--text-sm) text-(--text-secondary)">
                                {row.active === null
                                    ? t('dashboard.integrations.noActiveProvider', {
                                          count: row.providerCount,
                                      })
                                    : t('dashboard.integrations.attempts', {
                                          count: row.attempts,
                                          failures: row.failures,
                                      })}
                            </p>

                            {row.active !== null && !row.active.has_credentials ? (
                                <p className="text-(length:--text-sm) text-(--text-danger)">
                                    {t('dashboard.integrations.noCredentials')}
                                </p>
                            ) : null}

                            {row.lastFailure !== null ? (
                                <p className="text-(length:--text-sm) text-(--text-muted)">
                                    <span data-technical>
                                        {row.lastFailure.error_code ?? t('state.error')}
                                    </span>
                                    {' · '}
                                    <span
                                        title={
                                            absoluteTime(row.lastFailure.created_at, locale) ??
                                            undefined
                                        }
                                    >
                                        {relativeTime(row.lastFailure.created_at, locale)}
                                    </span>
                                </p>
                            ) : null}
                        </StateRail>
                    </li>
                ))}
            </ul>
        </Panel>
    );
}
