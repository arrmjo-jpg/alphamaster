import { useState } from 'react';
import { useTranslation } from 'react-i18next';

import { ApiError } from '@/api/errors';
import { Alert } from '@/ui/Alert';
import { Button } from '@/ui/Button';

import type { TranslationEntry, TranslationLocale } from './api';

export interface TranslationEntryRowProps {
    entry: TranslationEntry;
    source: TranslationLocale;
    target: TranslationLocale;
    mayWrite: boolean;
    onSave: (values: Record<string, string>) => Promise<void>;
}

/**
 * One item, with the source language beside the target.
 *
 * Side by side is the whole point. Translating means reading one language and writing
 * another, and every existing screen shows one locale at a time — so the way to write
 * Arabic was to switch the console to Arabic, at which point the English was no longer
 * on screen.
 *
 * The source column is text rather than a disabled field. It is not something the
 * editor is being stopped from changing here; it is what they are translating, and a
 * greyed-out input would invite them to try.
 *
 * Empty means untranslated, and it is left visibly empty. Prefilling the target with
 * the source is how a platform ends up with English inside its Arabic column and no
 * way to tell which of those were deliberate.
 */
export function TranslationEntryRow({
    entry,
    source,
    target,
    mayWrite,
    onSave,
}: TranslationEntryRowProps) {
    const { t } = useTranslation();

    const [draft, setDraft] = useState<Record<string, string>>({});
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [saved, setSaved] = useState(false);

    const valueFor = (field: TranslationEntry['fields'][number]): string =>
        draft[field.name] ?? field.values[target.code] ?? '';

    const changed = Object.entries(draft).some(
        ([name, value]) =>
            value !==
            (entry.fields.find((field) => field.name === name)?.values[target.code] ?? ''),
    );

    const save = async () => {
        setSaving(true);
        setError(null);
        setSaved(false);

        try {
            // Only what changed. The endpoint merges, so sending an untouched field
            // back would rewrite it with what was already there and put a pointless
            // entry in its history.
            await onSave(draft);
            setDraft({});
            setSaved(true);
        } catch (caught) {
            if (!(caught instanceof ApiError)) {
                throw caught;
            }

            setError(caught.message);
        } finally {
            setSaving(false);
        }
    };

    return (
        <div className="flex flex-col gap-2 border-t border-(--border-default) py-3 first:border-t-0">
            <div className="flex flex-wrap items-baseline gap-2">
                <p
                    className="text-(length:--text-sm) font-medium text-(--text-primary)"
                    data-technical
                >
                    {entry.title}
                </p>
                {entry.context !== null ? (
                    <p className="text-(length:--text-xs) text-(--text-muted)">{entry.context}</p>
                ) : null}
            </div>

            {entry.fields.map((field) => {
                const original = field.values[source.code] ?? '';
                const current = valueFor(field);

                return (
                    <div className="grid gap-2 md:grid-cols-2" key={field.name}>
                        <div className="flex flex-col gap-1">
                            <p className="text-(length:--text-xs) text-(--text-muted)">
                                {field.label} · {source.native_name}
                            </p>
                            <p
                                className="min-h-(--field-height) border border-(--border-default) bg-(--surface-subtle) p-2 text-(length:--text-sm) whitespace-pre-wrap text-(--text-secondary)"
                                dir={source.direction}
                                lang={source.code}
                            >
                                {original === '' ? (
                                    <span className="text-(--text-muted) italic">
                                        {t('translations.nothingWritten')}
                                    </span>
                                ) : (
                                    original
                                )}
                            </p>
                        </div>

                        <div className="flex flex-col gap-1">
                            <label
                                className="text-(length:--text-xs) text-(--text-muted)"
                                htmlFor={`${entry.id}-${field.name}`}
                            >
                                {field.label} · {target.native_name}
                            </label>
                            <textarea
                                className="min-h-(--field-height) w-full border border-(--border-strong) bg-(--surface-default) p-2 text-(length:--text-sm) text-(--text-primary) focus-visible:outline-2 focus-visible:outline-offset-0 focus-visible:outline-(--focus-ring) disabled:cursor-not-allowed disabled:opacity-50"
                                dir={target.direction}
                                disabled={!mayWrite || saving}
                                id={`${entry.id}-${field.name}`}
                                lang={target.code}
                                onChange={(event) => {
                                    setSaved(false);
                                    setDraft((held) => ({
                                        ...held,
                                        [field.name]: event.target.value,
                                    }));
                                }}
                                rows={field.multiline ? 6 : 1}
                                value={current}
                            />
                        </div>
                    </div>
                );
            })}

            {error !== null ? <Alert tone="danger">{error}</Alert> : null}

            {mayWrite ? (
                <div className="flex items-center gap-3">
                    <Button
                        disabled={!changed}
                        loading={saving}
                        onClick={() => void save()}
                        size="sm"
                        variant="secondary"
                    >
                        {t('translations.save')}
                    </Button>
                    {saved && !changed ? (
                        <span className="text-(length:--text-xs) text-(--state-success-text)">
                            {t('translations.saved')}
                        </span>
                    ) : null}
                </div>
            ) : null}
        </div>
    );
}
