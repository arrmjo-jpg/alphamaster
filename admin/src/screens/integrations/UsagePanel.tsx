import { useState } from 'react';
import { useTranslation } from 'react-i18next';

import { absoluteTime, relativeTime } from '@/lib/time';
import { useDirection } from '@/shell/DirectionProvider';
import { Panel } from '@/ui/Panel';
import { SegmentedControl } from '@/ui/SegmentedControl';
import { StatusBadge } from '@/ui/StatusBadge';

import type { IntegrationUsage } from './api';

type Filter = 'all' | 'failure';

export interface UsagePanelProps {
    usage: readonly IntegrationUsage[];
}

/**
 * What the platform has actually asked its vendors, most recent first.
 *
 * The endpoint takes no parameters: it answers with the last hundred attempts across
 * every capability, and that is the entire window. So the filter below is applied in
 * the browser, to those rows and no others, and the panel says so — a control that
 * looked like it had asked the server a narrower question would quietly become wrong
 * the moment there were more than a hundred attempts worth looking at.
 */
export function UsagePanel({ usage }: UsagePanelProps) {
    const { t } = useTranslation();
    const { locale } = useDirection();
    const [filter, setFilter] = useState<Filter>('all');

    const rows = filter === 'all' ? usage : usage.filter((entry) => entry.status === 'failure');

    return (
        <Panel
            aside={
                <SegmentedControl<Filter>
                    label={t('integrations.usage.filter')}
                    onChange={setFilter}
                    options={[
                        { value: 'all', label: t('integrations.usage.all') },
                        { value: 'failure', label: t('integrations.usage.failuresOnly') },
                    ]}
                    value={filter}
                />
            }
            empty={rows.length === 0}
            emptyMessage={
                filter === 'all' ? t('integrations.noAttempts') : t('integrations.noFailures')
            }
            title={t('integrations.usage.title')}
        >
            <div className="flex flex-col gap-2">
                <p className="text-(length:--text-sm) text-(--text-muted)">
                    {t('integrations.usage.window', { count: usage.length })}
                </p>

                <div className="overflow-x-auto">
                    <table className="w-full border-collapse">
                        <thead>
                            <tr className="border-b border-(--border-strong)">
                                <Th>{t('integrations.usage.columns.outcome')}</Th>
                                <Th>{t('integrations.usage.columns.capability')}</Th>
                                <Th>{t('integrations.usage.columns.driver')}</Th>
                                <Th>{t('integrations.usage.columns.detail')}</Th>
                                <Th>{t('integrations.usage.columns.when')}</Th>
                            </tr>
                        </thead>
                        <tbody>
                            {rows.map((entry) => (
                                <tr
                                    className="border-b border-(--border-default) last:border-b-0"
                                    key={entry.id}
                                >
                                    <Td>
                                        <StatusBadge
                                            tone={entry.status === 'failure' ? 'danger' : 'success'}
                                        >
                                            {entry.status === 'failure'
                                                ? t('integrations.usage.failure')
                                                : t('integrations.usage.success')}
                                        </StatusBadge>
                                    </Td>
                                    <Td>
                                        <span data-technical>{entry.capability}</span>
                                    </Td>
                                    <Td>
                                        <span data-technical>{entry.driver}</span>
                                    </Td>
                                    <Td>
                                        {entry.error_code === null ? (
                                            <span className="text-(--text-muted)">
                                                {entry.duration_ms === null
                                                    ? t('integrations.usage.noDetail')
                                                    : t('integrations.usage.duration', {
                                                          ms: entry.duration_ms,
                                                      })}
                                            </span>
                                        ) : (
                                            <span className="text-(--text-danger)">
                                                <span data-technical>{entry.error_code}</span>
                                                {entry.error_message === null
                                                    ? null
                                                    : ` · ${entry.error_message}`}
                                            </span>
                                        )}
                                    </Td>
                                    <Td>
                                        <span
                                            title={
                                                absoluteTime(entry.created_at, locale) ?? undefined
                                            }
                                        >
                                            {relativeTime(entry.created_at, locale)}
                                        </span>
                                    </Td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>
        </Panel>
    );
}

function Th({ children }: { children: React.ReactNode }) {
    return (
        <th className="px-2 py-2 text-start align-bottom" scope="col">
            <span data-eyebrow>{children}</span>
        </th>
    );
}

function Td({ children }: { children: React.ReactNode }) {
    return (
        <td className="px-2 py-(--table-cell-padding-block) align-middle text-(length:--text-sm)">
            {children}
        </td>
    );
}
