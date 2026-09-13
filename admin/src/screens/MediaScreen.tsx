import { keepPreviousData, useQuery } from '@tanstack/react-query';
import {
    ChevronLeft,
    ChevronRight,
    FileText,
    Grid2x2,
    List,
    Music,
    Upload,
    Video,
} from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';

import { ApiError } from '@/api/errors';
import type { MediaStatus, MediaType } from '@/api/generated';
import { useCurrentUser } from '@/auth/AuthProvider';
import { cn } from '@/lib/cn';
import { absoluteTime, relativeTime } from '@/lib/time';
import { fileUrl, mediaPage, type AdminMediaFile } from '@/screens/media/api';
import { MediaDetail } from '@/screens/media/MediaDetail';
import { formatBytes, previewVerdict } from '@/screens/media/preview';
import { UploadPanel } from '@/screens/media/UploadPanel';
import { useDirection } from '@/shell/DirectionProvider';
import { Alert } from '@/ui/Alert';
import { Button } from '@/ui/Button';
import { SegmentedControl } from '@/ui/SegmentedControl';
import { StatusBadge } from '@/ui/StatusBadge';
import { TONE_CLASSES, type StateTone } from '@/ui/state';

/**
 * Every status and type the platform has, from the contract rather than from memory.
 *
 * Written out because a filter needs a list and the API publishes no endpoint that
 * returns one — but typed as the contract's own unions, so adding a case server-side
 * fails the typecheck here instead of silently leaving a filter that cannot reach it.
 */
const STATUSES: readonly MediaStatus[] = [
    'uploaded',
    'scanning',
    'processing',
    'ready',
    'scan_failed',
    'processing_failed',
];

const TYPES: readonly MediaType[] = ['image', 'video', 'audio', 'document'];

type View = 'grid' | 'list';

/**
 * The library: what is stored, what state it is in, and what may be removed.
 *
 * The two filters are the platform's own, so narrowing by status or by type is a
 * question the server answers over everything it holds. There is deliberately no search
 * box: `/admin/media` takes no term, and a box that filtered the twenty-five rows in
 * front of you while looking like it had asked the server would be a lie about where
 * the work happened — and would be most wrong exactly when the library is large enough
 * to need one.
 *
 * The page size is the server's, and there is no control for it, because the endpoint
 * takes no `per_page`.
 *
 * A grid is the right shape for images and the wrong one for a scan failure, so both
 * views exist and neither is the only one. The grid shows what a file looks like; the
 * list shows what state it is in.
 */
export function MediaScreen() {
    const { t } = useTranslation();
    const { locale } = useDirection();
    const viewer = useCurrentUser();

    const [page, setPage] = useState(1);
    const [status, setStatus] = useState<MediaStatus | 'all'>('all');
    const [type, setType] = useState<MediaType | 'all'>('all');
    const [view, setView] = useState<View>('grid');
    const [selected, setSelected] = useState<string | null>(null);
    const [uploading, setUploading] = useState(false);

    const mayDelete = viewer.permissions.includes('media.delete');

    const query = useQuery({
        queryKey: ['admin-media', page, status, type],
        queryFn: ({ signal }) =>
            mediaPage(
                {
                    page,
                    ...(status === 'all' ? {} : { status }),
                    ...(type === 'all' ? {} : { type }),
                },
                signal,
            ),
        // Paging keeps the previous page on screen while the next one arrives, so the
        // layout does not collapse and reflow on every click.
        placeholderData: keepPreviousData,
    });

    const rows = query.data?.rows ?? [];
    const pagination = query.data?.pagination ?? null;

    /** Any filter change starts again from the first page; page 4 of a new filter is nobody's question. */
    const narrow =
        <T,>(set: (value: T) => void) =>
        (value: T) => {
            set(value);
            setPage(1);
        };

    return (
        <div className="flex flex-col gap-(--section-gap)">
            <header className="flex flex-wrap items-end justify-between gap-3">
                <div>
                    <p data-eyebrow>{t('media.eyebrow')}</p>
                    <h1 className="text-(length:--text-2xl) text-(--text-primary)">
                        {t('modules.media')}
                    </h1>
                </div>

                <div className="flex flex-wrap items-center gap-2">
                    <SegmentedControl<View>
                        label={t('media.view')}
                        onChange={setView}
                        options={[
                            {
                                value: 'grid',
                                label: t('media.grid'),
                                icon: <Grid2x2 className="size-3.5" />,
                            },
                            {
                                value: 'list',
                                label: t('media.list'),
                                icon: <List className="size-3.5" />,
                            },
                        ]}
                        value={view}
                    />
                    <Button
                        onClick={() => {
                            setUploading(true);
                            setSelected(null);
                        }}
                        variant="secondary"
                    >
                        <Upload aria-hidden className="size-3.5" />
                        {t('media.upload.title')}
                    </Button>
                </div>
            </header>

            <div className="flex flex-wrap items-end gap-4 border border-(--border-default) bg-(--surface-default) px-3 py-2.5">
                <Filter
                    label={t('media.filters.type')}
                    onChange={narrow(setType)}
                    options={TYPES}
                    prefix="media.types"
                    value={type}
                />
                <Filter
                    label={t('media.filters.status')}
                    onChange={narrow(setStatus)}
                    options={STATUSES}
                    prefix="media.statuses"
                    value={status}
                />
                <p className="ms-auto text-(length:--text-sm) text-(--text-muted)">
                    {pagination === null
                        ? null
                        : t('media.filters.count', { count: pagination.total })}
                </p>
            </div>

            <div className="grid grid-cols-1 gap-(--section-gap) xl:grid-cols-[1fr_var(--panel-width-docked)]">
                <div className="flex min-w-0 flex-col gap-3">
                    {query.error !== null ? (
                        <Alert tone="danger">
                            <p>
                                {query.error instanceof ApiError
                                    ? query.error.message
                                    : t('state.error')}
                            </p>
                            <Button
                                className="mt-2"
                                onClick={() => void query.refetch()}
                                size="sm"
                                variant="secondary"
                            >
                                {t('state.retry')}
                            </Button>
                        </Alert>
                    ) : query.isPending ? (
                        <p className="border border-(--border-default) bg-(--surface-default) p-4 text-(--text-muted)">
                            {t('state.loading')}
                        </p>
                    ) : rows.length === 0 ? (
                        <div className="border border-(--border-default) bg-(--surface-default) p-6">
                            <p className="text-(length:--text-md) text-(--text-primary)">
                                {status === 'all' && type === 'all'
                                    ? t('media.empty.title')
                                    : t('media.empty.filtered')}
                            </p>
                            <p className="mt-1 max-w-prose text-(--text-muted)">
                                {status === 'all' && type === 'all'
                                    ? t('media.empty.body')
                                    : t('media.empty.filteredBody')}
                            </p>
                        </div>
                    ) : view === 'grid' ? (
                        <ul
                            className={cn(
                                'grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4',
                                query.isPlaceholderData ? 'opacity-60' : null,
                            )}
                        >
                            {rows.map((row) => (
                                <li key={row.id}>
                                    <Tile
                                        media={row}
                                        onSelect={() => {
                                            setSelected(row.id);
                                            setUploading(false);
                                        }}
                                        selected={selected === row.id}
                                        viewerEmail={viewer.email}
                                    />
                                </li>
                            ))}
                        </ul>
                    ) : (
                        <div
                            className={cn(
                                'border border-(--border-default) bg-(--surface-default)',
                                query.isPlaceholderData ? 'opacity-60' : null,
                            )}
                        >
                            <ul className="divide-y divide-(--border-default)">
                                {rows.map((row) => (
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
                                            onClick={() => {
                                                setSelected(row.id);
                                                setUploading(false);
                                            }}
                                            type="button"
                                        >
                                            <span
                                                aria-hidden
                                                className={cn(
                                                    'absolute inset-y-0 start-0 w-(--rail-width)',
                                                    TONE_CLASSES[statusTone(row.status)].rail,
                                                )}
                                            />
                                            <span className="block truncate font-medium text-(--text-primary)">
                                                {row.original_filename}
                                            </span>
                                            <span
                                                className="block text-(length:--text-xs) text-(--text-muted)"
                                                data-technical
                                            >
                                                {row.mime_type} ·{' '}
                                                {formatBytes(row.size_bytes, locale)} ·{' '}
                                                {row.collection}
                                            </span>
                                            <span className="mt-1 flex flex-wrap items-center gap-1.5">
                                                <StatusBadge tone={statusTone(row.status)}>
                                                    {row.status_label}
                                                </StatusBadge>
                                                <StatusBadge
                                                    tone={
                                                        row.visibility === 'public'
                                                            ? 'info'
                                                            : 'neutral'
                                                    }
                                                >
                                                    {row.visibility_label}
                                                </StatusBadge>
                                                <span
                                                    className="text-(length:--text-xs) text-(--text-muted)"
                                                    title={
                                                        absoluteTime(row.created_at, locale) ??
                                                        undefined
                                                    }
                                                >
                                                    {relativeTime(row.created_at, locale)}
                                                </span>
                                            </span>
                                        </button>
                                    </li>
                                ))}
                            </ul>
                        </div>
                    )}

                    {pagination !== null && pagination.last_page > 1 ? (
                        <nav
                            aria-label={t('media.pagination.label')}
                            className="flex flex-wrap items-center gap-2 border border-(--border-default) bg-(--surface-default) px-3 py-2"
                        >
                            <Button
                                disabled={pagination.current_page <= 1 || query.isFetching}
                                onClick={() => setPage((current) => Math.max(1, current - 1))}
                                size="sm"
                                variant="secondary"
                            >
                                <ChevronLeft aria-hidden className="size-3.5 rtl:rotate-180" />
                                {t('media.pagination.previous')}
                            </Button>
                            <span className="text-(length:--text-sm) text-(--text-secondary)">
                                {t('media.pagination.position', {
                                    page: pagination.current_page,
                                    pages: pagination.last_page,
                                })}
                            </span>
                            <Button
                                disabled={!pagination.has_more_pages || query.isFetching}
                                onClick={() => setPage((current) => current + 1)}
                                size="sm"
                                variant="secondary"
                            >
                                {t('media.pagination.next')}
                                <ChevronRight aria-hidden className="size-3.5 rtl:rotate-180" />
                            </Button>
                        </nav>
                    ) : null}
                </div>

                <aside aria-label={t('media.detail')} className="min-w-0">
                    {uploading ? (
                        <UploadPanel
                            onClose={() => setUploading(false)}
                            onUploaded={() => setPage(1)}
                        />
                    ) : selected === null ? (
                        <p className="border border-(--border-default) bg-(--surface-default) p-4 text-(--text-muted)">
                            {t('media.chooseFile')}
                        </p>
                    ) : (
                        <MediaDetail
                            id={selected}
                            key={selected}
                            mayDelete={mayDelete}
                            onClose={() => setSelected(null)}
                            onDeleted={() => setSelected(null)}
                            viewerEmail={viewer.email}
                        />
                    )}
                </aside>
            </div>
        </div>
    );
}

/**
 * One of the platform's own filters.
 *
 * A select rather than a segmented control: six statuses will not fit across a toolbar,
 * and a control that wraps to three rows stops being a toolbar.
 */
function Filter<T extends string>({
    label,
    value,
    options,
    prefix,
    onChange,
}: {
    label: string;
    value: T | 'all';
    options: readonly T[];
    /** Translation namespace holding a label per value. */
    prefix: string;
    onChange: (value: T | 'all') => void;
}) {
    const { t } = useTranslation();

    return (
        <label className="flex flex-col gap-1">
            <span className="text-(length:--text-sm) font-medium text-(--text-secondary)">
                {label}
            </span>
            <select
                className="h-(--field-height) border border-(--border-control) bg-(--surface-input) px-2 text-(length:--text-base) text-(--text-primary) focus-visible:outline-2 focus-visible:outline-offset-0 focus-visible:outline-(--focus-ring)"
                onChange={(event) => onChange(event.target.value as T | 'all')}
                value={value}
            >
                <option value="all">{t('media.filters.any')}</option>
                {options.map((option) => (
                    <option key={option} value={option}>
                        {t(`${prefix}.${option}`)}
                    </option>
                ))}
            </select>
        </label>
    );
}

const TYPE_ICONS: Record<string, React.ComponentType<{ className?: string }>> = {
    video: Video,
    audio: Music,
    document: FileText,
};

/** One file in the grid: what it looks like, or why it cannot be shown. */
function Tile({
    media,
    selected,
    viewerEmail,
    onSelect,
}: {
    media: AdminMediaFile;
    selected: boolean;
    viewerEmail: string | null;
    onSelect: () => void;
}) {
    const { t } = useTranslation();
    const { locale } = useDirection();
    const [failed, setFailed] = useState(false);
    const verdict = previewVerdict(media, viewerEmail);
    const Icon = TYPE_ICONS[media.type] ?? FileText;

    return (
        <button
            aria-current={selected}
            className={cn(
                'group relative flex w-full flex-col border text-start',
                'focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-(--focus-ring)',
                selected
                    ? 'border-(--border-focus) bg-(--action-secondary)'
                    : 'border-(--border-default) bg-(--surface-default) hover:border-(--border-strong)',
            )}
            onClick={onSelect}
            type="button"
        >
            <span
                aria-hidden
                className={cn(
                    'absolute inset-x-0 top-0 h-(--rail-width)',
                    TONE_CLASSES[statusTone(media.status)].rail,
                )}
            />

            <span className="flex aspect-4/3 items-center justify-center overflow-hidden bg-(--surface-subtle)">
                {verdict.readable && !failed ? (
                    <img
                        alt={media.original_filename}
                        className="size-full object-cover"
                        loading="lazy"
                        onError={() => setFailed(true)}
                        src={fileUrl(media.id)}
                    />
                ) : (
                    <span className="flex flex-col items-center gap-1 p-2 text-center">
                        <Icon aria-hidden className="size-6 text-(--text-muted)" />
                        <span className="text-(length:--text-2xs) text-(--text-muted)">
                            {failed && verdict.readable
                                ? t('media.preview.refused')
                                : t(
                                      `media.preview.${verdict.readable ? 'refused' : verdict.reason}`,
                                  )}
                        </span>
                    </span>
                )}
            </span>

            <span className="flex min-w-0 flex-col gap-1 p-2">
                <span className="truncate text-(length:--text-sm) font-medium text-(--text-primary)">
                    {media.original_filename}
                </span>
                <span className="text-(length:--text-2xs) text-(--text-muted)" data-technical>
                    {formatBytes(media.size_bytes, locale)} · {media.collection}
                </span>
                <span className="flex flex-wrap gap-1">
                    <StatusBadge tone={statusTone(media.status)}>{media.status_label}</StatusBadge>
                    <StatusBadge tone={media.visibility === 'public' ? 'info' : 'neutral'}>
                        {media.visibility_label}
                    </StatusBadge>
                </span>
            </span>
        </button>
    );
}

function statusTone(status: string): StateTone {
    switch (status) {
        case 'ready':
            return 'success';
        case 'scan_failed':
        case 'processing_failed':
            return 'danger';
        default:
            return 'pending';
    }
}
