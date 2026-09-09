import { useMutation, useQueryClient } from '@tanstack/react-query';
import { Star, X } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';

import { ApiError } from '@/api/errors';
import { isSupportedLocale } from '@/i18n';
import { Alert } from '@/ui/Alert';
import { Button } from '@/ui/Button';
import { Field } from '@/ui/Field';
import { Input } from '@/ui/Input';
import { SegmentedControl } from '@/ui/SegmentedControl';
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

/**
 * One language, or the one being added.
 *
 * The same form serves both, because the platform takes the same fields either way —
 * a create is a `POST` of the whole record and an edit is a `PUT` of the parts that
 * changed. Two nearly identical forms would drift the first time a field moved.
 *
 * `is_active` and `is_default` are not fields here even though the create endpoint
 * accepts them. They are states with rules attached — the default must be active, and
 * the active default cannot be switched off — and the platform has an endpoint for
 * each. Editing them as checkboxes beside `sort_order` would put those rules in this
 * client, where they would be a second, quieter copy of the server's.
 *
 * The caller keys this component by language, so a draft never survives a change of
 * selection, and nothing resets the form on a refetch.
 */
export function LanguageDetail({ language, onClose, onCreated }: LanguageDetailProps) {
    const { t } = useTranslation();
    const queryClient = useQueryClient();

    const creating = language === null;
    const [draft, setDraft] = useState<Draft>(() => draftFrom(language));

    const refresh = async () => {
        await queryClient.invalidateQueries({ queryKey: ['admin-languages'] });
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
                        <p className="text-(length:--text-sm) text-(--text-muted)" data-technical>
                            {language.code}
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
                            <StatusBadge tone={language.is_active ? 'success' : 'neutral'}>
                                {language.is_active
                                    ? t('languages.active')
                                    : t('languages.inactive')}
                            </StatusBadge>
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
                            <h3 data-eyebrow>{t('languages.stateSection')}</h3>

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

                    {/* Said where the decision is made, because the two are easy to
                        confuse: adding a language here makes the platform serve it, and
                        does not make this console speak it. */}
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
