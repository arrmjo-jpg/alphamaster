import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';

import { ApiError } from '@/api/errors';
import { useCurrentUser } from '@/auth/AuthProvider';
import { absoluteTime, relativeTime } from '@/lib/time';
import { useDirection } from '@/shell/DirectionProvider';
import { Alert } from '@/ui/Alert';
import { Button } from '@/ui/Button';
import { Panel } from '@/ui/Panel';

import { history, rollback, type HistoryRow } from './api';

const LIMIT = 20;

/**
 * What this group used to be, and the way back to it (ADR 0040).
 *
 * Rolling back is behind its own permission, not `settings.update`, because it
 * changes many values at once from a state the operator may not have inspected — and
 * it is the operation most likely to be run under pressure. So it asks first, and
 * says exactly which value it is restoring.
 */
export function HistoryPanel({ group, version }: { group: string; version: string }) {
    const { t } = useTranslation();
    const { locale } = useDirection();
    const user = useCurrentUser();
    const queryClient = useQueryClient();

    const [confirming, setConfirming] = useState<HistoryRow | null>(null);

    const mayRollBack = user.permissions.includes('settings.rollback');

    const rows = useQuery({
        queryKey: ['settings-history', group],
        queryFn: ({ signal }) => history(group, LIMIT, signal),
    });

    const restore = useMutation({
        mutationFn: (row: HistoryRow) => rollback(group, row.id, version),
        onSuccess: async () => {
            setConfirming(null);
            await queryClient.invalidateQueries({ queryKey: ['settings-group', group] });
            await queryClient.invalidateQueries({ queryKey: ['settings-history', group] });
        },
    });

    return (
        <Panel
            empty={rows.data?.length === 0}
            emptyMessage={t('settings.history.none')}
            error={rows.error}
            loading={rows.isPending}
            onRetry={() => void rows.refetch()}
            title={t('settings.history.title')}
        >
            <div className="flex flex-col gap-3">
                {restore.error instanceof ApiError ? (
                    <Alert tone="danger">{restore.error.message}</Alert>
                ) : null}

                {confirming !== null ? (
                    <Alert title={t('settings.history.confirmTitle')} tone="warning">
                        <p>
                            {t('settings.history.confirmBody', {
                                key: confirming.key,
                                value: formatValue(confirming.value),
                            })}
                        </p>
                        <div className="mt-2 flex gap-2">
                            <Button
                                loading={restore.isPending}
                                onClick={() => restore.mutate(confirming)}
                                size="sm"
                                variant="danger"
                            >
                                {t('settings.history.confirm')}
                            </Button>
                            <Button onClick={() => setConfirming(null)} size="sm" variant="ghost">
                                {t('settings.history.cancel')}
                            </Button>
                        </div>
                    </Alert>
                ) : null}

                <ul className="flex flex-col divide-y divide-(--border-default)">
                    {rows.data?.map((row) => (
                        <li className="flex items-center justify-between gap-3 py-1.5" key={row.id}>
                            <div className="flex min-w-0 flex-col">
                                <span className="truncate text-(--text-primary)" data-technical>
                                    {row.key}
                                    {row.locale !== null ? ` (${row.locale})` : ''}
                                </span>
                                <span className="truncate text-(length:--text-sm) text-(--text-muted)">
                                    {formatValue(row.value)}
                                </span>
                            </div>

                            <div className="flex shrink-0 items-center gap-2">
                                <span
                                    className="text-(length:--text-sm) text-(--text-muted)"
                                    title={absoluteTime(row.recorded_at, locale) ?? undefined}
                                >
                                    {relativeTime(row.recorded_at, locale)}
                                </span>

                                {mayRollBack ? (
                                    <Button
                                        onClick={() => setConfirming(row)}
                                        size="sm"
                                        variant="ghost"
                                    >
                                        {t('settings.history.restore')}
                                    </Button>
                                ) : null}
                            </div>
                        </li>
                    ))}
                </ul>
            </div>
        </Panel>
    );
}

/** A stored value as one readable line, whatever its type. */
function formatValue(value: unknown): string {
    if (value === null || value === undefined) {
        return '—';
    }

    if (typeof value === 'string') {
        return value;
    }

    return JSON.stringify(value);
}
