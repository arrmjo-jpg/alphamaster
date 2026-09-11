import { useMutation, useQueryClient } from '@tanstack/react-query';
import { Upload as UploadIcon, X } from 'lucide-react';
import { useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';

import { ApiError } from '@/api/errors';
import { useDirection } from '@/shell/DirectionProvider';
import { Alert } from '@/ui/Alert';
import { Button } from '@/ui/Button';
import { Field } from '@/ui/Field';
import { Input } from '@/ui/Input';
import { SegmentedControl } from '@/ui/SegmentedControl';

import { uploadMedia } from './api';
import { formatBytes } from './preview';

/** The platform's own ceiling, from `StoreMediaRequest`: `max:102400` kilobytes. */
const MAX_BYTES = 102_400 * 1024;

/** The platform's own rule for a collection name. */
const COLLECTION = /^[a-z][a-z0-9_]*$/;

export interface UploadPanelProps {
    onClose: () => void;
    onUploaded: () => void;
}

/**
 * Put a file into the library.
 *
 * Not an administrative endpoint: media is a platform capability, so this is the same
 * `POST /media` any signed-in account uses. An administrator uploading here is
 * uploading as themselves, and the record will say so.
 *
 * The size and the collection pattern are checked before the request because the
 * platform checks them after it, and a hundred-megabyte round trip that ends in a 422
 * is a poor way to learn about a limit. Neither check replaces the server's: what the
 * file actually *is* gets decided from its bytes at the other end, and nothing here
 * inspects or claims a type.
 */
export function UploadPanel({ onClose, onUploaded }: UploadPanelProps) {
    const { t } = useTranslation();
    const { locale } = useDirection();
    const queryClient = useQueryClient();
    const input = useRef<HTMLInputElement>(null);

    const [file, setFile] = useState<File | null>(null);
    const [collection, setCollection] = useState('default');
    const [visibility, setVisibility] = useState<'public' | 'private'>('public');

    const send = useMutation({
        // Takes the file rather than reading it off state, so the type is narrowed by
        // the caller that already knows there is one instead of asserted here.
        mutationFn: (chosen: File) => uploadMedia({ file: chosen, collection, visibility }),
        onSuccess: async () => {
            setFile(null);

            if (input.current !== null) {
                input.current.value = '';
            }

            await queryClient.invalidateQueries({ queryKey: ['admin-media'] });
            onUploaded();
        },
    });

    const tooLarge = file !== null && file.size > MAX_BYTES;
    const collectionValid = COLLECTION.test(collection);
    const ready = file !== null && !tooLarge && collectionValid && !send.isPending;

    const rejection =
        send.error instanceof ApiError
            ? (send.error.validationDetails?.['file']?.[0] ?? send.error.message)
            : null;

    return (
        <div className="flex flex-col border border-(--border-default) bg-(--surface-default)">
            <header className="flex items-start justify-between gap-2 border-b border-(--border-default) px-3 py-2.5">
                <div>
                    <p data-eyebrow>{t('media.upload.eyebrow')}</p>
                    <h2 className="text-(length:--text-lg) text-(--text-primary)">
                        {t('media.upload.title')}
                    </h2>
                </div>
                <Button aria-label={t('media.close')} onClick={onClose} size="icon" variant="ghost">
                    <X aria-hidden className="size-4" />
                </Button>
            </header>

            <div className="flex flex-col gap-3 p-3">
                <Field
                    hint={t('media.upload.fileHint', {
                        size: formatBytes(MAX_BYTES, locale),
                    })}
                    label={t('media.upload.file')}
                    required
                    {...(tooLarge
                        ? {
                              error: t('media.upload.tooLarge', {
                                  size: formatBytes(MAX_BYTES, locale),
                              }),
                          }
                        : {})}
                >
                    {({ id, 'aria-describedby': describedBy, invalid }) => (
                        <input
                            aria-describedby={describedBy}
                            {...(invalid ? { 'aria-invalid': true } : {})}
                            className="block w-full text-(length:--text-sm) text-(--text-secondary) file:me-3 file:border file:border-(--border-control) file:bg-(--action-secondary) file:px-3 file:py-1.5 file:text-(--text-primary) hover:file:bg-(--action-secondary-hover)"
                            id={id}
                            onChange={(event) => setFile(event.target.files?.[0] ?? null)}
                            ref={input}
                            type="file"
                        />
                    )}
                </Field>

                {file !== null ? (
                    <p className="text-(length:--text-sm) text-(--text-muted)">
                        <span data-technical>{file.name}</span>
                        {' · '}
                        {formatBytes(file.size, locale)}
                    </p>
                ) : null}

                <Field
                    hint={t('media.upload.collectionHint')}
                    label={t('media.upload.collection')}
                    required
                    {...(collection === '' || collectionValid
                        ? {}
                        : { error: t('media.upload.collectionInvalid') })}
                >
                    {({ id, 'aria-describedby': describedBy, invalid }) => (
                        <Input
                            aria-describedby={describedBy}
                            data-technical
                            id={id}
                            invalid={invalid}
                            onChange={(event) => setCollection(event.target.value)}
                            value={collection}
                        />
                    )}
                </Field>

                <div className="flex flex-col gap-1">
                    <span className="text-(length:--text-sm) font-medium text-(--text-secondary)">
                        {t('media.visibility')}
                    </span>
                    <SegmentedControl<'public' | 'private'>
                        label={t('media.visibility')}
                        onChange={setVisibility}
                        options={[
                            { value: 'public', label: t('media.public') },
                            { value: 'private', label: t('media.private') },
                        ]}
                        value={visibility}
                    />
                    <p className="text-(length:--text-sm) text-(--text-muted)">
                        {visibility === 'public'
                            ? t('media.upload.publicNote')
                            : t('media.upload.privateNote')}
                    </p>
                </div>

                {rejection !== null ? <Alert tone="danger">{rejection}</Alert> : null}

                {send.isSuccess ? <Alert tone="success">{t('media.upload.accepted')}</Alert> : null}

                <div>
                    <Button
                        disabled={!ready}
                        loading={send.isPending}
                        onClick={() => {
                            if (file !== null) {
                                send.mutate(file);
                            }
                        }}
                        variant="primary"
                    >
                        <UploadIcon aria-hidden className="size-3.5" />
                        {t('media.upload.submit')}
                    </Button>
                </div>

                <p className="text-(length:--text-sm) text-(--text-muted)">
                    {t('media.upload.pipelineNote')}
                </p>
            </div>
        </div>
    );
}
