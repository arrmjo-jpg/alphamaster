import { Monitor, Moon, Sun } from 'lucide-react';
import { useTranslation } from 'react-i18next';

import { SUPPORTED_LOCALES, type SupportedLocale } from '@/i18n';
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

export function LocaleControl() {
    const { t } = useTranslation();
    const { locale, setLocale } = useDirection();

    return (
        <SegmentedControl<SupportedLocale>
            label={t('language.label')}
            onChange={setLocale}
            options={SUPPORTED_LOCALES.map((code) => ({ value: code, label: LOCALE_LABELS[code] }))}
            value={locale}
        />
    );
}
