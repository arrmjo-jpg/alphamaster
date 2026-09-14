import { Check, PenLine, RotateCcw, Sparkles, X } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';

import { ApiError } from '@/api/errors';
import { Alert } from '@/ui/Alert';
import { Button } from '@/ui/Button';
import { StatusBadge } from '@/ui/StatusBadge';

import {
    ITEM_STATUS_TONE,
    type TranslationEntry,
    type TranslationField,
    type TranslationLocale,
} from './api';

export interface TranslationEntryRowProps {
    entry: TranslationEntry;
    source: TranslationLocale;
    target: TranslationLocale;
    mayWrite: boolean;
    /** Whether this operator may ask AI, and whether a provider is there to ask. */
    ai: { mayUse: boolean; available: boolean | undefined };
    /** A person's own translation of the fields they changed. */
    onSave: (values: Record<string, string | null>) => Promise<void>;
    /** Translate this item with AI. */
    onTranslate: () => Promise<void>;
    /** Accept the item's translation once, with the fields the reviewer changed. */
    onAccept: (batchId: string, values: Record<string, string | null>) => Promise<void>;
    onDismiss: (batchId: string) => Promise<void>;
}

type Mode = 'closed' | 'review' | 'edit';

/**
 * One item in the target language: its status, how much of it is written, and what can be
 * done with it (ADR 0056).
 *
 * The item is the unit. It is translated with AI as a whole, reviewed as a whole — every field
 * that came back, grouped as its source describes them — and accepted with one button. There is
 * no control for a single field, because a field is not something an operator decides about:
 * a subject accepted without its body is a message that cannot be sent.
 *
 * Nothing about a field's name is known here. Whether it is required, rich text, SEO, or has a
 * length limit is read from the metadata the platform sends with it.
 *
 * Side by side is kept from the workshop's first version: translating means reading one
 * language and writing another, so the source text sits beside every field, as text rather
 * than a disabled input, and an untranslated field is left visibly empty rather than seeded
 * with the source.
 */
export function TranslationEntryRow({
    entry,
    source,
    target,
    mayWrite,
    ai,
    onSave,
    onTranslate,
    onAccept,
    onDismiss,
}: TranslationEntryRowProps) {
    const { t } = useTranslation();

    const [mode, setMode] = useState<Mode>('closed');
    const [draft, setDraft] = useState<Record<string, string>>({});
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [saved, setSaved] = useState(false);

    const batch = entry.batch;
    const proposals = new Map((batch?.suggestions ?? []).map((row) => [row.field, row]));
    const status = entry.status;

    const canTranslate =
        mayWrite &&
        ai.mayUse &&
        (status === 'not_translated' || status === 'incomplete' || status === 'failed');

    const written = (field: TranslationField): string => field.values[target.code] ?? '';

    // Only what the reviewer changed is sent: every other field is accepted as generated.
    const reviewEdits: Record<string, string | null> = {};

    for (const [name, value] of Object.entries(draft)) {
        const proposal = proposals.get(name);

        if (proposal !== undefined && value !== (proposal.text ?? '')) {
            reviewEdits[name] = value.trim() === '' ? null : value;
        }
    }

    // Only what changed. The endpoint merges, so sending an untouched field back would
    // rewrite it with what was already there.
    const manualChanges: Record<string, string | null> = {};

    for (const [name, value] of Object.entries(draft)) {
        const field = entry.fields.find((candidate) => candidate.name === name);

        if (field !== undefined && value !== written(field)) {
            manualChanges[name] = value.trim() === '' ? null : value;
        }
    }

    const edited = Object.keys(reviewEdits).length > 0;
    const changed = Object.keys(manualChanges).length > 0;

    const groups = (['content', 'seo'] as const)
        .map((group) => ({
            group,
            fields: entry.fields.filter((field) => field.group === group),
        }))
        .filter(({ fields }) => fields.length > 0);

    const open = (next: Mode): void => {
        setDraft({});
        setError(null);
        setSaved(false);
        setMode(mode === next ? 'closed' : next);
    };

    const run = async (work: () => Promise<void>, after?: () => void): Promise<void> => {
        setBusy(true);
        setError(null);

        try {
            await work();
            after?.();
        } catch (caught) {
            if (!(caught instanceof ApiError)) {
                throw caught;
            }

            setError(caught.message);
        } finally {
            setBusy(false);
        }
    };

    const progress = t('translations.item.progressLabel', {
        filled: entry.progress.filled,
        total: entry.progress.total,
    });

    return (
        <article
            aria-label={entry.title}
            className="flex flex-col gap-2 border-t border-(--border-default) py-3 first:border-t-0"
        >
            <div className="flex flex-wrap items-center justify-between gap-2">
                <div className="flex min-w-0 flex-col gap-0.5">
                    <p
                        className="text-(length:--text-sm) font-medium text-(--text-primary)"
                        data-technical
                    >
                        {entry.title}
                    </p>
                    {entry.context !== null ? (
                        <p className="text-(length:--text-xs) text-(--text-muted)">
                            {entry.context}
                        </p>
                    ) : null}
                </div>

                <div className="flex flex-wrap items-center gap-2">
                    <StatusBadge tone={ITEM_STATUS_TONE[status]}>{entry.status_label}</StatusBadge>

                    <span
                        aria-label={progress}
                        className="text-(length:--text-xs) text-(--text-secondary)"
                        data-technical
                        title={progress}
                    >
                        {t('translations.item.progress', {
                            filled: entry.progress.filled,
                            total: entry.progress.total,
                        })}
                    </span>

                    {canTranslate ? (
                        <Button
                            disabled={busy || ai.available === false}
                            loading={busy && mode === 'closed'}
                            onClick={() => void run(onTranslate)}
                            size="sm"
                            variant="secondary"
                        >
                            {status === 'failed' ? (
                                <RotateCcw aria-hidden className="size-3.5" />
                            ) : (
                                <Sparkles aria-hidden className="size-3.5" />
                            )}
                            {status === 'failed'
                                ? t('translations.item.retry')
                                : t('translations.item.translate')}
                        </Button>
                    ) : null}

                    {status === 'pending' ? (
                        <span className="flex items-center gap-1 text-(length:--text-xs) text-(--text-muted)">
                            <Sparkles aria-hidden className="size-3.5" />
                            {t('translations.item.translating')}
                        </span>
                    ) : null}

                    {status === 'ready' && batch !== null && mayWrite ? (
                        <Button
                            onClick={() => open('review')}
                            size="sm"
                            variant={mode === 'review' ? 'secondary' : 'primary'}
                        >
                            {mode === 'review'
                                ? t('translations.item.closeReview')
                                : t('translations.item.review')}
                        </Button>
                    ) : null}

                    <Button onClick={() => open('edit')} size="sm" variant="ghost">
                        <PenLine aria-hidden className="size-3.5" />
                        {mode === 'edit'
                            ? t('translations.item.close')
                            : mayWrite
                              ? t('translations.item.edit')
                              : t('translations.item.show')}
                    </Button>
                </div>
            </div>

            {status === 'failed' && batch !== null ? (
                <Alert tone="warning">
                    <p>{t('translations.item.failed')}</p>
                    {batch.error_message !== null ? (
                        <p className="mt-1 text-(length:--text-xs)">{batch.error_message}</p>
                    ) : null}
                    <p className="mt-1 text-(length:--text-xs)">
                        {t('translations.item.fieldsCameBack', {
                            ready: batch.fields_ready,
                            total: batch.fields_total,
                        })}
                    </p>
                </Alert>
            ) : null}

            {mode === 'review' && batch !== null ? (
                <section
                    aria-label={t('translations.item.reviewTitle')}
                    className="flex flex-col gap-3 border-s-(length:--rail-width) border-(--state-info-rail) bg-(--state-info-tint)/30 p-3"
                >
                    <div className="flex flex-wrap items-center gap-1.5">
                        <StatusBadge icon={<Sparkles className="size-3" />} tone="info">
                            {edited ? t('translations.item.edited') : batch.status_label}
                        </StatusBadge>
                        {/* Said rather than implied: what is in these fields is a proposal,
                            and the content has not changed. */}
                        <span className="text-(length:--text-2xs) text-(--text-muted)">
                            {t('translations.item.notSavedYet')}
                        </span>
                    </div>

                    {groups.map(({ group, fields }) => (
                        <div className="flex flex-col gap-2" key={group}>
                            {group === 'seo' ? (
                                <h4 data-eyebrow>{t('translations.item.seo')}</h4>
                            ) : null}
                            {fields.map((field) => {
                                const proposal = proposals.get(field.name);

                                return (
                                    <FieldPair
                                        editable={proposal !== undefined}
                                        entryId={entry.id}
                                        field={field}
                                        hint={
                                            proposal === undefined
                                                ? t('translations.item.notInTranslation')
                                                : undefined
                                        }
                                        key={field.name}
                                        onChange={(value) =>
                                            setDraft((held) => ({ ...held, [field.name]: value }))
                                        }
                                        source={source}
                                        target={target}
                                        value={
                                            proposal === undefined
                                                ? written(field)
                                                : (draft[field.name] ?? proposal.text ?? '')
                                        }
                                    />
                                );
                            })}
                        </div>
                    ))}

                    <div className="flex flex-wrap items-center gap-2">
                        <Button
                            disabled={busy}
                            loading={busy}
                            onClick={() =>
                                void run(
                                    () => onAccept(batch.id, reviewEdits),
                                    () => {
                                        setDraft({});
                                        setMode('closed');
                                    },
                                )
                            }
                            size="sm"
                            variant="primary"
                        >
                            <Check aria-hidden className="size-3.5" />
                            {t('translations.item.accept')}
                        </Button>
                        <Button
                            disabled={busy}
                            onClick={() =>
                                void run(
                                    () => onDismiss(batch.id),
                                    () => {
                                        setDraft({});
                                        setMode('closed');
                                    },
                                )
                            }
                            size="sm"
                            variant="ghost"
                        >
                            <X aria-hidden className="size-3.5" />
                            {t('translations.item.dismiss')}
                        </Button>
                    </div>
                </section>
            ) : null}

            {mode === 'edit' ? (
                <section className="flex flex-col gap-3">
                    {groups.map(({ group, fields }) => (
                        <div className="flex flex-col gap-2" key={group}>
                            {group === 'seo' ? (
                                <h4 data-eyebrow>{t('translations.item.seo')}</h4>
                            ) : null}
                            {fields.map((field) => (
                                <FieldPair
                                    editable={mayWrite && !busy}
                                    entryId={entry.id}
                                    field={field}
                                    key={field.name}
                                    onChange={(value) => {
                                        setSaved(false);
                                        setDraft((held) => ({ ...held, [field.name]: value }));
                                    }}
                                    source={source}
                                    target={target}
                                    value={draft[field.name] ?? written(field)}
                                />
                            ))}
                        </div>
                    ))}

                    {mayWrite ? (
                        <div className="flex items-center gap-3">
                            <Button
                                disabled={!changed}
                                loading={busy}
                                onClick={() =>
                                    void run(
                                        () => onSave(manualChanges),
                                        () => {
                                            setDraft({});
                                            setSaved(true);
                                        },
                                    )
                                }
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
                    ) : (
                        <p className="text-(length:--text-xs) text-(--text-muted)">
                            {t('translations.readOnly')}
                        </p>
                    )}
                </section>
            ) : null}

            {error !== null ? <Alert tone="danger">{error}</Alert> : null}
        </article>
    );
}

interface FieldPairProps {
    entryId: string;
    field: TranslationField;
    source: TranslationLocale;
    target: TranslationLocale;
    value: string;
    editable: boolean;
    onChange: (value: string) => void;
    hint?: string | undefined;
}

/** One field: the source text beside the target, and what its metadata says about it. */
function FieldPair({
    entryId,
    field,
    source,
    target,
    value,
    editable,
    onChange,
    hint,
}: FieldPairProps) {
    const { t } = useTranslation();
    const original = field.values[source.code] ?? '';
    const id = `${entryId}-${field.name}`;

    return (
        <div className="grid gap-2 md:grid-cols-2">
            <div className="flex flex-col gap-1">
                <p className="text-(length:--text-xs) text-(--text-muted)">
                    {field.label} · {source.native_name}
                    {field.required ? null : ` · ${t('translations.item.optional')}`}
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
                <label className="text-(length:--text-xs) text-(--text-muted)" htmlFor={id}>
                    {field.label} · {target.native_name}
                </label>
                <textarea
                    className="min-h-(--field-height) w-full border border-(--border-strong) bg-(--surface-default) p-2 text-(length:--text-sm) text-(--text-primary) focus-visible:outline-2 focus-visible:outline-offset-0 focus-visible:outline-(--focus-ring) disabled:cursor-not-allowed disabled:opacity-50"
                    dir={target.direction}
                    disabled={!editable}
                    id={id}
                    lang={target.code}
                    onChange={(event) => onChange(event.target.value)}
                    rows={field.multiline ? 6 : 1}
                    value={value}
                />
                {field.type === 'html' ? (
                    <p className="text-(length:--text-2xs) text-(--text-muted)">
                        {t('translations.item.htmlNote')}
                    </p>
                ) : null}
                {field.max_length !== null ? (
                    <p className="text-(length:--text-2xs) text-(--text-muted)" data-technical>
                        {t('translations.item.length', {
                            length: [...value].length,
                            max: field.max_length,
                        })}
                    </p>
                ) : null}
                {hint !== undefined ? (
                    <p className="text-(length:--text-2xs) text-(--text-muted)">{hint}</p>
                ) : null}
            </div>
        </div>
    );
}
