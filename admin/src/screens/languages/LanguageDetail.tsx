import { useMutation, useQueryClient } from '@tanstack/react-query';
import { ClipboardList, PenLine, RotateCcw, Sparkles, Star, X } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { useNavigate } from 'react-router';

import { ApiError } from '@/api/errors';
import { isSupportedLocale } from '@/i18n';
import { requestSuggestions } from '@/screens/ai/api';
import { CoverageMeter } from '@/screens/languages/Coverage';
import type { LanguageStanding, Overview } from '@/screens/translations/api';
import { Alert } from '@/ui/Alert';
import { Button } from '@/ui/Button';
import { Checkbox } from '@/ui/Checkbox';
import { Field } from '@/ui/Field';
import { Input } from '@/ui/Input';
import { SegmentedControl } from '@/ui/SegmentedControl';
import type { StateTone } from '@/ui/state';
import { StatusBadge } from '@/ui/StatusBadge';

import {
    createLanguage,
    makeDefaultLanguage,
    toggleLanguage,
    updateLanguage,
    type AdminLanguage,
    type LanguageDirection,
} from './api';

export interface LanguageDetailProps {
    /** Null while a new language is being composed. */
    language: AdminLanguage | null;
    onClose: () => void;
    /** Called with the new language once the platform has accepted it. */
    onCreated: (language: AdminLanguage) => void;
    /** Coverage and AI progress, from the platform; absent until it has answered. */
    standing?: LanguageStanding;
    /** Whether AI is there, and whether this operator may use it. */
    ai?: Overview['ai'];
}

interface Draft {
    code: string;
    name: string;
    native_name: string;
    direction: LanguageDirection;
    sort_order: string;
}

function draftFrom(language: AdminLanguage | null): Draft {
    return {
        code: language?.code ?? '',
        name: language?.name ?? '',
        native_name: language?.native_name ?? '',
        // The response types direction as the platform's own enum, so this needs no
        // narrowing: a value outside it could not have been published.
        direction: language?.direction ?? 'ltr',
        sort_order: String(language?.sort_order ?? 0),
    };
}

/** The suggestion states the platform stores, in the order a reviewer reads them. */
const PROGRESS: { key: keyof LanguageStanding['suggestions']; tone: StateTone }[] = [
    { key: 'pending', tone: 'pending' },
    { key: 'ready', tone: 'info' },
    { key: 'failed', tone: 'danger' },
    { key: 'accepted', tone: 'success' },
    { key: 'dismissed', tone: 'neutral' },
];

/**
 * One language: who it is, how far it has got, and what to do next.
 *
 * The next step is the point. A language that has just been added is at 0% and is a
 * draft (ADR 0048), and an operator looking at it should not have to know that the
 * Translations screen exists to find out how to fill it — so the two ways to translate
 * it are offered here, each mapped to what the platform actually does: one opens the
 * missing entries for a person to write, the other queues AI suggestions a person then
 * reviews. Neither saves anything on its own.
 *
 * `is_active` and `is_default` are not form fields. They are states with rules — the
 * default must be served, and cannot be switched off — and the platform has an
 * endpoint for each. The one exception is creation, where "serve it immediately" is an
 * explicit choice, off by default, so a new language starts as a draft.
 */
export function LanguageDetail({
    language,
    onClose,
    onCreated,
    standing,
    ai,
}: LanguageDetailProps) {
    const { t } = useTranslation();
    const queryClient = useQueryClient();
    const navigate = useNavigate();

    const creating = language === null;
    const [draft, setDraft] = useState<Draft>(() => draftFrom(language));
    const [serveNow, setServeNow] = useState(false);

    const refresh = async () => {
        await queryClient.invalidateQueries({ queryKey: ['admin-languages'] });
        await queryClient.invalidateQueries({ queryKey: ['translation-overview'] });
        // The public list drives the console's own language switcher, so it has to be
        // re-read too — otherwise activating a language leaves the switcher offering
        // the old set until the page is reloaded.
        await queryClient.invalidateQueries({ queryKey: ['languages'] });
    };

    const save = useMutation({
        mutationFn: async () => {
            const sortOrder = Number.parseInt(draft.sort_order, 10);

            if (creating) {
                return createLanguage({
                    code: draft.code.trim(),
                    name: draft.name.trim(),
                    native_name: draft.native_name.trim(),
                    direction: draft.direction,
                    sort_order: sortOrder,
                    // Said explicitly rather than left to the API's default, which
                    // serves a new language at once (ADR 0048 §1).
                    is_active: serveNow,
                });
            }

            // Only what changed. Every field is `sometimes`, so an absent key leaves
            // the stored value alone.
            return updateLanguage(language.id, {
                ...(draft.code.trim() === language.code ? {} : { code: draft.code.trim() }),
                ...(draft.name.trim() === language.name ? {} : { name: draft.name.trim() }),
                ...(draft.native_name.trim() === language.native_name
                    ? {}
                    : { native_name: draft.native_name.trim() }),
                ...(draft.direction === language.direction ? {} : { direction: draft.direction }),
                ...(sortOrder === language.sort_order ? {} : { sort_order: sortOrder }),
            });
        },
        onSuccess: async (saved) => {
            setDraft(draftFrom(saved));
            await refresh();

            if (creating) {
                onCreated(saved);
            }
        },
    });

    const toggle = useMutation({
        mutationFn: () => toggleLanguage(language?.id ?? ''),
        onSuccess: refresh,
    });

    const promote = useMutation({
        mutationFn: () => makeDefaultLanguage(language?.id ?? ''),
        onSuccess: refresh,
    });

    const fill = useMutation({
        mutationFn: (locale: string) => requestSuggestions({ locale }),
        onSuccess: () => queryClient.invalidateQueries({ queryKey: ['translation-overview'] }),
    });

    const set = (patch: Partial<Draft>) => setDraft((current) => ({ ...current, ...patch }));

    const sortOrder = Number.parseInt(draft.sort_order, 10);
    const complete =
        draft.code.trim() !== '' && draft.name.trim() !== '' && draft.native_name.trim() !== '';
    const dirty =
        creating ||
        draft.code.trim() !== language.code ||
        draft.name.trim() !== language.name ||
        draft.native_name.trim() !== language.native_name ||
        draft.direction !== language.direction ||
        sortOrder !== language.sort_order;

    const fieldError = (name: string): { error?: string } => {
        const message =
            save.error instanceof ApiError ? save.error.validationDetails?.[name]?.[0] : undefined;

        return message === undefined ? {} : { error: message };
    };

    const translated = isSupportedLocale(draft.code.trim());

    // Why AI cannot help here, if it cannot. Read from the platform, never assumed —
    // the action is disabled with its reason rather than hidden or left to fail.
    const aiBlocked =
        ai === undefined
            ? null
            : !ai.available
              ? t('languages.aiUnavailableNotConfigured')
              : !ai.may_use
                ? t('languages.aiUnavailableNoPermission')
                : null;

    const counts = standing?.suggestions;
    const anySuggestions = counts !== undefined && Object.values(counts).some((n) => n > 0);

    const openTranslations = (code: string, state?: string): void => {
        void navigate(
            `/translations?target=${encodeURIComponent(code)}${state === undefined ? '' : `&state=${state}`}`,
        );
    };

    return (
        <div className="flex flex-col border border-(--border-default) bg-(--surface-default)">
            <header className="flex items-start justify-between gap-2 border-b border-(--border-default) px-3 py-2.5">
                <div className="min-w-0">
                    <p data-eyebrow>
                        {creating ? t('languages.addingEyebrow') : t('languages.eyebrowOne')}
                    </p>
                    <h2 className="truncate text-(length:--text-lg) text-(--text-primary)">
                        {creating ? t('languages.add') : language.name}
                    </h2>
                    {creating ? null : (
                        <p className="text-(length:--text-sm) text-(--text-muted)">
                            <span dir={language.direction} lang={language.code}>
                                {language.native_name}
                            </span>{' '}
                            · <span data-technical>{language.code}</span>
                        </p>
                    )}
                </div>
                <Button
                    aria-label={t('languages.close')}
                    onClick={onClose}
                    size="icon"
                    variant="ghost"
                >
                    <X aria-hidden className="size-4" />
                </Button>
            </header>

            <div className="flex flex-col gap-4 p-3">
                {creating ? null : (
                    <>
                        <div className="flex flex-wrap gap-1.5">
                            {language.is_active ? (
                                <StatusBadge tone="success">{t('languages.active')}</StatusBadge>
                            ) : (
                                <StatusBadge tone="pending">
                                    {t('languages.draft')} · {t('languages.draftHint')}
                                </StatusBadge>
                            )}
                            {language.is_default ? (
                                <StatusBadge icon={<Star className="size-3" />} tone="info">
                                    {t('languages.default')}
                                </StatusBadge>
                            ) : null}
                            <StatusBadge tone={language.direction === 'rtl' ? 'info' : 'neutral'}>
                                {t(`languages.direction.${language.direction}`)}
                            </StatusBadge>
                        </div>

                        <section className="flex flex-col gap-2">
                            <h3 data-eyebrow>{t('languages.coverage')}</h3>
                            {standing === undefined ? (
                                <p className="text-(length:--text-sm) text-(--text-muted)">
                                    {t('state.loading')}
                                </p>
                            ) : (
                                <CoverageMeter counts={standing.coverage} />
                            )}
                            <p className="text-(length:--text-xs) text-(--text-muted)">
                                {language.is_default
                                    ? t('languages.sourceExplained')
                                    : t('languages.coverageNote')}
                            </p>
                        </section>

                        {language.is_default ? null : (
                            <section className="flex flex-col gap-3 border border-(--border-default) bg-(--surface-raised) p-3">
                                <h3 className="text-(length:--text-md) font-medium text-(--text-primary)">
                                    {t('languages.workflowTitle')}
                                </h3>

                                <div className="flex flex-col gap-1.5">
                                    <div>
                                        <Button
                                            onClick={() =>
                                                openTranslations(language.code, 'missing')
                                            }
                                            size="sm"
                                            variant="primary"
                                        >
                                            <PenLine aria-hidden className="size-3.5" />
                                            {t('languages.translateManually')}
                                        </Button>
                                    </div>
                                    <p className="text-(length:--text-xs) text-(--text-muted)">
                                        {t('languages.translateManuallyNote')}
                                    </p>
                                </div>

                                <div className="flex flex-col gap-1.5">
                                    <div>
                                        <Button
                                            disabled={aiBlocked !== null || ai === undefined}
                                            loading={fill.isPending}
                                            onClick={() => fill.mutate(language.code)}
                                            size="sm"
                                            variant="secondary"
                                        >
                                            <Sparkles aria-hidden className="size-3.5" />
                                            {t('languages.translateWithAi')}
                                        </Button>
                                    </div>
                                    <p className="text-(length:--text-xs) text-(--text-muted)">
                                        {aiBlocked ?? t('languages.translateWithAiNote')}
                                    </p>
                                </div>

                                {fill.data !== undefined ? (
                                    <Alert tone="info">
                                        {t('languages.aiQueued', {
                                            queued: fill.data.queued,
                                            skipped: fill.data.skipped,
                                        })}
                                    </Alert>
                                ) : null}

                                {fill.error instanceof ApiError ? (
                                    <Alert tone="danger">{fill.error.message}</Alert>
                                ) : null}

                                <div className="border-t border-(--border-default) pt-3">
                                    <Button
                                        onClick={() => openTranslations(language.code)}
                                        size="sm"
                                        variant="ghost"
                                    >
                                        <ClipboardList aria-hidden className="size-3.5" />
                                        {t('languages.manageTranslations')}
                                    </Button>
                                </div>
                            </section>
                        )}

                        {anySuggestions && counts !== undefined ? (
                            <section className="flex flex-col gap-2">
                                <h3 data-eyebrow>{t('languages.progressTitle')}</h3>
                                <dl className="grid grid-cols-2 gap-2 sm:grid-cols-3">
                                    {PROGRESS.map(({ key, tone }) => (
                                        <div
                                            className="flex flex-col gap-1 border border-(--border-default) p-2"
                                            key={key}
                                        >
                                            <dt>
                                                <StatusBadge tone={tone}>
                                                    {t(`languages.progress.${key}`)}
                                                </StatusBadge>
                                            </dt>
                                            <dd
                                                className="text-(length:--text-lg) text-(--text-primary)"
                                                data-technical
                                            >
                                                {counts[key]}
                                            </dd>
                                        </div>
                                    ))}
                                </dl>
                                <p className="text-(length:--text-xs) text-(--text-muted)">
                                    {t('languages.progressNote')}
                                </p>
                                <div className="flex flex-wrap gap-2">
                                    {counts.ready > 0 ? (
                                        <Button
                                            onClick={() =>
                                                openTranslations(language.code, 'needs_review')
                                            }
                                            size="sm"
                                            variant="secondary"
                                        >
                                            {t('languages.reviewSuggestions')}
                                        </Button>
                                    ) : null}
                                    {counts.failed > 0 && aiBlocked === null && ai !== undefined ? (
                                        // Asking again for what is missing is the retry: a
                                        // failed field is still missing, so it is queued
                                        // again, and nothing already translated is touched.
                                        <Button
                                            loading={fill.isPending}
                                            onClick={() => fill.mutate(language.code)}
                                            size="sm"
                                            variant="ghost"
                                        >
                                            <RotateCcw aria-hidden className="size-3.5" />
                                            {t('languages.retryFailed')}
                                        </Button>
                                    ) : null}
                                </div>
                            </section>
                        ) : null}

                        <section className="flex flex-col gap-2">
                            <h3 data-eyebrow>{t('languages.stateSection')}</h3>

                            {language.is_active ? null : (
                                <p className="text-(length:--text-sm) text-(--text-secondary)">
                                    {t('languages.draftExplained')}
                                </p>
                            )}

                            <div className="flex flex-wrap gap-2">
                                <Button
                                    // The platform refuses to switch off the language it
                                    // falls back to, so the control is refused here too
                                    // rather than sending a request certain to be 422.
                                    disabled={language.is_default && language.is_active}
                                    loading={toggle.isPending}
                                    onClick={() => toggle.mutate()}
                                    size="sm"
                                    variant={language.is_active ? 'secondary' : 'primary'}
                                >
                                    {language.is_active
                                        ? t('languages.deactivate')
                                        : t('languages.activate')}
                                </Button>

                                {language.is_default ? null : (
                                    <Button
                                        loading={promote.isPending}
                                        onClick={() => promote.mutate()}
                                        size="sm"
                                        variant="secondary"
                                    >
                                        <Star aria-hidden className="size-3.5" />
                                        {t('languages.makeDefault')}
                                    </Button>
                                )}
                            </div>

                            {language.is_default && language.is_active ? (
                                <p className="text-(length:--text-sm) text-(--text-muted)">
                                    {t('languages.defaultStaysOn')}
                                </p>
                            ) : null}

                            {language.is_default ? null : (
                                <p className="text-(length:--text-sm) text-(--text-muted)">
                                    {t('languages.makeDefaultActivates')}
                                </p>
                            )}

                            {toggle.error instanceof ApiError ? (
                                <Alert tone="danger">{toggle.error.message}</Alert>
                            ) : null}
                            {promote.error instanceof ApiError ? (
                                <Alert tone="danger">{promote.error.message}</Alert>
                            ) : null}
                        </section>
                    </>
                )}

                <section className="flex flex-col gap-2">
                    <h3 data-eyebrow>{t('languages.details')}</h3>

                    <Field
                        {...fieldError('code')}
                        hint={creating ? t('languages.codeHint') : t('languages.codeChangeHint')}
                        label={t('languages.code')}
                        required
                    >
                        {({ id, 'aria-describedby': describedBy, invalid }) => (
                            <Input
                                aria-describedby={describedBy}
                                data-technical
                                id={id}
                                invalid={invalid}
                                onChange={(event) => set({ code: event.target.value })}
                                value={draft.code}
                            />
                        )}
                    </Field>

                    <Field {...fieldError('name')} label={t('languages.name')} required>
                        {({ id, 'aria-describedby': describedBy, invalid }) => (
                            <Input
                                aria-describedby={describedBy}
                                id={id}
                                invalid={invalid}
                                onChange={(event) => set({ name: event.target.value })}
                                value={draft.name}
                            />
                        )}
                    </Field>

                    <Field
                        {...fieldError('native_name')}
                        hint={t('languages.nativeNameHint')}
                        label={t('languages.nativeName')}
                        required
                    >
                        {({ id, 'aria-describedby': describedBy, invalid }) => (
                            <Input
                                aria-describedby={describedBy}
                                id={id}
                                invalid={invalid}
                                lang={draft.code.trim() === '' ? undefined : draft.code.trim()}
                                onChange={(event) => set({ native_name: event.target.value })}
                                value={draft.native_name}
                            />
                        )}
                    </Field>

                    <div className="flex flex-col gap-1">
                        <span className="text-(length:--text-sm) font-medium text-(--text-secondary)">
                            {t('languages.writingDirection')}
                        </span>
                        <SegmentedControl<LanguageDirection>
                            label={t('languages.writingDirection')}
                            onChange={(direction) => set({ direction })}
                            options={[
                                { value: 'ltr', label: t('languages.direction.ltr') },
                                { value: 'rtl', label: t('languages.direction.rtl') },
                            ]}
                            value={draft.direction}
                        />
                    </div>

                    <Field
                        {...fieldError('sort_order')}
                        hint={t('languages.orderHint')}
                        label={t('languages.order')}
                    >
                        {({ id, 'aria-describedby': describedBy, invalid }) => (
                            <Input
                                aria-describedby={describedBy}
                                id={id}
                                invalid={invalid || !Number.isInteger(sortOrder) || sortOrder < 0}
                                min={0}
                                onChange={(event) => set({ sort_order: event.target.value })}
                                type="number"
                                value={draft.sort_order}
                            />
                        )}
                    </Field>

                    {creating ? (
                        <label className="flex items-start gap-2 text-(length:--text-sm) text-(--text-secondary)">
                            <Checkbox
                                checked={serveNow}
                                onChange={(event) => setServeNow(event.target.checked)}
                            />
                            <span className="flex flex-col gap-0.5">
                                <span className="text-(--text-primary)">
                                    {t('languages.serveImmediately')}
                                </span>
                                <span className="text-(length:--text-xs) text-(--text-muted)">
                                    {t('languages.serveImmediatelyHint')}
                                </span>
                            </span>
                        </label>
                    ) : null}

                    {/* Said where the decision is made, because the two are easy to
                        confuse: adding a language here makes the platform able to serve
                        it, and does not make this console speak it (ADR 0049). */}
                    {draft.code.trim() === '' || translated ? null : (
                        <Alert tone="info">
                            {t('languages.noInterfaceTranslation', { code: draft.code.trim() })}
                        </Alert>
                    )}

                    {save.error instanceof ApiError && save.error.validationDetails === null ? (
                        <Alert tone="danger">{save.error.message}</Alert>
                    ) : null}

                    {save.isSuccess && !dirty ? (
                        <Alert tone="success">{t('languages.saved')}</Alert>
                    ) : null}

                    <div className="flex flex-wrap gap-2">
                        <Button
                            disabled={!dirty || !complete || !Number.isInteger(sortOrder)}
                            loading={save.isPending}
                            onClick={() => save.mutate()}
                            variant="primary"
                        >
                            {creating ? t('languages.create') : t('languages.save')}
                        </Button>
                        <Button
                            disabled={!dirty}
                            onClick={() => setDraft(draftFrom(language))}
                            variant="ghost"
                        >
                            {t('languages.discard')}
                        </Button>
                    </div>
                </section>

                {creating ? null : (
                    <p className="border-t border-(--border-default) pt-3 text-(length:--text-sm) text-(--text-muted)">
                        {t('languages.noDelete')}
                    </p>
                )}
            </div>
        </div>
    );
}
