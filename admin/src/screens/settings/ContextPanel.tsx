import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';

import { ApiError } from '@/api/errors';
import { useCurrentUser } from '@/auth/AuthProvider';
import { absoluteTime, relativeTime } from '@/lib/time';
import { useDirection } from '@/shell/DirectionProvider';
import { Alert } from '@/ui/Alert';
import { Button } from '@/ui/Button';

import { group as fetchGroup, history, rollback, rollbackPreview, type HistoryRow } from './api';

const LIMIT = 30;

/**
 * What this group used to be, and the way back to it (ADR 0040).
 *
 * A timeline rather than a table: what an operator is reading is a sequence, and the
 * question is almost always "what changed most recently and can I undo it".
 *
 * Rolling back is behind its own permission, not `settings.update`, because it
 * changes many values at once from a state the operator may not have inspected — and
 * it is the operation most likely to be run under pressure. So it previews first. The
 * plan comes from the platform, not from this client guessing: what would be
 * restored, and what would be skipped with the reason beside it, which is the part
 * that matters — a rollback that silently left a credential behind would look like it
 * had restored the group.
 */
export function ContextPanel({ group }: { group: string }) {
    const { t } = useTranslation();
    const { locale } = useDirection();
    const user = useCurrentUser();
    const queryClient = useQueryClient();

    const [target, setTarget] = useState<HistoryRow | null>(null);

    const mayRollBack = user.permissions.includes('settings.rollback');

    // The same query key the workspace uses, so this shares its cache rather than
    // fetching the group a second time — and reads the very version a write would
    // carry, instead of being handed a copy that can go stale.
    const state = useQuery({
        queryKey: ['settings-group', group, locale],
        queryFn: ({ signal }) => fetchGroup(group, locale, signal),
    });

    const version = state.data?.version ?? '';

    const rows = useQuery({
        queryKey: ['settings-history', group],
        queryFn: ({ signal }) => history(group, LIMIT, signal),
    });

    // Only fetched once a revision is actually being considered. Previewing every row
    // in the timeline would be a request per row for a question nobody asked.
    const plan = useQuery({
        queryKey: ['rollback-preview', group, target?.id],
        queryFn: ({ signal }) => rollbackPreview(group, target?.id ?? '', signal),
        enabled: target !== null,
        retry: false,
    });

    const restore = useMutation({
        mutationFn: (row: HistoryRow) => rollback(group, row.id, version),
        onSuccess: async () => {
            setTarget(null);
            await queryClient.invalidateQueries({ queryKey: ['settings-group', group] });
            await queryClient.invalidateQueries({ queryKey: ['settings-history', group] });
        },
    });

    return (
        <div className="flex h-full flex-col">
            <header className="border-b border-(--border-default) px-3 py-2.5">
                <h2 className="text-(--text-secondary)" data-eyebrow>
                    {t('settings.history.title')}
                </h2>
            </header>

            {target !== null ? (
                <div className="border-b border-(--border-default) p-3">
                    <RollbackPreview
                        error={plan.error}
                        loading={plan.isPending}
                        onCancel={() => setTarget(null)}
                        onConfirm={() => restore.mutate(target)}
                        plan={plan.data}
                        saving={restore.isPending}
                        {...(restore.error instanceof ApiError
                            ? { failure: restore.error.message }
                            : {})}
                    />
                </div>
            ) : null}

            <div className="min-h-0 flex-1 overflow-y-auto">
                {rows.isPending ? (
                    <p className="p-3 text-(--text-muted)">{t('state.loading')}</p>
                ) : rows.data?.length === 0 ? (
                    <p className="p-3 text-(--text-muted)">{t('settings.history.none')}</p>
                ) : (
                    <ol className="relative px-3 py-2">
                        {rows.data?.map((row) => (
                            <li className="relative flex gap-3 pb-3 last:pb-0" key={row.id}>
                                {/* The spine of the timeline, drawn once per row so it
                                    stops at the last one rather than trailing off. */}
                                <span aria-hidden className="relative flex flex-col items-center">
                                    <span className="mt-1.5 size-1.5 rounded-full bg-(--state-neutral-rail)" />
                                    <span className="w-px flex-1 bg-(--border-default)" />
                                </span>

                                <div className="min-w-0 flex-1">
                                    <p
                                        className="truncate text-(length:--text-sm) text-(--text-primary)"
                                        data-technical
                                    >
                                        {row.key}
                                        {row.locale !== null ? ` · ${row.locale}` : ''}
                                    </p>
                                    <p className="truncate text-(length:--text-sm) text-(--text-muted)">
                                        {formatValue(row.value) ?? t('settings.notSet')}
                                    </p>
                                    <p className="mt-0.5 flex flex-wrap items-center gap-x-2 text-(length:--text-2xs) text-(--text-muted)">
                                        <span
                                            title={
                                                absoluteTime(row.recorded_at, locale) ?? undefined
                                            }
                                        >
                                            {relativeTime(row.recorded_at, locale)}
                                        </span>
                                        <span data-technical>v{row.version}</span>
                                        {row.actor_id !== null ? (
                                            <span data-technical title={row.actor_id}>
                                                {row.actor_id.slice(0, 8)}
                                            </span>
                                        ) : (
                                            <span>{t('settings.history.systemActor')}</span>
                                        )}
                                    </p>

                                    {mayRollBack ? (
                                        <Button
                                            className="mt-1"
                                            onClick={() => setTarget(row)}
                                            size="sm"
                                            variant="ghost"
                                        >
                                            {t('settings.history.preview')}
                                        </Button>
                                    ) : null}
                                </div>
                            </li>
                        ))}
                    </ol>
                )}
            </div>
        </div>
    );
}

function RollbackPreview({
    plan,
    loading,
    error,
    failure,
    saving,
    onConfirm,
    onCancel,
}: {
    plan: Awaited<ReturnType<typeof rollbackPreview>> | undefined;
    loading: boolean;
    error: unknown;
    failure?: string;
    saving: boolean;
    onConfirm: () => void;
    onCancel: () => void;
}) {
    const { t } = useTranslation();

    if (loading) {
        return <p className="text-(--text-muted)">{t('state.loading')}</p>;
    }

    if (error !== null && error !== undefined) {
        return (
            <Alert tone="danger">
                {error instanceof ApiError ? error.message : t('state.error')}
            </Alert>
        );
    }

    const restored = plan?.restored ?? [];
    const skipped = plan?.skipped ?? [];

    return (
        <div className="flex flex-col gap-3">
            <div>
                <p className="text-(--text-secondary)" data-eyebrow>
                    {t('settings.rollback.willRestore', { count: restored.length })}
                </p>
                <ul className="mt-1 flex flex-col gap-0.5">
                    {restored.map((change) => (
                        <li
                            className="text-(length:--text-sm) text-(--text-primary)"
                            key={`${change.key}-${change.locale ?? ''}`}
                        >
                            <span data-technical>{change.key}</span>{' '}
                            <span className="text-(--text-muted)">
                                → {formatValue(change.value) ?? t('settings.notSet')}
                            </span>
                        </li>
                    ))}
                </ul>
            </div>

            {skipped.length > 0 ? (
                <div className="border-s-(length:--rail-width) border-(--state-warning-rail) ps-2">
                    <p className="text-(--state-warning-text)" data-eyebrow>
                        {t('settings.rollback.willSkip', { count: skipped.length })}
                    </p>
                    <ul className="mt-1 flex flex-col gap-0.5">
                        {skipped.map((skip) => (
                            <li
                                className="text-(length:--text-sm)"
                                key={`${skip.key}-${skip.locale ?? ''}`}
                            >
                                <span className="text-(--text-primary)" data-technical>
                                    {skip.key}
                                </span>{' '}
                                {/* The reason a person reads, beside the identifier a
                                    client branches on (ADR 0031). */}
                                <span className="text-(--text-muted)">{skip.reason_label}</span>
                            </li>
                        ))}
                    </ul>
                </div>
            ) : null}

            {failure !== undefined ? <Alert tone="danger">{failure}</Alert> : null}

            <div className="flex flex-wrap gap-2">
                <Button
                    disabled={restored.length === 0}
                    loading={saving}
                    onClick={onConfirm}
                    size="sm"
                    variant="danger"
                >
                    {t('settings.rollback.confirm')}
                </Button>
                <Button disabled={saving} onClick={onCancel} size="sm" variant="ghost">
                    {t('settings.history.cancel')}
                </Button>
            </div>
        </div>
    );
}

function formatValue(value: unknown): string | null {
    if (value === null || value === undefined || value === '') {
        return null;
    }

    if (typeof value === 'string') {
        return value;
    }

    return JSON.stringify(value);
}
