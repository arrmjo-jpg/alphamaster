import i18n from 'i18next';
import { initReactI18next } from 'react-i18next';

import source from '@catalogue/console/en.json';

/**
 * Every user-visible string goes through here.
 *
 * The console's catalogue is the platform's Interface Translation Catalog (ADR 0049), kept in
 * `backend/lang/interface/console` so the platform can offer its keys for translation. Its
 * English file is the source: it defines every key the console has, and it is the only
 * catalogue bundled here, because it is the fallback that must render even when the API
 * cannot be reached.
 *
 * Which languages exist is not decided here. Language Management owns the list, its direction
 * and its default, and `DirectionProvider` reads it from `/api/v1/languages`; each language's
 * wording is fetched from the platform when it is chosen (`shell/interfaceCatalogue.ts`).
 */

/**
 * The language the console's catalogue is written in, and every other language is translated
 * from. A fact about the source, not a list of what the console can be read in.
 */
export const CATALOGUE_LOCALE = 'en';

void i18n.use(initReactI18next).init({
    resources: {
        [CATALOGUE_LOCALE]: { common: source },
    },
    lng: CATALOGUE_LOCALE,
    fallbackLng: CATALOGUE_LOCALE,
    defaultNS: 'common',
    interpolation: { escapeValue: false },
    react: { useSuspense: false },
    // A key a language has translated to nothing is not translated: English is shown.
    returnEmptyString: false,
});

export default i18n;
