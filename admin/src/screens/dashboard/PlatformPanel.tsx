import { useQuery } from '@tanstack/react-query';
import { useTranslation } from 'react-i18next';

import { absoluteTime, relativeTime } from '@/lib/time';
import { useDirection } from '@/shell/DirectionProvider';
import { Panel } from '@/ui/Panel';
import { StatusBadge } from '@/ui/StatusBadge';

import { platformHealth } from './api';

/**
 * Is the platform answering?
 *
 * The probe reports that the application is executing, not that its database or its
 * cache is reachable — that is what the route was written to mean. This panel says so
 * in as many words, because a green light a reader takes to mean more than it does is
 * worse than no light at all.
 */
export function PlatformPanel() {
    const { t } = useTranslation();
    const { locale } = useDirection();

    const health = useQuery({
        queryKey: ['platform-health'],
        queryFn: ({ signal }) => platformHealth(signal),
        // Short, because this is the panel an operator watches while waiting for a
        // deployment to come back.
        refetchInterval: 30_000,
        staleTime: 10_000,
    });

    const reachable = health.isSuccess && health.data.status === 'healthy';
    const checkedAt =
        health.dataUpdatedAt === 0 ? null : new Date(health.dataUpdatedAt).toISOString();

    return (
        <Panel
            aside={
                health.isFetching ? (
                    t('state.loading')
                ) : (
                    <span title={absoluteTime(checkedAt, locale) ?? undefined}>
                        {relativeTime(checkedAt, locale)}
                    </span>
                )
            }
            // No error state: for a liveness probe a failure *is* the answer, and
            // "not answering" is what the badge below says. It retries itself.
            loading={health.isPending}
            title={t('dashboard.platform.title')}
        >
            <div className="flex flex-col gap-3">
                <div className="flex items-center gap-2">
                    <StatusBadge tone={reachable ? 'success' : 'danger'}>
                        {reachable
                            ? t('dashboard.platform.answering')
                            : t('dashboard.platform.notAnswering')}
                    </StatusBadge>

                    {health.isSuccess ? (
                        <span
                            className="text-(length:--text-sm) text-(--text-muted)"
                            data-technical
                        >
                            {health.data.framework}
                        </span>
                    ) : null}
                </div>

                <p className="text-(length:--text-sm) text-(--text-muted)">
                    {t('dashboard.platform.scope')}
                </p>
            </div>
        </Panel>
    );
}
