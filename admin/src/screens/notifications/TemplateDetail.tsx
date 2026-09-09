import { useMutation, useQueryClient } from '@tanstack/react-query';
import { X } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';

import { ApiError } from '@/api/errors';
import { absoluteTime, relativeTime } from '@/lib/time';
import { useDirection, type LanguageOption } from '@/shell/DirectionProvider';
import { Alert } from '@/ui/Alert';
import { Button } from '@/ui/Button';
import { Field } from '@/ui/Field';
import { Input } from '@/ui/Input';
import { SegmentedControl } from '@/ui/SegmentedControl';
import { StatusBadge } from '@/ui/StatusBadge';

import { updateTemplate, type NotificationTemplate } from './api';

export interface TemplateDetailProps {
    template: NotificationTemplate;
    mayUpdate: boolean;
    onClose: () => void;
}

interface Wording {
    subject: string;
    body: string;
}

/**
 * One notification's wording, per language.
 *
 * The languages are the platform's active list rather than a pair this screen knows
 * about: `translations.*.locale` is validated `exists:languages,code`, so a template
 * may carry a translation for anything the Languages workspace has added — and a tab
 * strip hard-coded to English and Arabic would hide the one somebody just created.
 *
 * Only the languages actually edited are sent. The endpoint merges rather than
 * replaces, so an untouched Arabic translation survives an edit to the English one; a
 * form that submitted every tab would rewrite all of them on every save and would
 * quietly resurrect a translation somebody had meant to leave alone.
 */
export function TemplateDetail({ template, mayUpdate, onClose }: TemplateDetailProps) {
    const { t } = useTranslation();
    const { locale, languages } = useDirection();
    const queryClient = useQueryClient();

    const stored = new Map(
        template.translations.map((translation) => [
            translation.locale,
            { subject: translation.subject, body: translation.body },
        ]),
    );

    // The platform's languages, plus any locale this template already carries wording
    // for. The second half matters: a language can be deactivated after a translation
    // was written, and hiding the tab would make the text unreachable and un-editable
    // while it was still what the platform sent.
    const tabs: { code: string; label: string }[] = [
        ...languages.map((language: LanguageOption) => ({
            code: language.code,
            label: language.native_name,
        })),
        ...[...stored.keys()]
            .filter((code) => !languages.some((language) => language.code === code))
            .map((code) => ({ code, label: code })),
    ];

    const [tab, setTab] = useState(
        () => tabs.find((candidate) => candidate.code === locale)?.code ?? tabs[0]?.code ?? locale,
    );
    const [draft, setDraft] = useState<Record<string, Wording>>({});

    const wordingFor = (code: string): Wording =>
        draft[code] ?? stored.get(code) ?? { subject: '', body: '' };

    const changed = Object.entries(draft).filter(([code, wording]) => {
        const original = stored.get(code);

        return original === undefined
            ? wording.subject.trim() !== '' || wording.body.trim() !== ''
            : wording.subject !== original.subject || wording.body !== original.body;
    });

    // The endpoint requires both fields on any translation it is given, so a language
    // with only half of it filled in is not sendable and the form says which.
    const incomplete = changed.filter(
        ([, wording]) => wording.subject.trim() === '' || wording.body.trim() === '',
    );

    const save = useMutation({
        mutationFn: (changes: Parameters<typeof updateTemplate>[1]) =>
            updateTemplate(template.id, changes),
        onSuccess: (fresh) => {
            queryClient.setQueryData<NotificationTemplate[]>(
                ['notification-templates'],
                (current) => current?.map((row) => (row.id === fresh.id ? fresh : row)) ?? [fresh],
            );
            setDraft({});
        },
    });

    const set = (patch: Partial<Wording>) =>
        setDraft((current) => ({
            ...current,
            [tab]: { ...wordingFor(tab), ...patch },
        }));

    const current = wordingFor(tab);

    return (
        <div className="flex flex-col border border-(--border-default) bg-(--surface-default)">
            <header className="flex items-start justify-between gap-2 border-b border-(--border-default) px-3 py-2.5">
                <div className="min-w-0">
                    <p data-eyebrow>{t('notifications.templates.eyebrow')}</p>
                    <h3 className="truncate text-(length:--text-lg) text-(--text-primary)">
                        {template.type_label}
                    </h3>
                    <p className="text-(length:--text-sm) text-(--text-muted)" data-technical>
                        {template.type}
                    </p>
                </div>
                <Button
                    aria-label={t('notifications.templates.close')}
                    onClick={onClose}
                    size="icon"
                    variant="ghost"
                >
                    <X aria-hidden className="size-4" />
                </Button>
            </header>

            <div className="flex flex-col gap-4 p-3">
                <div className="flex flex-wrap items-center gap-2">
                    <StatusBadge tone={template.is_active ? 'success' : 'neutral'}>
                        {template.is_active
                            ? t('notifications.templates.active')
                            : t('notifications.templates.inactive')}
                    </StatusBadge>
                    <span
                        className="text-(length:--text-sm) text-(--text-muted)"
                        title={absoluteTime(template.updated_at, locale) ?? undefined}
                    >
                        {relativeTime(template.updated_at, locale)}
                    </span>
                </div>

                {mayUpdate ? (
                    <div className="flex flex-wrap gap-2">
                        <Button
                            loading={save.isPending}
                            onClick={() => save.mutate({ is_active: !template.is_active })}
                            size="sm"
                            variant={template.is_active ? 'secondary' : 'primary'}
                        >
                            {template.is_active
                                ? t('notifications.templates.deactivate')
                                : t('notifications.templates.activate')}
                        </Button>
                    </div>
                ) : (
                    <Alert tone="info">{t('notifications.templates.readOnly')}</Alert>
                )}

                {!template.is_active ? (
                    <p className="text-(length:--text-sm) text-(--text-muted)">
                        {t('notifications.templates.inactiveNote')}
                    </p>
                ) : null}

                <section className="flex flex-col gap-2 border-t border-(--border-default) pt-3">
                    <h4 data-eyebrow>{t('notifications.templates.wording')}</h4>

                    {tabs.length > 1 ? (
                        <SegmentedControl<string>
                            label={t('notifications.templates.language')}
                            onChange={setTab}
                            options={tabs.map((candidate) => ({
                                value: candidate.code,
                                label: candidate.label,
                            }))}
                            value={tab}
                        />
                    ) : null}

                    {stored.has(tab) ? null : (
                        <p className="text-(length:--text-sm) text-(--text-muted)">
                            {t('notifications.templates.noWording')}
                        </p>
                    )}

                    <Field label={t('notifications.templates.subject')}>
                        {({ id, 'aria-describedby': describedBy }) => (
                            <Input
                                aria-describedby={describedBy}
                                disabled={!mayUpdate}
                                id={id}
                                lang={tab}
                                onChange={(event) => set({ subject: event.target.value })}
                                value={current.subject}
                            />
                        )}
                    </Field>

                    <Field
                        hint={t('notifications.templates.bodyHint')}
                        label={t('notifications.templates.body')}
                    >
                        {({ id, 'aria-describedby': describedBy }) => (
                            <textarea
                                aria-describedby={describedBy}
                                className="min-h-40 w-full border border-(--border-strong) bg-(--surface-default) p-2 text-(length:--text-base) text-(--text-primary) focus-visible:outline-2 focus-visible:outline-offset-0 focus-visible:outline-(--focus-ring) disabled:cursor-not-allowed disabled:opacity-50"
                                disabled={!mayUpdate}
                                id={id}
                                lang={tab}
                                onChange={(event) => set({ body: event.target.value })}
                                value={current.body}
                            />
                        )}
                    </Field>

                    {incomplete.length > 0 ? (
                        <Alert tone="warning">
                            {t('notifications.templates.incomplete', {
                                languages: incomplete.map(([code]) => code).join(', '),
                            })}
                        </Alert>
                    ) : null}

                    {save.error instanceof ApiError ? (
                        <Alert tone="danger">{save.error.message}</Alert>
                    ) : null}

                    {save.isSuccess && changed.length === 0 ? (
                        <Alert tone="success">{t('notifications.templates.saved')}</Alert>
                    ) : null}

                    {mayUpdate ? (
                        <div className="flex flex-wrap gap-2">
                            <Button
                                disabled={changed.length === 0 || incomplete.length > 0}
                                loading={save.isPending}
                                onClick={() =>
                                    save.mutate({
                                        translations: changed.map(([code, wording]) => ({
                                            locale: code,
                                            subject: wording.subject,
                                            body: wording.body,
                                        })),
                                    })
                                }
                                variant="primary"
                            >
                                {t('notifications.templates.save', { count: changed.length })}
                            </Button>
                            <Button
                                disabled={changed.length === 0}
                                onClick={() => setDraft({})}
                                variant="ghost"
                            >
                                {t('notifications.templates.discard')}
                            </Button>
                        </div>
                    ) : null}
                </section>
            </div>
        </div>
    );
}
