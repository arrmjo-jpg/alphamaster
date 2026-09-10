import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';

import { ApiError } from '@/api/errors';
import { TranslationEntryRow } from '@/screens/translations/TranslationEntryRow';
import {
    workshop as fetchWorkshop,
    writeTranslation,
    type TranslationLocale,
    type TranslationSource,
} from '@/screens/translations/api';
import { Alert } from '@/ui/Alert';
import { Checkbox } from '@/ui/Checkbox';
import { StateRail } from '@/ui/StateRail';

/**
 * What the platform says, in every language it says it in.
 *
 * The languages workspace manages *which* languages exist. This is what is written in
 * them, and the two were never the same thing: adding Arabic to the language list did
 * nothing to the roles, the settings copy or the notification wording, and no screen
 * anywhere reported that.
 *
 * Three bodies of content appear here and they belong to three other modules. This
 * screen renders what the platform declares rather than knowing what any of them are
 * (ADR 0043), so a module that becomes translatable later appears without an edit
 * here.
 *
 * Permissions are per body of content, not per screen. Translating notification
 * wording *is* editing notification wording, so an operator without
 * `notifications.update` sees it and cannot save it — and an operator without
 * `notifications.view` does not see it at all. The API decides both; this reflects
 * the decision rather than making one.
 */
export function TranslationsScreen() {
    const { t } = useTranslation();
    const queryClient = useQueryClient();

    const [targetCode, setTargetCode] = useState<string | null>(null);
    const [untranslatedOnly, setUntranslatedOnly] = useState(false);

    const state = useQuery({
        queryKey: ['translations'],
        queryFn: ({ signal }) => fetchWorkshop(signal),
    });

    const save = useMutation({
        mutationFn: ({
            source,
            id,
            locale,
            values,
        }: {
            source: string;
            id: string;
            locale: string;
            values: Record<string, string>;
        }) => writeTranslation(source, id, { locale, values }),
        onSuccess: () => queryClient.invalidateQueries({ queryKey: ['translations'] }),
    });

    if (state.isPending) {
        return <StateRail tone="info">{t('state.loading')}</StateRail>;
    }

    if (state.error !== null) {
        return (
            <Alert tone="danger">
                {state.error instanceof ApiError ? state.error.message : t('state.error')}
            </Alert>
        );
    }

    const { locales, sources } = state.data;

    // The default language is what everything else is translated *from*: it is the one
    // the platform falls back to, so it is the one an operator is reading when they
    // write another.
    const source = locales.find((language) => language.is_default) ?? locales[0];
    const targets = locales.filter((language) => language.code !== source?.code);
    const target = targets.find((language) => language.code === targetCode) ?? targets[0];

    if (source === undefined || target === undefined) {
        return (
            <div className="flex min-w-0 flex-col gap-(--section-gap)">
                <Header />
                <Alert tone="info">{t('translations.oneLanguage')}</Alert>
            </div>
        );
    }

    return (
        <div className="flex min-w-0 flex-col gap-(--section-gap)">
            <Header />

            <section className="flex flex-wrap items-end gap-4 border border-(--border-default) bg-(--surface-raised) p-3">
                <div className="flex flex-col gap-1">
                    <span className="text-(length:--text-xs) text-(--text-muted)">
                        {t('translations.from')}
                    </span>
                    {/* Not a control. There is one default language and it is the one
                        everything falls back to; offering a choice here would imply
                        the platform could be read from somewhere else. */}
                    <span className="text-(length:--text-sm) text-(--text-primary)">
                        {source.native_name}
                    </span>
                </div>

                <div className="flex flex-col gap-1">
                    <label
                        className="text-(length:--text-xs) text-(--text-muted)"
                        htmlFor="translation-target"
                    >
                        {t('translations.into')}
                    </label>
                    <select
                        className="h-(--field-height) border border-(--border-strong) bg-(--surface-default) px-2 text-(length:--text-sm) text-(--text-primary) focus-visible:outline-2 focus-visible:outline-offset-0 focus-visible:outline-(--focus-ring)"
                        id="translation-target"
                        onChange={(event) => setTargetCode(event.target.value)}
                        value={target.code}
                    >
                        {targets.map((language) => (
                            <option key={language.code} value={language.code}>
                                {language.native_name}
                            </option>
                        ))}
                    </select>
                </div>

                <label className="flex items-center gap-2 text-(length:--text-sm) text-(--text-secondary)">
                    <Checkbox
                        checked={untranslatedOnly}
                        onChange={(event) => setUntranslatedOnly(event.target.checked)}
                    />
                    {t('translations.untranslatedOnly')}
                </label>
            </section>

            {sources.length === 0 ? (
                <Alert tone="info">{t('translations.nothingReadable')}</Alert>
            ) : null}

            {sources.map((body) => (
                <SourceSection
                    body={body}
                    key={body.key}
                    onSave={(id, values) =>
                        save.mutateAsync({
                            source: body.key,
                            id,
                            locale: target.code,
                            values,
                        })
                    }
                    source={source}
                    target={target}
                    untranslatedOnly={untranslatedOnly}
                />
            ))}
        </div>
    );
}

function Header() {
    const { t } = useTranslation();

    return (
        <header>
            <p data-eyebrow>{t('translations.eyebrow')}</p>
            <h1 className="text-(length:--text-2xl) text-(--text-primary)">
                {t('modules.translations')}
            </h1>
            <p className="mt-1 max-w-prose text-(length:--text-sm) text-(--text-secondary)">
                {t('translations.description')}
            </p>
        </header>
    );
}

interface SourceSectionProps {
    body: TranslationSource;
    source: TranslationLocale;
    target: TranslationLocale;
    untranslatedOnly: boolean;
    onSave: (id: string, values: Record<string, string>) => Promise<void>;
}

function SourceSection({ body, source, target, untranslatedOnly, onSave }: SourceSectionProps) {
    const { t } = useTranslation();

    const counts = body.completeness[target.code] ?? { total: 0, translated: 0 };
    const outstanding = counts.total - counts.translated;

    const entries = untranslatedOnly
        ? body.entries.filter((entry) =>
              entry.fields.some((field) => (field.values[target.code] ?? '').trim() === ''),
          )
        : body.entries;

    return (
        <section className="flex min-w-0 flex-col gap-2 border border-(--border-default) bg-(--surface-raised) p-4">
            <div className="flex flex-wrap items-baseline justify-between gap-2">
                <h2 className="text-(length:--text-md) font-medium text-(--text-primary)">
                    {body.label}
                </h2>
                {/* Fields, not items. A template with an Arabic subject over an English
                    body is not half translated in any sense a recipient would
                    recognise, and the number says so. */}
                <p className="text-(length:--text-sm) text-(--text-secondary)">
                    {outstanding === 0
                        ? t('translations.complete', { language: target.native_name })
                        : t('translations.outstanding', {
                              count: outstanding,
                              total: counts.total,
                          })}
                </p>
            </div>

            {!body.may_write ? (
                <p className="text-(length:--text-xs) text-(--text-muted)">
                    {t('translations.readOnly')}
                </p>
            ) : null}

            {entries.length === 0 ? (
                <p className="py-2 text-(length:--text-sm) text-(--text-muted)">
                    {untranslatedOnly ? t('translations.noneOutstanding') : t('state.empty')}
                </p>
            ) : (
                entries.map((entry) => (
                    <TranslationEntryRow
                        entry={entry}
                        key={entry.id}
                        mayWrite={body.may_write}
                        onSave={(values) => onSave(entry.id, values)}
                        source={source}
                        target={target}
                    />
                ))
            )}
        </section>
    );
}
