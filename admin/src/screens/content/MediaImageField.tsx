import { useQuery } from '@tanstack/react-query';
import { ImagePlus } from 'lucide-react';
import { useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';

import { ApiError } from '@/api/errors';
import { useCurrentUser } from '@/auth/AuthProvider';
import { formatBytes } from '@/screens/media/preview';
import { useDirection } from '@/shell/DirectionProvider';
import { Alert } from '@/ui/Alert';
import { Button } from '@/ui/Button';

import { libraryImages, mediaRecord, uploadPublicImage, waitForImage } from './images';

/** The ceiling the avatar rule uses, and a sensible one for any image content shows. */
const MAX_BYTES = 5 * 1024 * 1024;

export interface MediaImageFieldProps {
    label: string;
    hint?: string;
    /** The id the content currently refers to, or null. */
    mediaId: string | null;
    /** The URL the content's own read already resolved, when it has one. */
    previewUrl?: string | null;
    /** The library collection an upload lands in. */
    collection: string;
    disabled?: boolean;
    /** A save of the choice is in flight. */
    busy?: boolean;
    onChange: (mediaId: string | null) => void;
    /** How often to ask whether an upload is ready. Tests shorten it. */
    pollIntervalMs?: number;
}

/**
 * One image a piece of content refers to, chosen by upload or from the library (ADR 0057 §4).
 *
 * The same field for every consumer — a team member's picture, a page's sharing image, and
 * whatever a later module attaches — so there is one way content gets an image, and it is the
 * platform's media capability rather than an upload path per screen. The field hands its editor
 * an id only once the image is ready to serve, which is the rule the save enforces.
 *
 * The library is offered only to an account that may read it (`media.view`). Uploading needs no
 * media permission, because media is a platform capability; editing the content still needs the
 * content's own permission, which the editor already decides through `disabled`.
 */
export function MediaImageField({
    label,
    hint,
    mediaId,
    previewUrl,
    collection,
    disabled = false,
    busy = false,
    onChange,
    pollIntervalMs = 1500,
}: MediaImageFieldProps) {
    const { t } = useTranslation();
    const { locale } = useDirection();
    const viewer = useCurrentUser();
    const input = useRef<HTMLInputElement>(null);

    const [uploading, setUploading] = useState(false);
    const [notice, setNotice] = useState<{ tone: 'danger' | 'info'; text: string } | null>(null);
    const [libraryOpen, setLibraryOpen] = useState(false);

    const mayBrowse = viewer.permissions.includes('media.view');

    // The editor's read may already carry the URL; only an id without one is looked up.
    const record = useQuery({
        queryKey: ['media-record', mediaId],
        queryFn: ({ signal }) => mediaRecord(mediaId ?? '', signal),
        enabled: mediaId !== null && previewUrl === undefined,
    });

    const library = useQuery({
        queryKey: ['image-library'],
        queryFn: ({ signal }) => libraryImages(signal),
        enabled: libraryOpen && mayBrowse,
    });

    const shown = mediaId === null ? null : previewUrl !== undefined ? previewUrl : (record.data?.url ?? null);

    const pick = async (file: File | undefined): Promise<void> => {
        if (input.current !== null) {
            input.current.value = '';
        }

        if (file === undefined) {
            return;
        }

        if (file.size > MAX_BYTES) {
            setNotice({ tone: 'danger', text: t('image.tooLarge', { size: formatBytes(MAX_BYTES, locale) }) });

            return;
        }

        setUploading(true);
        setNotice({ tone: 'info', text: t('image.processing') });

        try {
            const accepted = await uploadPublicImage(file, collection);
            const { readiness, record: settled } = await waitForImage(accepted, {
                intervalMs: pollIntervalMs,
                attempts: 40,
            });

            if (readiness === 'ready') {
                setNotice(null);
                onChange(settled.id);
            } else {
                setNotice({
                    tone: readiness === 'failed' ? 'danger' : 'info',
                    text: readiness === 'failed' ? t('image.failed') : t('image.stillProcessing'),
                });
            }
        } catch (caught) {
            if (!(caught instanceof ApiError)) {
                throw caught;
            }

            setNotice({
                tone: 'danger',
                text: caught.validationDetails?.['file']?.[0] ?? caught.message,
            });
        } finally {
            setUploading(false);
        }
    };

    const locked = disabled || uploading || busy;

    return (
        <div className="flex flex-col gap-2">
            <span className="text-(length:--text-sm) font-medium text-(--text-primary)">{label}</span>
            {hint !== undefined ? (
                <span className="text-(length:--text-xs) text-(--text-muted)">{hint}</span>
            ) : null}

            <div className="flex flex-wrap items-start gap-3">
                <div className="flex size-20 shrink-0 items-center justify-center overflow-hidden border border-(--border-default) bg-(--surface-default)">
                    {shown !== null ? (
                        <img alt={label} className="size-full object-cover" src={shown} />
                    ) : (
                        <ImagePlus aria-label={t('image.none')} className="size-5 text-(--text-muted)" />
                    )}
                </div>

                {disabled ? null : (
                    <div className="flex flex-col items-start gap-1.5">
                        <input
                            accept="image/jpeg,image/png,image/webp"
                            aria-label={t('image.upload')}
                            className="hidden"
                            onChange={(event) => void pick(event.target.files?.[0])}
                            ref={input}
                            type="file"
                        />
                        <Button
                            disabled={locked}
                            loading={uploading}
                            onClick={() => input.current?.click()}
                            size="sm"
                            type="button"
                            variant="secondary"
                        >
                            {t('image.upload')}
                        </Button>
                        {mayBrowse ? (
                            <Button
                                disabled={locked}
                                onClick={() => setLibraryOpen((open) => !open)}
                                size="sm"
                                type="button"
                                variant="ghost"
                            >
                                {libraryOpen ? t('image.close') : t('image.library')}
                            </Button>
                        ) : null}
                        {mediaId !== null ? (
                            <Button
                                disabled={locked}
                                onClick={() => onChange(null)}
                                size="sm"
                                type="button"
                                variant="ghost"
                            >
                                {t('image.remove')}
                            </Button>
                        ) : null}
                    </div>
                )}
            </div>

            {notice !== null ? <Alert tone={notice.tone}>{notice.text}</Alert> : null}

            {libraryOpen && mayBrowse ? (
                <section
                    aria-label={t('image.libraryTitle')}
                    className="flex flex-col gap-2 border border-(--border-default) p-2"
                >
                    <p data-eyebrow>{t('image.libraryTitle')}</p>
                    {library.isError ? <Alert tone="danger">{t('image.libraryUnavailable')}</Alert> : null}
                    {library.data !== undefined && library.data.length === 0 ? (
                        <p className="text-(length:--text-xs) text-(--text-muted)">{t('image.libraryEmpty')}</p>
                    ) : null}
                    <div className="grid grid-cols-[repeat(auto-fill,minmax(4.5rem,1fr))] gap-2">
                        {(library.data ?? []).map((image) => (
                            <button
                                aria-label={image.original_filename}
                                aria-pressed={image.id === mediaId}
                                className="aspect-square overflow-hidden border border-(--border-default) aria-pressed:border-(--action-primary)"
                                disabled={locked}
                                key={image.id}
                                onClick={() => {
                                    setLibraryOpen(false);
                                    setNotice(null);
                                    onChange(image.id);
                                }}
                                type="button"
                            >
                                {image.url !== null ? (
                                    <img alt="" className="size-full object-cover" src={image.url} />
                                ) : null}
                            </button>
                        ))}
                    </div>
                </section>
            ) : null}

            <span className="text-(length:--text-xs) text-(--text-muted)">{t('image.publicNote')}</span>
        </div>
    );
}
