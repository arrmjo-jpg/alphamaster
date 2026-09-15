import { AlertCircle, CheckCircle2, CircleDashed } from 'lucide-react';
import { useTranslation } from 'react-i18next';

import { cn } from '@/lib/cn';
import {
    orderedLanguages,
    translationStatus,
    type ContentLanguageOption,
    type TranslationState,
} from '@/lib/contentLanguages';

export interface TranslationStatusListProps {
    languages: readonly ContentLanguageOption[];
    /** Keyed by language code. A language absent from the map has nothing written. */
    states: Readonly<Record<string, TranslationState>>;
    /** The language being edited, marked in the list. */
    current?: string | null;
    /** Makes each language a button that switches to it. */
    onSelect?: (code: string) => void;
    className?: string;
}

/**
 * Each language, and how far its translation has got (ADR 0055 §8).
 *
 * Three states and no others: translated, incomplete (with how many fields), not translated.
 * A language that is not served yet says that too, so an operator can tell "missing" from
 * "missing, and nobody can see it yet".
 */
export function TranslationStatusList({
    languages,
    states,
    current = null,
    onSelect,
    className,
}: TranslationStatusListProps) {
    const { t } = useTranslation();

    return (
        <ul aria-label={t('content.status.title')} className={cn('flex flex-col gap-1', className)}>
            {orderedLanguages(languages).map((language) => {
                const state = states[language.code];
                const status = translationStatus(state);
                const Icon =
                    status === 'translated'
                        ? CheckCircle2
                        : status === 'incomplete'
                          ? CircleDashed
                          : AlertCircle;

                const content = (
                    <>
                        <span className="min-w-0 flex-1 truncate">{language.native_name}</span>
                        <span
                            className={cn(
                                'inline-flex items-center gap-1 text-(length:--text-xs)',
                                status === 'translated'
                                    ? 'text-(--state-success-text)'
                                    : 'text-(--state-warning-text)',
                            )}
                        >
                            <Icon aria-hidden className="size-3.5" />
                            {status === 'incomplete' && state !== undefined
                                ? t('content.status.incomplete', {
                                      filled: state.filled,
                                      total: state.total,
                                  })
                                : t(`content.status.${status}`)}
                        </span>
                        {!language.is_active ? (
                            <span className="text-(length:--text-2xs) text-(--text-muted)">
                                {t('content.language.draft')}
                            </span>
                        ) : null}
                    </>
                );

                return (
                    <li key={language.code}>
                        {onSelect === undefined ? (
                            <div className="flex items-center gap-2 px-2 py-1 text-(length:--text-sm)">
                                {content}
                            </div>
                        ) : (
                            <button
                                aria-current={current === language.code ? 'true' : undefined}
                                className={cn(
                                    'flex w-full items-center gap-2 border-s-(length:--rail-width) px-2 py-1 text-start text-(length:--text-sm)',
                                    current === language.code
                                        ? 'border-(--action-primary) bg-(--surface-subtle)'
                                        : 'border-transparent hover:bg-(--surface-subtle)',
                                )}
                                onClick={() => onSelect(language.code)}
                                type="button"
                            >
                                {content}
                            </button>
                        )}
                    </li>
                );
            })}
        </ul>
    );
}
