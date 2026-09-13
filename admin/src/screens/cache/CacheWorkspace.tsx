import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';

import { ApiError } from '@/api/errors';
import { Alert } from '@/ui/Alert';
import { Button } from '@/ui/Button';
import { StatusBadge } from '@/ui/StatusBadge';

import { cacheNamespaces, flushCacheNamespace, type CacheNamespaceRow } from './api';

/**
 * The application cache, as an operator manages it (ADR 0035, ADR 0051 §7).
 *
 * One row per namespace — what each part of the platform caches, for how long, what it
 * does when the cache is down, and its current generation — and one action: invalidate
 * the namespace. The action asks first, because it cannot be taken back and it is
 * recorded, and it is absent for the two namespaces the platform refuses to invalidate
 * here rather than offered and then refused.
 */
export function CacheWorkspace({ mayInvalidate }: { mayInvalidate: boolean }) {
    const { t } = useTranslation();
    const queryClient = useQueryClient();
    const [confirming, setConfirming] = useState<string | null>(null);
    const [done, setDone] = useState<string | null>(null);

    const rows = useQuery({
        queryKey: ['cache-namespaces'],
        queryFn: ({ signal }) => cacheNamespaces(signal),
    });

    const invalidate = useMutation({
        mutationFn: (namespace: string) => flushCacheNamespace(namespace),
        onMutate: () => setDone(null),
        onSuccess: async (row) => {
            setConfirming(null);
            setDone(row.namespace);
            await queryClient.invalidateQueries({ queryKey: ['cache-namespaces'] });
        },
    });

    const label = (namespace: string) =>
        t(`cache.namespace.${namespace}`, { defaultValue: namespace });

    return (
        <div className="flex flex-col gap-4 p-4">
            <header>
                <p data-eyebrow>{t('cache.eyebrow')}</p>
                <h1 className="text-(length:--text-xl) text-(--text-primary)">
                    {t('cache.title')}
                </h1>
                <p className="mt-1 max-w-prose text-(length:--text-sm) text-(--text-muted)">
                    {t('cache.intro')}
                </p>
            </header>

            {!mayInvalidate ? <Alert tone="info">{t('cache.readOnly')}</Alert> : null}

            {done !== null ? (
                <Alert tone="success">{t('cache.done', { namespace: label(done) })}</Alert>
            ) : null}

            {invalidate.error instanceof ApiError ? (
                <Alert tone="danger">{invalidate.error.message}</Alert>
            ) : null}

            {rows.isPending ? (
                <p className="text-(--text-muted)">{t('state.loading')}</p>
            ) : rows.error !== null ? (
                <Alert tone="danger">
                    {rows.error instanceof ApiError ? rows.error.message : t('state.error')}
                </Alert>
            ) : (
                <ul className="flex flex-col border border-(--border-default) bg-(--surface-default)">
                    {rows.data.map((row: CacheNamespaceRow) => (
                        <li
                            className="flex flex-col gap-2 border-b border-(--border-subtle) p-3 last:border-b-0"
                            key={row.namespace}
                        >
                            <div className="flex flex-wrap items-center justify-between gap-2">
                                <div className="min-w-0">
                                    <p className="text-(--text-primary)">{label(row.namespace)}</p>
                                    <p
                                        className="text-(length:--text-sm) text-(--text-muted)"
                                        data-technical
                                    >
                                        {row.namespace}
                                    </p>
                                </div>

                                <div className="flex flex-wrap items-center gap-2">
                                    <StatusBadge
                                        tone={
                                            row.failure_mode === 'fail_closed'
                                                ? 'warning'
                                                : 'neutral'
                                        }
                                    >
                                        {row.failure_mode === 'fail_closed'
                                            ? t('cache.failClosed')
                                            : t('cache.failOpen')}
                                    </StatusBadge>

                                    {row.flushable ? (
                                        mayInvalidate ? (
                                            <Button
                                                disabled={invalidate.isPending}
                                                onClick={() => setConfirming(row.namespace)}
                                                size="sm"
                                                variant="secondary"
                                            >
                                                {t('cache.invalidate')}
                                            </Button>
                                        ) : null
                                    ) : (
                                        <span className="text-(length:--text-sm) text-(--text-muted)">
                                            {t('cache.protected')}
                                        </span>
                                    )}
                                </div>
                            </div>

                            <dl className="grid grid-cols-[auto_1fr] gap-x-4 gap-y-0.5 text-(length:--text-sm)">
                                <dt className="text-(--text-muted)">{t('cache.lifetime')}</dt>
                                <dd className="text-(--text-primary)" data-technical>
                                    {t('cache.seconds', { count: row.ttl_seconds })}
                                </dd>
                                <dt className="text-(--text-muted)">{t('cache.generation')}</dt>
                                <dd className="text-(--text-primary)" data-technical>
                                    {row.generation}
                                </dd>
                            </dl>

                            {confirming === row.namespace ? (
                                <div className="flex flex-col gap-2 border-s-(length:--rail-width) border-(--state-warning-rail) ps-2">
                                    <p className="text-(length:--text-sm) text-(--state-warning-text)">
                                        {t('cache.confirmBody', {
                                            namespace: label(row.namespace),
                                        })}
                                    </p>
                                    <div className="flex flex-wrap gap-2">
                                        <Button
                                            onClick={() => setConfirming(null)}
                                            size="sm"
                                            variant="secondary"
                                        >
                                            {t('cache.cancel')}
                                        </Button>
                                        <Button
                                            loading={invalidate.isPending}
                                            onClick={() => invalidate.mutate(row.namespace)}
                                            size="sm"
                                            variant="danger"
                                        >
                                            {t('cache.confirm')}
                                        </Button>
                                    </div>
                                </div>
                            ) : null}
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}
