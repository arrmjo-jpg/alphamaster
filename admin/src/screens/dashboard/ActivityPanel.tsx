import { useQuery } from '@tanstack/react-query';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';

import { absoluteTime, relativeTime } from '@/lib/time';
import { useDirection } from '@/shell/DirectionProvider';
import { Panel } from '@/ui/Panel';
import { SegmentedControl } from '@/ui/SegmentedControl';
import { StatusBadge } from '@/ui/StatusBadge';

import { recentAudit } from './api';

const RECENT = 8;

export interface ActivityPanelProps {
    headingLevel?: 2 | 3;
}

/**
 * What has changed, most recent first.
 *
 * The trail is append-only and has no write endpoint (ADR 0037), so this is a read
 * and only ever a read. It shows what the platform sent and nothing beside it:
 * `action_label` is what a person reads, and the stable `action` is what anything
 * else would branch on.
 *
 * The failures filter is the endpoint's own `outcome` parameter, not a filter applied
 * to this page after it arrives. That distinction is the whole value of the control —
 * the failure worth finding is usually several pages back, where sifting one page
 * would never have found it.
 */
export function ActivityPanel({ headingLevel }: ActivityPanelProps) {
    const { t } = useTranslation();
    const { locale } = useDirection();
    const [failuresOnly, setFailuresOnly] = useState(false);

    const audit = useQuery({
        queryKey: ['recent-audit', RECENT, failuresOnly],
        queryFn: ({ signal }) => recentAudit(RECENT, failuresOnly ? 'failed' : undefined, signal),
    });

    return (
        <Panel
            aside={
                <SegmentedControl
                    label={t('dashboard.activity.filter')}
                    onChange={(value) => setFailuresOnly(value === 'failed')}
                    options={[
                        { value: 'all', label: t('dashboard.activity.all') },
                        { value: 'failed', label: t('dashboard.activity.failedOnly') },
                    ]}
                    value={failuresOnly ? 'failed' : 'all'}
                />
            }
            empty={audit.data?.records.length === 0}
            emptyMessage={
                failuresOnly ? t('dashboard.activity.noFailures') : t('dashboard.activity.none')
            }
            error={audit.error}
            {...(headingLevel === undefined ? {} : { headingLevel })}
            loading={audit.isPending}
            onRetry={() => void audit.refetch()}
            title={t('dashboard.activity.title')}
        >
            <ul className="flex flex-col divide-y divide-(--border-default)">
                {audit.data?.records.map((record) => (
                    <li
                        className="flex flex-wrap items-start justify-between gap-x-3 gap-y-1 py-1.5 first:pt-0 last:pb-0"
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
