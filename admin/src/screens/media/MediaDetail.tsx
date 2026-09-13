import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Trash2, X } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';

import { ApiError } from '@/api/errors';
import { absoluteTime, relativeTime } from '@/lib/time';
import { useDirection } from '@/shell/DirectionProvider';
import { Alert } from '@/ui/Alert';
import { Button } from '@/ui/Button';
import { StatusBadge } from '@/ui/StatusBadge';
import type { StateTone } from '@/ui/state';

import { deleteMedia, fileUrl, mediaFile } from './api';
import { formatBytes, previewVerdict } from './preview';

export interface MediaDetailProps {
    id: string;
    /** The signed-in account's address, for deciding whether it may read the bytes. */
    viewerEmail: string | null;
    mayDelete: boolean;
    onClose: () => void;
    onDeleted: () => void;
}

/**
 * One stored file, as an operator needs to see it.
 *
 * The record comes from the administrative endpoint, which carries the checksum, the
 * failure reason, the attachment target and the uploader's address — everything the
 * public representation deliberately leaves out. The bytes come from somewhere else
 * entirely, and may not come at all: the file route is gated by the media access
 * policy rather than by `media.view`, and an administrator has no special reach into
 * private media they did not upload. Where that applies the panel says so instead of
 * showing a broken image.
 */
export function MediaDetail({ id, viewerEmail, mayDelete, onClose, onDeleted }: MediaDetailProps) {
    const { t } = useTranslation();
    const { locale } = useDirection();
    const queryClient = useQueryClient();
    const [confirming, setConfirming] = useState(false);

    const record = useQuery({
        queryKey: ['admin-media-file', id],
        queryFn: ({ signal }) => mediaFile(id, signal),
    });

    const remove = useMutation({
        mutationFn: () => deleteMedia(id),
        onSuccess: async () => {
            await queryClient.invalidateQueries({ queryKey: ['admin-media'] });
            onDeleted();
        },
    });

    return (
        <div className="flex flex-col border border-(--border-default) bg-(--surface-default)">
            <header className="flex items-start justify-between gap-2 border-b border-(--border-default) px-3 py-2.5">
                <div className="min-w-0">
                    <p data-eyebrow>{t('media.detail')}</p>
                    <h2 className="truncate text-(length:--text-lg) text-(--text-primary)">
                        {record.data?.original_filename ?? t('state.loading')}
                    </h2>
                </div>
                <Button aria-label={t('media.close')} onClick={onClose} size="icon" variant="ghost">
                    <X aria-hidden className="size-4" />
                </Button>
            </header>

            {record.isPending ? (
                <p className="p-3 text-(--text-muted)">{t('state.loading')}</p>
            ) : record.error !== null ? (
                <div className="p-3">
                    <Alert tone="danger">
                        {record.error instanceof ApiError ? record.error.message : t('state.error')}
                    </Alert>
                </div>
            ) : record.data === undefined ? null : (
                <div className="flex flex-col gap-4 p-3">
                    <Preview media={record.data} mediaId={id} viewerEmail={viewerEmail} />

                    <div className="flex flex-wrap gap-1.5">
                        <StatusBadge tone={statusTone(record.data.status)}>
                            {record.data.status_label}
                        </StatusBadge>
                        <StatusBadge
                            tone={record.data.visibility === 'public' ? 'info' : 'neutral'}
                        >
                            {record.data.visibility_label}
                        </StatusBadge>
                        <StatusBadge tone={scanTone(record.data.scan_status)}>
                            {record.data.scan_status_label}
                        </StatusBadge>
                        <StatusBadge tone="neutral">{record.data.type_label}</StatusBadge>
                    </div>

                    {record.data.failure_reason === null ? null : (
                        <Alert title={t('media.failed')} tone="danger">
                            {record.data.failure_reason}
                        </Alert>
                    )}

                    <dl className="grid grid-cols-[auto_1fr] gap-x-4 gap-y-1 text-(length:--text-sm)">
                        <Row label={t('media.fields.collection')}>
                            <span data-technical>{record.data.collection}</span>
                        </Row>
                        <Row label={t('media.fields.mime')}>
                            <span data-technical>{record.data.mime_type}</span>
                        </Row>
                        <Row label={t('media.fields.size')}>
                            {formatBytes(record.data.size_bytes, locale)}
                        </Row>
                        <Row label={t('media.fields.uploader')}>
                            {record.data.uploaded_by ?? t('media.fields.noUploader')}
                        </Row>
                        <Row label={t('media.fields.uploaded')}>
                            <span title={absoluteTime(record.data.created_at, locale) ?? undefined}>
                                {relativeTime(record.data.created_at, locale)}
                            </span>
                        </Row>
                        <Row label={t('media.fields.attached')}>
                            {record.data.attachable_type === null ? (
                                t('media.fields.unattached')
                            ) : (
                                <span data-technical>
                                    {record.data.attachable_type}
                                    {record.data.attachable_id === null
                                        ? ''
                                        : ` · ${record.data.attachable_id}`}
                                </span>
                            )}
                        </Row>
                        <Row label={t('media.fields.checksum')}>
                            {/* Wrapped rather than truncated: a checksum is for
                                comparing, and half of one compares to nothing. */}
                            <span className="break-all" data-technical>
                                {record.data.checksum}
                            </span>
                        </Row>
                    </dl>

                    {mayDelete ? (
                        <section className="flex flex-col gap-2 border-t border-(--border-default) pt-3">
                            <h3 data-eyebrow>{t('media.removeSection')}</h3>

                            {/* The trigger stays where it is and stays disabled while
                                the confirmation is open, so the confirmation appears
                                *below* it rather than in its place. A confirm button
                                that lands under the pointer that opened it turns a
                                double-click into a deletion, and that is exactly what
                                browser testing of the first draft of this panel did. */}
                            <div>
                                <Button
                                    disabled={confirming}
                                    onClick={() => setConfirming(true)}
                                    size="sm"
                                    variant="secondary"
                                >
                                    <Trash2 aria-hidden className="size-3.5" />
                                    {t('media.remove')}
                                </Button>
                            </div>

                            {confirming ? (
                                <div className="flex flex-col gap-2 border-s-(length:--rail-width) border-(--state-danger-rail) ps-2">
                                    <p className="text-(length:--text-sm) text-(--state-danger-text)">
                                        {t('media.removeWarning')}
                                    </p>
                                    {remove.error instanceof ApiError ? (
                                        <Alert tone="danger">{remove.error.message}</Alert>
                                    ) : null}
                                    {/* Cancel first, and not as a matter of taste: it is
                                        the control nearest the trigger, so the cheapest
                                        mistake lands on the reversible action. */}
                                    <div className="flex flex-wrap gap-2">
                                        <Button
                                            onClick={() => setConfirming(false)}
                                            size="sm"
                                            variant="secondary"
                                        >
                                            {t('media.cancel')}
                                        </Button>
                                        <Button
                                            loading={remove.isPending}
                                            onClick={() => remove.mutate()}
                                            size="sm"
                                            variant="danger"
                                        >
                                            {t('media.removeConfirm')}
                                        </Button>
                                    </div>
                                </div>
                            ) : null}
                        </section>
                    ) : (
                        <p className="border-t border-(--border-default) pt-3 text-(length:--text-sm) text-(--text-muted)">
                            {t('media.removeDenied')}
                        </p>
                    )}

                    <p className="text-(length:--text-sm) text-(--text-muted)">
                        {t('media.noReplace')}
                    </p>
                </div>
            )}
        </div>
    );
}

function Row({ label, children }: { label: string; children: React.ReactNode }) {
    return (
        <>
            <dt className="text-(--text-muted)">{label}</dt>
            <dd className="min-w-0 text-(--text-primary)">{children}</dd>
        </>
    );
}

/**
 * The file itself, where this viewer may have it.
 *
 * The image is requested with the session cookie the browser already holds, because
 * the URL is same-origin and relative (ADR 0042). `onError` is not decoration: the
 * verdict below is this client's best reading of a decision the server owns, and a
 * private file attached to a type whose policy admits this viewer would be readable
 * while the verdict said otherwise — and the reverse is just as possible.
 */
function Preview({
    media,
    mediaId,
    viewerEmail,
}: {
    media: Parameters<typeof previewVerdict>[0];
    mediaId: string;
    viewerEmail: string | null;
}) {
    const { t } = useTranslation();
    const [failed, setFailed] = useState(false);
    const verdict = previewVerdict(media, viewerEmail);

    if (!verdict.readable || failed) {
        return (
            <div className="flex min-h-24 items-center justify-center border border-(--border-default) bg-(--surface-subtle) p-4">
                <p className="text-center text-(length:--text-sm) text-(--text-muted)">
                    {failed && verdict.readable
                        ? t('media.preview.refused')
                        : t(`media.preview.${verdict.readable ? 'refused' : verdict.reason}`)}
                </p>
            </div>
        );
    }

    return (
        <div className="flex items-center justify-center border border-(--border-default) bg-(--surface-subtle) p-2">
            <img
                alt={t('media.preview.alt')}
                className="max-h-64 w-auto max-w-full object-contain"
                onError={() => setFailed(true)}
                src={fileUrl(mediaId)}
            />
        </div>
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

function scanTone(status: string): StateTone {
    switch (status) {
        case 'clean':
            return 'success';
        case 'infected':
            return 'danger';
        case 'scan_error':
            return 'warning';
        default:
            return 'neutral';
    }
}
