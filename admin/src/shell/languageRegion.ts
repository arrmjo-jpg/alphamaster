/**
 * Where a language's flag comes from, when it has one.
 *
 * A flag is a country, and a language is not a country. Arabic is not Jordan and
 * English is not the United States — most languages are spoken in many places, and
 * several countries have many languages. So nothing here maps a language to a flag.
 * What it does is narrower and defensible: if the platform has told us *which region*
 * a language entry is for, that region has a flag, and we show it.
 *
 * There is deliberately no table of languages in this file. A lookup from `ar` to 🇯🇴
 * would be this console inventing a fact the platform never asserted, wrong for every
 * Arabic speaker outside Jordan, and it would have to be maintained by hand for every
 * language ever added. The flag is derived from region metadata or it is not shown.
 *
 * Two sources are read, in order, and both are optional:
 *
 *   1. an explicit `region` (or `country_code`) on the language record — the cleaner
 *      contract, and the one that does not exist yet;
 *   2. the region subtag of the language's own `code`, per BCP 47 — `ar-JO` is Arabic
 *      as written in Jordan, and that *is* an assertion about a region. `code` is a
 *      free string of 2 to 10 characters in the current API, so this needs nothing
 *      added to the backend to start working.
 *
 * When neither is present the caller shows a neutral globe. That is the common case
 * today and it is the correct output, not a fallback that failed.
 */

/** The shape this reads. Deliberately looser than `LanguageResource`. */
export interface RegionBearing {
    code: string;
    /**
     * Not in `LanguageResource` today. Declared optional so that the moment the API
     * grows it, flags appear with no change here — and so that reading it cannot be
     * mistaken for a claim that it already exists.
     */
    region?: string | null;
    country_code?: string | null;
}

/**
 * Codes ISO 3166-1 reserves for private use, so none of them is ever a country.
 *
 * `AA`, `QM`–`QZ`, `XA`–`XZ` and `ZZ`. CLDR resolves some of them to a name — `ZZ` is
 * "Unknown Region" and `XA` is "Pseudo-Accents", a test locale — which is exactly why
 * asking whether a name came back is not enough on its own.
 */
const PRIVATE_USE = /^(AA|Q[M-Z]|X[A-Z]|ZZ)$/;

/**
 * Regions CLDR names but Unicode defines no flag for.
 *
 * The short list of genuine exceptions, and each is here for a reason rather than to
 * pad a deny-list:
 *
 *   UK — everyday abbreviation, exceptionally reserved; the flag lives on `GB`
 *   EZ — the Eurozone, an economic area; `EU` is the one with a flag
 *   XK — Kosovo, which has no code point assigned to it
 *   ZR — Zaire, withdrawn when the country was renamed
 *
 * Without this, each renders as two empty boxes where a flag was promised. `EU`, `UN`,
 * `AC` and `TA` are deliberately absent: unusual as regions, but all four do have a
 * flag, so all four are allowed.
 */
const NAMED_BUT_UNFLAGGED = new Set(['UK', 'EZ', 'XK', 'ZR']);

/**
 * Whether a string is a region a flag can actually be drawn for.
 *
 * Three gates, and all three are needed. Two ASCII letters, because that is the shape
 * of an alpha-2 code — which also refuses a three-digit UN M.49 region such as the
 * `419` in `es-419`, Latin American Spanish: a real region, and no flag for a
 * continent. Then not a private-use code. Then known to CLDR, which is what rejects
 * `XX` and every other unassigned pair.
 *
 * The check matters because the arithmetic below is happy to compose any two letters
 * into a sequence, and an unassigned one renders as a pair of empty boxes — a promise
 * of visual identification that delivers noise.
 */
export function isFlaggableRegion(value: string): boolean {
    if (!/^[A-Za-z]{2}$/.test(value)) {
        return false;
    }

    const region = value.toUpperCase();

    if (PRIVATE_USE.test(region) || NAMED_BUT_UNFLAGGED.has(region)) {
        return false;
    }

    try {
        const names = new Intl.DisplayNames(['en'], { type: 'region' });

        // Pinned to English so the comparison is deterministic: for a code it has no
        // data for, `of` hands back the code unchanged.
        return names.of(region) !== region;
    } catch {
        // An environment without the region data, or one that threw on the input.
        // Refusing is the safe answer: no flag beats a wrong flag.
        return false;
    }
}

/**
 * The region a language entry is for, or null when it has not said.
 */
export function regionOf(language: RegionBearing): string | null {
    const declared = language.region ?? language.country_code ?? null;

    if (typeof declared === 'string' && isFlaggableRegion(declared)) {
        return declared.toUpperCase();
    }

    // BCP 47 orders subtags language-script-region, so the region is never the first
    // one: `ar` alone is a language and must not be read as a country. Underscores are
    // tolerated because they are how a locale gets written in half the world's config
    // files, and an operator typing `ar_JO` means the same thing.
    const subtags = language.code.split(/[-_]/).slice(1);

    for (const subtag of subtags) {
        if (isFlaggableRegion(subtag)) {
            return subtag.toUpperCase();
        }
    }

    return null;
}

/**
 * The flag for a region, composed rather than looked up.
 *
 * A regional-indicator pair: the two letters offset into U+1F1E6..U+1F1FF, which every
 * platform renders as that country's flag. It is arithmetic on the region code, so
 * there is no list to keep in step with the world — a region the platform starts
 * serving tomorrow gets its flag today.
 */
export function flagOf(region: string): string | null {
    if (!isFlaggableRegion(region)) {
        return null;
    }

    const BASE = 0x1f1e6;
    const A = 'A'.charCodeAt(0);

    return [...region.toUpperCase()]
        .map((letter) => String.fromCodePoint(BASE + (letter.charCodeAt(0) - A)))
        .join('');
}

/**
 * The flag for a language entry, or null when it has claimed no region.
 */
export function flagForLanguage(language: RegionBearing): string | null {
    const region = regionOf(language);

    return region === null ? null : flagOf(region);
}
