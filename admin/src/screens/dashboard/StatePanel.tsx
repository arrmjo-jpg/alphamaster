import { useQuery } from '@tanstack/react-query';
import { useTranslation } from 'react-i18next';

import { platformHealth } from '@/api/health';
import { absoluteTime, relativeTime } from '@/lib/time';
import { useDirection } from '@/shell/DirectionProvider';
import { Panel } from '@/ui/Panel';
import { StatusBadge } from '@/ui/StatusBadge';

import { adminLanguages, recentAudit } from './api';

export interface StatePanelProps {
    mayReadAudit: boolean;
    headingLevel?: 2 | 3;
}

/**
 * What the platform is, as opposed to what it is doing.
 *
 * Facts that change on the scale of a deployment rather than of a minute: whether the
 * application answers, which languages it serves, how much trail it is holding. An
 * operator does not watch this panel — they consult it, usually to confirm that the
 * thing they just changed took effect.
 *
 * Each line is one published field or a count of them. Nothing here is a computed
 * "score", because a number that summarises several unrelated facts tells an operator
 * that something is wrong without telling them what, which is the worst of both.
 */
export function StatePanel({ mayReadAudit, headingLevel }: StatePanelProps) {
    const { t } = useTranslation();
    const { locale } = useDirection();

    const health = useQuery({
        queryKey: ['platform-health'],
        queryFn: ({ signal }) => platformHealth(signal),
        // Short, because this is the line an operator watches while waiting for a
        // deployment to come back.
        refetchInterval: 30_000,
        staleTime: 10_000,
    });

    // No permission gate: `/admin/languages` is behind the perimeter and nothing
    // more, so any account that reached this screen may read it.
    const languages = useQuery({
        queryKey: ['admin-languages'],
        queryFn: ({ signal }) => adminLanguages(signal),
    });

    // One record, asked for only to read the total out of the envelope. The trail
    // itself is the panel below this one.
    const trail = useQuery({
        queryKey: ['audit-total'],
        queryFn: ({ signal }) => recentAudit(1, undefined, signal),
        enabled: mayReadAudit,
    });

    const reachable = health.isSuccess && health.data.status === 'healthy';
    const checkedAt =
        health.dataUpdatedAt === 0 ? null : new Date(health.dataUpdatedAt).toISOString();

    const active = languages.data?.filter((language) => language.is_active) ?? [];
    const fallback = languages.data?.find((language) => language.is_default) ?? null;

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
            {...(headingLevel === undefined ? {} : { headingLevel })}
            // No error state for the panel as a whole: for a liveness probe a failure
            // *is* the answer, and "not answering" is what the badge says. It retries
            // itself.
            loading={health.isPending}
            title={t('dashboard.state.title')}
        >
            <dl className="flex flex-col divide-y divide-(--border-default)">
                <Row label={t('dashboard.state.reachability')}>
                    <div className="flex flex-wrap items-center gap-2">
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
                    {/* The probe reports that the application is executing, not that
                        its database or its cache is reachable — that is what the route
                        was written to mean. A green light a reader takes to mean more
                        than it does is worse than no light at all. */}
                    <p className="mt-0.5 text-(length:--text-xs) text-(--text-muted)">
                        {t('dashboard.platform.scope')}
                    </p>
                </Row>

                <Row label={t('dashboard.state.languages')}>
                    {languages.isPending ? (
                        <span className="text-(--text-muted)">{t('state.loading')}</span>
                    ) : languages.error !== null ? (
                        <span className="text-(--text-muted)">{t('state.unavailable')}</span>
                    ) : (
                        <>
                            <span className="text-(--text-primary)">
                                {t('dashboard.state.languageCount', {
                                    count: active.length,
                                    total: languages.data?.length ?? 0,
                                })}
                            </span>
                            {fallback !== null ? (
                                <p className="mt-0.5 text-(length:--text-xs) text-(--text-muted)">
                                    {t('dashboard.state.defaultLanguage', {
                                        name: fallback.native_name,
                                    })}
                                </p>
                            ) : null}
                        </>
                    )}
                </Row>

                {mayReadAudit ? (
                    <Row label={t('dashboard.state.trail')}>
                        {trail.isPending ? (
                            <span className="text-(--text-muted)">{t('state.loading')}</span>
                        ) : trail.error !== null || trail.data?.total === null ? (
                            <span className="text-(--text-muted)">{t('state.unavailable')}</span>
                        ) : (
                            <span className="text-(--text-primary)">
                                {t('dashboard.activity.total', { count: trail.data?.total ?? 0 })}
                            </span>
                        )}
                        {/* Retention is a deployment decision the API does not
                            publish, so this is a size and not a window. */}
                        <p className="mt-0.5 text-(length:--text-xs) text-(--text-muted)">
                            {t('dashboard.state.trailNote')}
                        </p>
                    </Row>
                ) : null}
            </dl>
        </Panel>
    );
}

/**
 * A label beside its value, never instead of it (ADR 0030).
 *
 * A description list rather than a grid of divs, so the pairing survives into the
 * accessibility tree instead of being a purely visual arrangement.
 */
function Row({ label, children }: { label: string; children: React.ReactNode }) {
    return (
        <div className="grid grid-cols-1 gap-x-4 py-2 first:pt-0 last:pb-0 sm:grid-cols-[140px_1fr]">
            <dt className="text-(length:--text-sm) text-(--text-muted)">{label}</dt>
            <dd className="min-w-0 text-(length:--text-sm)">{children}</dd>
        </div>
    );
}
