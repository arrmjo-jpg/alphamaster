import i18n from 'i18next';
import { initReactI18next } from 'react-i18next';

import arCommon from './ar/common.json';
import enCommon from './en/common.json';

/**
 * Every user-visible string goes through here.
 *
 * The catalogues are the platform's two shipped languages. Which one is *active*
 * is not decided here — the backend owns the language list and its direction
 * (ADR 0015), and DirectionProvider reads it from `/api/v1/languages`.
 */
export const SUPPORTED_LOCALES = ['en', 'ar'] as const;

export type SupportedLocale = (typeof SUPPORTED_LOCALES)[number];

export const FALLBACK_LOCALE: SupportedLocale = 'en';

export function isSupportedLocale(value: string): value is SupportedLocale {
    return (SUPPORTED_LOCALES as readonly string[]).includes(value);
}

void i18n.use(initReactI18next).init({
    resources: {
        en: { common: enCommon },
        ar: { common: arCommon },
    },
    lng: FALLBACK_LOCALE,
    fallbackLng: FALLBACK_LOCALE,
    defaultNS: 'common',
    interpolation: { escapeValue: false },
    react: { useSuspense: false },
});

export default i18n;
