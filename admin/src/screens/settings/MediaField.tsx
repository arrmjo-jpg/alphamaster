import { ImageOff, Upload, X } from 'lucide-react';
import { useQuery } from '@tanstack/react-query';
import { useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';

import { ApiError } from '@/api/errors';
import { Button } from '@/ui/Button';

import { mediaFile, uploadMedia, type MediaFile } from './api';

/** The platform's own bound: `file` is validated `max:102400`, in kilobytes. */
const MAX_BYTES = 102_400 * 1024;

export interface MediaFieldProps {
    /** The media id currently staged or stored, or null. */
    value: unknown;
    disabled: boolean;
    onChange: (mediaId: string | null) => void;
    id: string;
}

/**
 * A branding image: uploaded through the media API, stored as an id.
 *
 * The setting does not hold a file. It holds a media ULID, and the file belongs to
 * the media API — so this uploads, takes the id back, and stages that id into the
 * draft like any other value. The group write that follows is the ordinary atomic one
 * under `If-Match`, which is the whole point: there is deliberately no second save
 * path that could bypass validation, permissions or the precondition.
 *
 * Removing clears the setting, not the file. The media record stays where it is —
 * this control has no authority to delete somebody's upload, and the setting pointing
 * elsewhere is what was actually asked for.
 */
export function MediaField({ value, disabled, onChange, id }: MediaFieldProps) {
    const { t } = useTranslation();
    const input = useRef<HTMLInputElement>(null);
    const [uploading, setUploading] = useState(false);
    const [error, setError] = useState<string | null>(null);

    const mediaId = typeof value === 'string' && value !== '' ? value : null;

    // Fetched rather than assumed: the id is all the setting stores, so the filename
    // and the URL to preview come from the media record itself.
    const media = useQuery({
        queryKey: ['media', mediaId],
        queryFn: ({ signal }) => mediaFile(mediaId ?? '', signal),
        enabled: mediaId !== null,
        retry: false,
    });

    const accept = async (file: File | undefined) => {
        if (file === undefined) {
            return;
        }

        setError(null);

        // Checked here as well as by the platform, because a 100 MB round trip that
        // ends in a refusal is a minute of an operator's time for an answer that was
        // knowable immediately.
        if (file.size > MAX_BYTES) {
            setError(t('settings.media.tooLarge'));

            return;
        }

        if (!file.type.startsWith('image/')) {
            setError(t('settings.media.notAnImage'));

            return;
        }

        setUploading(true);

        try {
            const uploaded = await uploadMedia(file);
            onChange(uploaded.id);
        } catch (caught) {
            setError(caught instanceof ApiError ? caught.message : t('state.error'));
        } finally {
            setUploading(false);
        }
    };

    return (
        <div className="flex flex-col gap-2">
            <div className="flex items-start gap-3">
                <Preview file={media.data} loading={media.isPending && mediaId !== null} />

                <div className="flex min-w-0 flex-1 flex-col gap-2">
                    {mediaId !== null ? (
                        <div className="min-w-0">
                            <p className="truncate text-(length:--text-sm) text-(--text-primary)">
                                {media.data?.original_filename ?? t('settings.media.stored')}
                            </p>
                            <p
                                className="truncate text-(length:--text-xs) text-(--text-muted)"
                                data-technical
                            >
                                {mediaId}
                            </p>
                        </div>
                    ) : (
                        <p className="text-(length:--text-sm) text-(--text-muted)">
                            {t('settings.media.none')}
                        </p>
                    )}

                    <div className="flex flex-wrap gap-2">
                        <Button
                            disabled={disabled}
                            loading={uploading}
                            onClick={() => input.current?.click()}
                            size="sm"
                            variant="secondary"
                        >
                            <Upload aria-hidden className="size-3.5" />
                            {mediaId === null
                                ? t('settings.media.upload')
                                : t('settings.media.replace')}
                        </Button>

                        {mediaId !== null ? (
                            <Button
                                disabled={disabled}
                                onClick={() => onChange(null)}
                                size="sm"
                                variant="ghost"
                            >
                                <X aria-hidden className="size-3.5" />
                                {t('settings.media.remove')}
                            </Button>
                        ) : null}
                    </div>
                </div>
            </div>

            <input
                accept="image/*"
                className="sr-only"
                disabled={disabled}
                id={id}
                onChange={(event) => {
                    void accept(event.target.files?.[0]);
                    // Cleared so choosing the same file twice still fires a change.
                    event.target.value = '';
                }}
                ref={input}
                type="file"
            />

            {error !== null ? (
                <p className="text-(length:--text-sm) text-(--text-danger)" role="alert">
                    {error}
                </p>
            ) : null}
        </div>
    );
}

function Preview({ file, loading }: { file: MediaFile | undefined; loading: boolean }) {
    const { t } = useTranslation();

    return (
        <div className="flex size-20 shrink-0 items-center justify-center border border-(--border-default) bg-(--surface-subtle)">
            {loading ? (
                <span className="size-full animate-pulse bg-(--action-secondary)" />
            ) : file?.url != null && file.url !== '' ? (
                // The URL the media API hands back. No storage path is constructed
                // here, and none is exposed.
                <img
                    alt={file.original_filename}
                    className="size-full object-contain"
                    src={file.url}
                />
            ) : (
                <ImageOff aria-hidden className="size-5 text-(--text-muted)" />
            )}
            <span className="sr-only">{t('settings.media.preview')}</span>
        </div>
    );
}
