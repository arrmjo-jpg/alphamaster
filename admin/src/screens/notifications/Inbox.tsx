import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Check, CheckCheck, ChevronLeft, ChevronRight } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';

import { ApiError } from '@/api/errors';
import { cn } from '@/lib/cn';
import { relativeTime } from '@/lib/time';
import { useDirection } from '@/shell/DirectionProvider';
import { Alert } from '@/ui/Alert';
import { Button } from '@/ui/Button';
import { StatusBadge } from '@/ui/StatusBadge';

import { inbox, markAllRead, markRead, type NotificationRecord } from './api';

/**
 * What the platform has told this account.
 *
 * The in-app record is the one channel a recipient cannot silence, because it is the
 * evidence a notification was raised at all (ADR 0019). Reading it is not
 * administrative and needs no permission: the endpoint is scoped to whoever is asking,
 * so there is nothing here to gate and nobody else's inbox to reach.
 *
 * The wording shown is the wording that was stored. A template is editable, and
 * re-rendering an old record against today's template would mean an operator
 * correcting a typo silently rewrote what somebody was told last week.
 *
 * There is no delete, and its absence is deliberate rather than unfinished: a
 * recipient who could remove a record could remove the evidence they were told. The
 * only state this screen changes is whether a record has been read.
 */
export function Inbox() {
    const { t } = useTranslation();
    const { locale } = useDirection();
    const queryClient = useQueryClient();

    const [page, setPage] = useState(1);
    const [unreadOnly, setUnreadOnly] = useState(false);

    const records = useQuery({
        queryKey: ['notification-inbox', page, unreadOnly],
        queryFn: ({ signal }) => inbox({ page, unread: unreadOnly }, signal),
    });

    const refresh = async () => {
        await queryClient.invalidateQueries({ queryKey: ['notification-inbox'] });
    };

    const readOne = useMutation({
        mutationFn: (id: string) => markRead(id),
        onSuccess: refresh,
    });

    const readEverything = useMutation({
        mutationFn: () => markAllRead(),
        onSuccess: refresh,
    });

    if (records.isPending) {
        return <p className="text-(--text-muted)">{t('state.loading')}</p>;
    }

    if (records.error !== null) {
        return (
            <Alert tone="danger">
                <p>
                    {records.error instanceof ApiError ? records.error.message : t('state.error')}
                </p>
                <Button
                    className="mt-2"
                    onClick={() => void records.refetch()}
                    size="sm"
                    variant="secondary"
                >
                    {t('state.retry')}
                </Button>
            </Alert>
        );
    }

    const { rows, pagination, unread } = records.data;

    return (
        <div className="flex min-w-0 flex-col gap-2">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <p className="text-(--text-secondary)">
                    {t('notifications.inbox.unread', { count: unread })}
                </p>

                <div className="flex flex-wrap gap-2">
                    {/* A filter, not a second list: the endpoint answers the narrowed
                        question, so the count below it stays true. */}
                    <Button
                        aria-pressed={unreadOnly}
                        onClick={() => {
                            setUnreadOnly((current) => !current);
                            setPage(1);
                        }}
                        size="sm"
                        variant={unreadOnly ? 'secondary' : 'ghost'}
                    >
                        {t('notifications.inbox.onlyUnread')}
                    </Button>

                    <Button
                        disabled={unread === 0}
                        loading={readEverything.isPending}
                        onClick={() => readEverything.mutate()}
                        size="sm"
                        variant="secondary"
                    >
                        <CheckCheck aria-hidden className="size-3.5" />
                        {t('notifications.inbox.readAll')}
                    </Button>
                </div>
            </div>

            {readEverything.error instanceof ApiError ? (
                <Alert tone="danger">{readEverything.error.message}</Alert>
            ) : null}

            {rows.length === 0 ? (
                <p className="border border-(--border-default) bg-(--surface-default) p-4 text-(--text-muted)">
                    {unreadOnly
                        ? t('notifications.inbox.noneUnread')
                        : t('notifications.inbox.none')}
                </p>
            ) : (
                <ul className="min-w-0 divide-y divide-(--border-default) border border-(--border-default) bg-(--surface-default)">
                    {rows.map((record) => (
                        <Record
                            key={record.id}
                            locale={locale}
                            onRead={() => readOne.mutate(record.id)}
                            pending={readOne.isPending && readOne.variables === record.id}
                            record={record}
                        />
                    ))}
                </ul>
            )}

            {pagination !== null && pagination.last_page > 1 ? (
                // The same shape the media library uses, down to the mirrored
                // chevrons: two screens that page through a list should not page
                // through it differently.
                <nav
                    aria-label={t('notifications.inbox.pagination.label')}
                    className="flex flex-wrap items-center gap-2 border border-(--border-default) bg-(--surface-default) px-3 py-2"
                >
                    <Button
                        disabled={pagination.current_page <= 1 || records.isFetching}
                        onClick={() => setPage((current) => Math.max(1, current - 1))}
                        size="sm"
                        variant="secondary"
                    >
                        <ChevronLeft aria-hidden className="size-3.5 rtl:rotate-180" />
                        {t('notifications.inbox.pagination.previous')}
                    </Button>
                    <span className="text-(length:--text-sm) text-(--text-secondary)">
                        {t('notifications.inbox.pagination.position', {
                            page: pagination.current_page,
                            pages: pagination.last_page,
                        })}
                    </span>
                    <Button
                        disabled={!pagination.has_more_pages || records.isFetching}
                        onClick={() => setPage((current) => current + 1)}
                        size="sm"
                        variant="secondary"
                    >
                        {t('notifications.inbox.pagination.next')}
                        <ChevronRight aria-hidden className="size-3.5 rtl:rotate-180" />
                    </Button>
                </nav>
            ) : null}
        </div>
    );
}

function Record({
    record,
    locale,
    pending,
    onRead,
}: {
    record: NotificationRecord;
    locale: string;
    pending: boolean;
    onRead: () => void;
}) {
    const { t } = useTranslation();
    const isUnread = record.read_at === null;

    return (
        <li className="relative flex min-w-0 flex-col gap-1 px-3 py-2.5 ps-4">
            {/* The same rail the rest of the console uses for state, and the only
                colour signal here — weight carries it too, so an unread record is
                distinguishable without relying on hue. */}
            <span
                aria-hidden
                className={cn(
                    'absolute inset-y-0 start-0 w-(--rail-width)',
                    isUnread ? 'bg-(--action-primary)' : 'bg-transparent',
                )}
            />

            <div className="flex flex-wrap items-baseline justify-between gap-2">
                <p
                    className={cn(
                        'min-w-0 text-(--text-primary)',
                        isUnread ? 'font-bold' : 'font-normal',
                    )}
                >
                    {record.subject}
                </p>
                <span className="shrink-0 text-(length:--text-xs) text-(--text-muted)">
                    {record.created_at === null ? null : relativeTime(record.created_at, locale)}
                </span>
            </div>

            <p className="max-w-prose text-(--text-secondary)">{record.body}</p>

            <div className="flex flex-wrap items-center gap-2">
                <StatusBadge tone={isUnread ? 'info' : 'neutral'}>
                    {record.type_label === ''
                        ? t('notifications.inbox.unknownType')
                        : record.type_label}
                </StatusBadge>

                {isUnread ? (
                    <Button loading={pending} onClick={onRead} size="sm" variant="ghost">
                        <Check aria-hidden className="size-3.5" />
                        {t('notifications.inbox.markRead')}
                    </Button>
                ) : (
                    <span className="text-(length:--text-xs) text-(--text-muted)">
                        {t('notifications.inbox.readAt', {
                            at: relativeTime(record.read_at ?? '', locale),
                        })}
                    </span>
                )}
            </div>
        </li>
    );
}
