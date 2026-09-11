import { describe, expect, it } from 'vitest';

import { flagForLanguage, flagOf, isFlaggableRegion, regionOf } from './languageRegion';

/**
 * A flag is a country and a language is not a country.
 *
 * Most of what follows is about what this refuses. The one thing it must do is read a
 * region the platform has actually asserted; the many things it must not do are invent
 * one, guess one from the language, or compose two letters into a flag that renders as
 * a pair of empty boxes.
 */

describe('reading a region the platform asserted', () => {
    it('takes the region subtag of a BCP 47 code', () => {
        expect(regionOf({ code: 'ar-JO' })).toBe('JO');
        expect(regionOf({ code: 'en-GB' })).toBe('GB');
        expect(regionOf({ code: 'pt-BR' })).toBe('BR');
    });

    it('accepts an underscore, because that is how half the world writes a locale', () => {
        expect(regionOf({ code: 'ar_JO' })).toBe('JO');
    });

    it('normalises case, so a hand-typed code still resolves', () => {
        expect(regionOf({ code: 'ar-jo' })).toBe('JO');
    });

    it('skips a script subtag to reach the region behind it', () => {
        // language-script-region. `Latn` is not a region and must not be read as one.
        expect(regionOf({ code: 'sr-Latn-RS' })).toBe('RS');
    });

    it('prefers an explicit field over the code, for when the API grows one', () => {
        expect(regionOf({ code: 'ar', region: 'JO' })).toBe('JO');
        expect(regionOf({ code: 'ar', country_code: 'EG' })).toBe('EG');
    });
});

describe('what it refuses to call a region', () => {
    it('reads a bare language code as a language, not a country', () => {
        // The whole point. `ar` is Arabic — spoken across twenty-odd countries — and
        // nothing here is entitled to pick one of them.
        expect(regionOf({ code: 'ar' })).toBeNull();
        expect(regionOf({ code: 'en' })).toBeNull();
        expect(regionOf({ code: 'fr' })).toBeNull();
    });

    it('never treats the first subtag as a region, even when it looks like one', () => {
        // `it` is Italian and also Italy's region code. Position decides, not shape.
        expect(regionOf({ code: 'it' })).toBeNull();
        expect(regionOf({ code: 'de' })).toBeNull();
    });

    it('refuses a UN M.49 numeric region, which is real and has no flag', () => {
        // es-419 is Latin American Spanish. There is no flag for a continent.
        expect(regionOf({ code: 'es-419' })).toBeNull();
    });

    it('refuses a region code ISO 3166-1 reserves for private use', () => {
        // Whatever CLDR happens to call them: `ZZ` resolves to "Unknown Region" and
        // `XA` to "Pseudo-Accents", a test locale. A check that only asked whether a
        // name came back would let both through.
        expect(isFlaggableRegion('ZZ')).toBe(false);
        expect(isFlaggableRegion('XA')).toBe(false);
        expect(isFlaggableRegion('QM')).toBe(false);
        expect(isFlaggableRegion('AA')).toBe(false);
        expect(regionOf({ code: 'en-ZZ' })).toBeNull();
    });

    it('refuses an unassigned pair outright', () => {
        expect(isFlaggableRegion('XX')).toBe(false);
    });

    it('refuses a region that is named but has no flag', () => {
        // All four resolve to a name and none has a flag sequence, so all four would
        // otherwise render as two empty boxes.
        expect(isFlaggableRegion('UK')).toBe(false); // the flag lives on GB
        expect(isFlaggableRegion('EZ')).toBe(false); // the Eurozone is not a country
        expect(isFlaggableRegion('XK')).toBe(false); // Kosovo has no code point
        expect(isFlaggableRegion('ZR')).toBe(false); // Zaire, withdrawn
    });

    it('allows the unusual regions that do have a flag', () => {
        // Not countries in the ordinary sense, and all four are flaggable, so the
        // deny-list above stays a list of real exceptions rather than a habit.
        expect(isFlaggableRegion('EU')).toBe(true);
        expect(isFlaggableRegion('UN')).toBe(true);
        expect(isFlaggableRegion('AC')).toBe(true);
        expect(isFlaggableRegion('TA')).toBe(true);
    });

    it('refuses malformed input rather than composing something from it', () => {
        expect(isFlaggableRegion('')).toBe(false);
        expect(isFlaggableRegion('J')).toBe(false);
        expect(isFlaggableRegion('JOR')).toBe(false);
        expect(isFlaggableRegion('1A')).toBe(false);
        expect(flagOf('ZZ')).toBeNull();
    });

    it('ignores an explicit field holding something that is not a region', () => {
        expect(regionOf({ code: 'ar', region: 'nonsense' })).toBeNull();
        expect(regionOf({ code: 'ar', region: null })).toBeNull();
    });
});

describe('composing the flag', () => {
    it('derives it by arithmetic rather than from a table', () => {
        // Regional indicator pairs. No list to keep in step with the world.
        expect(flagOf('JO')).toBe('🇯🇴');
        expect(flagOf('GB')).toBe('🇬🇧');
        expect(flagOf('FR')).toBe('🇫🇷');
        expect(flagOf('jp')).toBe('🇯🇵');
    });

    it('gives a language its flag only when a region came with it', () => {
        expect(flagForLanguage({ code: 'ar-JO' })).toBe('🇯🇴');
        expect(flagForLanguage({ code: 'ar', region: 'EG' })).toBe('🇪🇬');

        // The state of the platform today: two languages, neither carrying a region.
        expect(flagForLanguage({ code: 'ar' })).toBeNull();
        expect(flagForLanguage({ code: 'en' })).toBeNull();
    });
});
