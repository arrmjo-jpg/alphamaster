import { Monitor, Moon, Sun } from 'lucide-react';
import { useTranslation } from 'react-i18next';

import { isSupportedLocale, SUPPORTED_LOCALES, type SupportedLocale } from '@/i18n';
import { SegmentedControl } from '@/ui/SegmentedControl';

import { useDensity, type Density } from './DensityProvider';
import { useDirection } from './DirectionProvider';
import { useTheme, type ThemePreference } from './ThemeProvider';

const LOCALE_LABELS: Record<SupportedLocale, string> = { en: 'English', ar: 'العربية' };

export function ThemeControl() {
    const { t } = useTranslation();
    const { preference, setPreference } = useTheme();

    return (
        <SegmentedControl<ThemePreference>
            label={t('theme.label')}
            onChange={setPreference}
            options={[
                { value: 'light', label: t('theme.light'), icon: <Sun className="size-3.5" /> },
                { value: 'dark', label: t('theme.dark'), icon: <Moon className="size-3.5" /> },
                {
                    value: 'system',
                    label: t('theme.system'),
                    icon: <Monitor className="size-3.5" />,
                },
            ]}
            value={preference}
        />
    );
}

export function DensityControl() {
    const { t } = useTranslation();
    const { density, setDensity } = useDensity();

    return (
        <SegmentedControl<Density>
            label={t('density.label')}
            onChange={setDensity}
            options={[
                { value: 'compact', label: t('density.compact') },
                { value: 'comfortable', label: t('density.comfortable') },
                { value: 'spacious', label: t('density.spacious') },
            ]}
            value={density}
        />
    );
}

/**
 * The languages this console can be read in, as the platform currently serves them.
 *
 * Driven by `/languages` rather than by the shipped catalogue list: a language an
 * administrator has deactivated is one the platform no longer serves, and offering it
 * here would be offering to render the console in a language the API will not answer
 * in. The label is the platform's own `native_name` — a language names itself, and the
 * two hard-coded strings this replaces could only ever have named two.
 *
 * The static list is the fallback for the one case where there is no answer: the API
 * is unreachable, and a switcher with nothing in it is worse than one offering what the
 * bundle can certainly render.
 */
export function LocaleControl() {
    const { t } = useTranslation();
    const { locale, available, setLocale } = useDirection();

    const options =
        available.length > 0
            ? available
                  .filter((language) => isSupportedLocale(language.code))
                  .map((language) => ({
                      value: language.code as SupportedLocale,
                      label: language.native_name,
                  }))
            : SUPPORTED_LOCALES.map((code) => ({ value: code, label: LOCALE_LABELS[code] }));

    // One option is not a choice. It still has to be announced, though, or an operator
    // is left wondering where the switcher went.
    if (options.length <= 1) {
        return (
            <p className="text-(length:--text-sm) text-(--text-on-chrome-muted)">
                {t('language.only', { name: options[0]?.label ?? locale })}
            </p>
        );
    }

    return (
        <SegmentedControl<SupportedLocale>
            label={t('language.label')}
            onChange={setLocale}
            options={options}
            value={locale}
        />
    );
}
