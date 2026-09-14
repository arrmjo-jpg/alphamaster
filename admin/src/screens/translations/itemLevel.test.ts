import { describe, expect, it } from 'vitest';

/**
 * What the translation workshop is not allowed to contain (ADR 0056), read from the source.
 *
 * The rendered tests show one accept per item and one request for "translate all missing".
 * These make the absence permanent: no field-level call to the API, no confirmation step, no
 * dialog, and no content language written into the console's code.
 */

const workshopSources = import.meta.glob(
    ['../TranslationsScreen.tsx', './TranslationEntryRow.tsx', './api.ts'],
    { query: '?raw', import: 'default', eager: true },
);

const applicationSources = import.meta.glob(
    [
        '../../**/*.{ts,tsx}',
        '!../../**/*.test.{ts,tsx}',
        '!../../api/generated/**',
        '!../../test/**',
    ],
    { query: '?raw', import: 'default', eager: true },
);

describe('item-level translation, in the code', () => {
    it('reads the three files the workshop is made of', () => {
        expect(Object.keys(workshopSources)).toHaveLength(3);
    });

    it('never calls a field-level endpoint', () => {
        for (const [file, source] of Object.entries(workshopSources)) {
            expect(source, file).not.toMatch(/translations\/suggestions/);
            expect(source, file).not.toMatch(/\/fields?\//);
        }
    });

    it('asks for no confirmation and opens no dialog, for an item or for a field', () => {
        for (const [file, source] of Object.entries(workshopSources)) {
            expect(source, file).not.toMatch(/\bconfirm\(/);
            expect(source, file).not.toMatch(/Dialog|Modal|role="dialog"/);
        }
    });

    it('translates and accepts by language, source, item or batch — never by field', () => {
        const api =
            Object.entries(workshopSources).find(([file]) => file.endsWith('api.ts'))?.[1] ?? '';

        expect(api).toMatch(/export async function translateWithAi\(\s*body: TranslationRequest/);
        expect(api).toMatch(/export async function acceptTranslation\(\s*batchId: string/);
        expect(api).toMatch(/export async function acceptAllReady\(/);
        expect(api).not.toMatch(/function (accept|translate|dismiss)\w*Field/i);
    });
});

describe('languages are data, in the code', () => {
    const list = /\[\s*'[a-z]{2,3}(?:-[A-Za-z]{2})?'\s*(?:,\s*'[a-z]{2,3}(?:-[A-Za-z]{2})?'\s*)+\]/;
    const fixedDirection = /['"](?:ar|he|fa|ur)['"]\s*:\s*['"]rtl['"]|=== ['"](?:ar|he|fa|ur)['"]/;

    it('reads the application', () => {
        expect(Object.keys(applicationSources).length).toBeGreaterThan(50);
        expect(Object.keys(applicationSources)).toContain('../../i18n/index.ts');
    });

    it('finds a language list or a direction tied to a language when one is planted', () => {
        // The control, before the result is trusted.
        expect("export const SUPPORTED_LOCALES = ['en', 'ar'];").toMatch(list);
        expect("const KNOWN_DIRECTIONS = { en: 'ltr', 'ar': 'rtl' };").toMatch(fixedDirection);
    });

    it('holds no list of languages anywhere — interface or content — and ties no direction to one', () => {
        // Language Management is the one source of languages and their direction (ADR 0049).
        for (const [file, source] of Object.entries(applicationSources)) {
            expect(source, file).not.toMatch(list);
            expect(source, file).not.toMatch(fixedDirection);
            expect(source, file).not.toMatch(/SUPPORTED_LOCALES|isSupportedLocale/);
        }
    });
});
