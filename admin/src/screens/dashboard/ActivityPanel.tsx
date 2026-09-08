import { useQuery } from '@tanstack/react-query';
import { useTranslation } from 'react-i18next';

import { absoluteTime, relativeTime } from '@/lib/time';
import { useDirection } from '@/shell/DirectionProvider';
import { Panel } from '@/ui/Panel';
import { StatusBadge } from '@/ui/StatusBadge';

import { recentAudit } from './api';

const RECENT = 8;

/**
 * The last few administrative actions.
 *
 * The trail is append-only and has no write endpoint (ADR 0037), so this is a
 * read and only ever a read. It shows the label beside nothing else the platform
 * did not send: `action_label` is what a person reads, and the stable `action` is
 * what anything else would branch on.
 *
 * A failed action is coloured, because the reason this panel is on an operations
 * dashboard rather than in an archive is that a failure here is usually the first
 * visible sign of a misconfiguration.
 */
export function ActivityPanel() {
    const { t } = useTranslation();
    const { locale } = useDirection();

    const audit = useQuery({
        queryKey: ['recent-audit', RECENT],
        queryFn: ({ signal }) => recentAudit(RECENT, signal),
    });

    return (
        <Panel
            aside={
                audit.data?.total !== null && audit.data?.total !== undefined
                    ? t('dashboard.activity.total', { count: audit.data.total })
                    : undefined
            }
            empty={audit.data?.records.length === 0}
            emptyMessage={t('dashboard.activity.none')}
            error={audit.error}
            loading={audit.isPending}
            onRetry={() => void audit.refetch()}
            title={t('dashboard.activity.title')}
        >
            <ul className="flex flex-col divide-y divide-(--border-default)">
                {audit.data?.records.map((record) => (
                    <li
                        className="flex items-start justify-between gap-3 py-1.5 first:pt-0 last:pb-0"
                        key={record.id}
                    >
                        <div className="flex min-w-0 flex-col">
                            <span className="truncate text-(--text-primary)">
                                {record.action_label}
                            </span>
                            {record.subject !== null ? (
                                <span
                                    className="truncate text-(length:--text-sm) text-(--text-muted)"
                                    data-technical
                                >
                                    {record.subject}
                                </span>
                            ) : null}
                        </div>

                        <div className="flex shrink-0 items-center gap-2">
                            {record.outcome === 'failed' ? (
                                <StatusBadge tone="danger">
                                    {t('dashboard.activity.failed')}
                                </StatusBadge>
                            ) : null}
                            <span
                                className="text-(length:--text-sm) text-(--text-muted)"
                                title={absoluteTime(record.created_at, locale) ?? undefined}
                            >
                                {relativeTime(record.created_at, locale)}
                            </span>
                        </div>
                    </li>
                ))}
            </ul>
        </Panel>
    );
}
