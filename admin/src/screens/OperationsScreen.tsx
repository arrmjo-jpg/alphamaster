import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Archive, ChevronLeft, ChevronRight } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';

import { ApiError } from '@/api/errors';
import { useCurrentUser } from '@/auth/AuthProvider';
import { cn } from '@/lib/cn';
import { relativeTime, absoluteTime } from '@/lib/time';
import { archiveAudit, auditPage } from '@/screens/operations/api';
import { AuditDetail } from '@/screens/operations/AuditDetail';
import { AuditFilters } from '@/screens/operations/AuditFilters';
import { EMPTY_QUERY, type AuditQuery } from '@/screens/operations/query';
import { ConfigurationPanel } from '@/screens/operations/ConfigurationPanel';
import { useDirection } from '@/shell/DirectionProvider';
import { Alert } from '@/ui/Alert';
import { Button } from '@/ui/Button';
import { StatusBadge } from '@/ui/StatusBadge';

const PER_PAGE = 25;

/** A `datetime-local` value is local wall time; the endpoint compares ISO instants. */
function toInstant(value: string): string | undefined {
    if (value === '') {
        return undefined;
    }

    const parsed = new Date(value);

    return Number.isNaN(parsed.getTime()) ? undefined : parsed.toISOString();
}

/**
 * The administrative trail, and moving configuration across this deployment's edge.
 *
 * The trail is the reason this screen exists. `audit.view` has been in the catalogue
 * since Phase 16A and the dashboard shows the last few entries; everything else about
 * it — who did that, what happened to this setting, what failed on Tuesday — needed a
 * place to be asked. Every filter here is one the endpoint takes, so the answer is
 * drawn from the whole trail rather than from the page in front of you.
 *
 * Configuration *history* is deliberately absent. The settings workspace already owns
 * per-group history and rollback (ADR 0040), and a second history assembled from audit
 * records would be a second answer to the same question that disagreed with the first
 * the moment either changed.
 *
 * Archiving is here rather than on a schedule because that is how the platform has it:
 * a removal with an author, not a silent periodic cleanup that is indistinguishable,
 * from outside, from evidence disappearing.
 */
export function OperationsScreen() {
    const { t } = useTranslation();
    const { locale } = useDirection();
    const viewer = useCurrentUser();
    const queryClient = useQueryClient();

    const mayReadAudit = viewer.permissions.includes('audit.view');
    const mayArchive = viewer.permissions.includes('audit.manage');
    const mayMoveConfiguration = viewer.permissions.includes('settings.backup.manage');

    const [query, setQuery] = useState<AuditQuery>(EMPTY_QUERY);
    const [page, setPage] = useState(1);
    const [selected, setSelected] = useState<string | null>(null);
    const [archiving, setArchiving] = useState(false);

    const from = toInstant(query.from);
    const to = toInstant(query.to);

    const trail = useQuery({
        queryKey: ['admin-audit', page, query],
        queryFn: ({ signal }) =>
            auditPage(
                {
                    page,
                    per_page: PER_PAGE,
                    ...(query.action === '' ? {} : { action: query.action }),
                    ...(query.subject === '' ? {} : { subject: query.subject }),
                    ...(query.actorId === '' ? {} : { actor_id: query.actorId }),
                    ...(query.outcome === '' ? {} : { outcome: query.outcome }),
                    // Read once each: `exactOptionalPropertyTypes` distinguishes an
                    // absent key from one holding `undefined`, and calling the
                    // converter inside the spread would put the second sort there.
                    ...(from === undefined ? {} : { from }),
                    ...(to === undefined ? {} : { to }),
                },
                signal,
            ),
        enabled: mayReadAudit,
        placeholderData: keepPreviousData,
    });

    const archive = useMutation({
        mutationFn: archiveAudit,
        onSuccess: async () => {
            setArchiving(false);
            await queryClient.invalidateQueries({ queryKey: ['admin-audit'] });
        },
    });

    const records = trail.data?.records ?? [];
    const pagination = trail.data?.pagination ?? null;
    const record = records.find((row) => row.id === selected) ?? null;

    /** Narrowing always restarts at the first page; page four of a new question is nobody's. */
    const narrow = (next: AuditQuery) => {
        setQuery(next);
        setPage(1);
    };

    const knownActions = [
        ...new Map(records.map((row) => [row.action, row.action_label])).entries(),
    ].map(([value, label]) => ({ value, label }));

    return (
        <div className="flex min-w-0 flex-col gap-(--section-gap)">
            <header>
                <p data-eyebrow>{t('operations.eyebrow')}</p>
                <h1 className="text-(length:--text-2xl) text-(--text-primary)">
                    {t('modules.operations')}
                </h1>
            </header>

            {mayReadAudit ? (
                <section className="flex min-w-0 flex-col gap-2">
                    <div className="flex flex-wrap items-end justify-between gap-3">
                        <div>
                            <h2 data-eyebrow>{t('operations.audit.region')}</h2>
                            <p className="max-w-prose text-(--text-secondary)">
                                {t('operations.audit.intro')}
                            </p>
                        </div>

                        {mayArchive ? (
                            <Button
                                disabled={archiving}
                                onClick={() => setArchiving(true)}
                                variant="secondary"
                            >
                                <Archive aria-hidden className="size-3.5" />
                                {t('operations.audit.archive.action')}
                            </Button>
                        ) : null}
                    </div>

                    {archiving ? (
                        <div className="flex flex-col gap-2 border-s-(length:--rail-width) border-(--state-danger-rail) bg-(--surface-default) p-3 ps-3">
                            <p className="text-(length:--text-sm) text-(--state-danger-text)">
                                {t('operations.audit.archive.warning')}
                            </p>
                            <div className="flex flex-wrap gap-2">
                                <Button
                                    onClick={() => setArchiving(false)}
                                    size="sm"
                                    variant="secondary"
                                >
                                    {t('operations.cancel')}
                                </Button>
                                <Button
                                    loading={archive.isPending}
                                    onClick={() => archive.mutate()}
                                    size="sm"
                                    variant="danger"
                                >
                                    {t('operations.audit.archive.confirm')}
                                </Button>
                            </div>
                        </div>
                    ) : null}

                    {archive.error instanceof ApiError ? (
                        <Alert title={t('operations.audit.archive.failed')} tone="danger">
                            {archive.error.message}
                        </Alert>
                    ) : null}

                    {archive.data !== undefined ? (
                        <Alert title={t('operations.audit.archive.done')} tone="success">
                            <p>
                                {t('operations.audit.archive.moved', {
                                    count: archive.data.count,
                                })}
                            </p>
                            <p className="break-all" data-technical>
                                {archive.data.location}
                            </p>
                        </Alert>
                    ) : null}

                    <AuditFilters knownActions={knownActions} onChange={narrow} value={query} />

                    {trail.error !== null ? (
                        <Alert tone="danger">
                            <p>
                                {trail.error instanceof ApiError
                                    ? trail.error.message
                                    : t('state.error')}
                            </p>
                            <Button
                                className="mt-2"
                                onClick={() => void trail.refetch()}
                                size="sm"
                                variant="secondary"
                            >
                                {t('state.retry')}
                            </Button>
                        </Alert>
                    ) : trail.isPending ? (
                        <p className="border border-(--border-default) bg-(--surface-default) p-4 text-(--text-muted)">
                            {t('state.loading')}
                        </p>
                    ) : (
                        <div className="grid min-w-0 grid-cols-1 gap-(--section-gap) xl:grid-cols-[1fr_var(--panel-width-docked)]">
                            <div className="flex min-w-0 flex-col gap-3">
                                <div
                                    className={cn(
                                        'min-w-0 border border-(--border-default) bg-(--surface-default)',
                                        trail.isPlaceholderData ? 'opacity-60' : null,
                                    )}
                                >
                                    {records.length === 0 ? (
                                        <div className="p-6">
                                            <p className="text-(length:--text-md) text-(--text-primary)">
                                                {t('operations.audit.empty.title')}
                                            </p>
                                            <p className="mt-1 max-w-prose text-(--text-muted)">
                                                {t('operations.audit.empty.body')}
                                            </p>
                                        </div>
                                    ) : (
                                        <ul className="divide-y divide-(--border-default)">
                                            {records.map((row) => (
                                                <li key={row.id}>
                                                    <button
                                                        aria-current={selected === row.id}
                                                        className={cn(
                                                            'relative w-full px-3 py-2.5 ps-4 text-start',
                                                            'focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-(--focus-ring)',
                                                            selected === row.id
                                                                ? 'bg-(--action-secondary)'
                                                                : 'hover:bg-(--action-ghost-hover)',
                                                        )}
                                                        onClick={() => setSelected(row.id)}
                                                        type="button"
                                                    >
                                                        <span
                                                            aria-hidden
                                                            className={cn(
                                                                'absolute inset-y-0 start-0 w-(--rail-width)',
                                                                row.outcome === 'failed'
                                                                    ? 'bg-(--state-danger-rail)'
                                                                    : 'bg-(--state-success-rail)',
                                                            )}
                                                        />
                                                        <span className="flex flex-wrap items-baseline justify-between gap-x-3">
                                                            <span className="font-medium text-(--text-primary)">
                                                                {row.action_label}
                                                            </span>
                                                            <span
                                                                className="text-(length:--text-xs) text-(--text-muted)"
                                                                title={
                                                                    absoluteTime(
                                                                        row.created_at,
                                                                        locale,
                                                                    ) ?? undefined
                                                                }
                                                            >
                                                                {relativeTime(
                                                                    row.created_at,
                                                                    locale,
                                                                )}
                                                            </span>
                                                        </span>
                                                        <span
                                                            className="mt-0.5 block truncate text-(length:--text-xs) text-(--text-muted)"
                                                            data-technical
                                                        >
                                                            {row.subject ??
                                                                t(
                                                                    'operations.audit.fields.noSubject',
                                                                )}
                                                        </span>
                                                        {row.outcome === 'failed' ? (
                                                            <span className="mt-1 inline-flex">
                                                                <StatusBadge tone="danger">
                                                                    {t(
                                                                        'operations.audit.outcome.failed',
                                                                    )}
                                                                </StatusBadge>
                                                            </span>
                                                        ) : null}
                                                    </button>
                                                </li>
                                            ))}
                                        </ul>
                                    )}
                                </div>

                                {pagination !== null ? (
                                    <nav
                                        aria-label={t('operations.audit.pagination.label')}
                                        className="flex flex-wrap items-center gap-2 border border-(--border-default) bg-(--surface-default) px-3 py-2"
                                    >
                                        <Button
                                            disabled={
                                                pagination.current_page <= 1 || trail.isFetching
                                            }
                                            onClick={() =>
                                                setPage((current) => Math.max(1, current - 1))
                                            }
                                            size="sm"
                                            variant="secondary"
                                        >
                                            <ChevronLeft
                                                aria-hidden
                                                className="size-3.5 rtl:rotate-180"
                                            />
                                            {t('operations.audit.pagination.previous')}
                                        </Button>
                                        <span className="text-(length:--text-sm) text-(--text-secondary)">
                                            {t('operations.audit.pagination.position', {
                                                page: pagination.current_page,
                                                pages: Math.max(pagination.last_page, 1),
                                                total: pagination.total,
                                            })}
                                        </span>
                                        <Button
                                            disabled={
                                                !pagination.has_more_pages || trail.isFetching
                                            }
                                            onClick={() => setPage((current) => current + 1)}
                                            size="sm"
                                            variant="secondary"
                                        >
                                            {t('operations.audit.pagination.next')}
                                            <ChevronRight
                                                aria-hidden
                                                className="size-3.5 rtl:rotate-180"
                                            />
                                        </Button>
                                    </nav>
                                ) : null}
                            </div>

                            <aside aria-label={t('operations.audit.entry')} className="min-w-0">
                                {record === null ? (
                                    <p className="border border-(--border-default) bg-(--surface-default) p-4 text-(--text-muted)">
                                        {t('operations.audit.choose')}
                                    </p>
                                ) : (
                                    <AuditDetail
                                        key={record.id}
                                        onClose={() => setSelected(null)}
                                        onFilter={(patch) =>
                                            narrow({
                                                ...EMPTY_QUERY,
                                                ...(patch.action === undefined
                                                    ? {}
                                                    : { action: patch.action }),
                                                ...(patch.subject === undefined
                                                    ? {}
                                                    : { subject: patch.subject }),
                                                ...(patch.actorId === undefined
                                                    ? {}
                                                    : { actorId: patch.actorId }),
                                            })
                                        }
                                        record={record}
                                    />
                                )}
                            </aside>
                        </div>
                    )}
                </section>
            ) : null}

            {mayMoveConfiguration ? (
                <section className="flex min-w-0 flex-col gap-2">
                    <h2 data-eyebrow>{t('operations.configuration.region')}</h2>
                    <p className="max-w-prose text-(--text-secondary)">
                        {t('operations.configuration.intro')}
                    </p>
                    <ConfigurationPanel />
                </section>
            ) : null}

            <section className="flex min-w-0 flex-col gap-2">
                <h2 data-eyebrow>{t('operations.history.region')}</h2>
                <div className="border border-(--border-default) bg-(--surface-default) p-4">
                    <p className="max-w-prose text-(--text-secondary)">
                        {t('operations.history.elsewhere')}
                    </p>
                </div>
            </section>
        </div>
    );
}
