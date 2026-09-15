import { useTranslation } from 'react-i18next';

import { cn } from '@/lib/cn';
import { orderedLanguages, type ContentLanguageOption } from '@/lib/contentLanguages';

export interface ContentLanguageSelectorProps {
    id: string;
    languages: readonly ContentLanguageOption[];
    /** The language whose content is being read and written; null until one is chosen. */
    value: string | null;
    onChange: (code: string) => void;
    disabled?: boolean;
    /** Replaces the default hint, for a screen that needs to say something more specific. */
    hint?: string;
    className?: string;
}

/**
 * Which language's content is being edited (ADR 0043 §4a, ADR 0055 §8).
 *
 * The languages are Language Management's, every one the platform knows, and never a list a
 * screen keeps. The default language comes first because it is the one a page cannot be
 * published without; the rest follow in their configured order. A language that is not served
 * yet is still offered — content is written before a language goes live (ADR 0048) — and says
 * so beside its name.
 *
 * This is the content language only. The console's own language is not touched by it, and the
 * two never share a parameter.
 */
export function ContentLanguageSelector({
    id,
    languages,
    value,
    onChange,
    disabled = false,
    hint,
    className,
}: ContentLanguageSelectorProps) {
    const { t } = useTranslation();

    return (
        <div className={cn('flex flex-col gap-1', className)}>
            <label
                className="text-(length:--text-sm) font-medium text-(--text-secondary)"
                htmlFor={id}
            >
                {t('settings.contentLanguage')}
            </label>
            <select
                className="h-(--field-height) w-full max-w-xs border border-(--border-strong) bg-(--surface-default) px-2 text-(length:--text-sm) text-(--text-primary) focus-visible:outline-2 focus-visible:outline-offset-0 focus-visible:outline-(--focus-ring) disabled:cursor-not-allowed disabled:opacity-50"
                disabled={disabled}
                id={id}
                onChange={(event) => onChange(event.target.value)}
                value={value ?? ''}
            >
                {orderedLanguages(languages).map((language) => (
                    <option key={language.code} value={language.code}>
                        {language.is_active
                            ? language.native_name
                            : t('content.language.draftOption', { name: language.native_name })}
                    </option>
                ))}
            </select>
            <p className="text-(length:--text-xs) text-(--text-muted)">
                {hint ?? t('settings.contentLanguageHint')}
            </p>
        </div>
    );
}
