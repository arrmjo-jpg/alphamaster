import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';

import {
    initialContentLanguage,
    orderedLanguages,
    translationStatus,
    type ContentLanguageOption,
} from '@/lib/contentLanguages';

import { ContentLanguageSelector } from './ContentLanguageSelector';
import { TranslationStatusList } from './TranslationStatusList';

import '@/i18n';

/**
 * The shared content-language controls (ADR 0055 §8): Settings, Pages and Team read the same
 * list from Language Management and show translation status the same way.
 */

const LANGUAGES: ContentLanguageOption[] = [
    { code: 'en', native_name: 'English', is_default: false, is_active: true, sort_order: 1 },
    { code: 'fr', native_name: 'Français', is_default: false, is_active: false, sort_order: 3 },
    { code: 'ar', native_name: 'العربية', is_default: true, is_active: true, sort_order: 2 },
];

describe('content language selector', () => {
    it('puts the platform default first, then the configured order', () => {
        expect(orderedLanguages(LANGUAGES).map((language) => language.code)).toEqual([
            'ar',
            'en',
            'fr',
        ]);
        expect(initialContentLanguage(LANGUAGES)).toBe('ar');
        expect(initialContentLanguage([])).toBeNull();
    });

    it('offers every known language, marks one that is not served, and reports the choice', async () => {
        const onChange = vi.fn();

        render(
            <ContentLanguageSelector
                id="lang"
                languages={LANGUAGES}
                onChange={onChange}
                value="ar"
            />,
        );

        const selector = screen.getByLabelText('Content language');
        const options = within(selector).getAllByRole('option');

        expect(options.map((option) => option.textContent)).toEqual([
            'العربية',
            'English',
            'Français (draft — not served)',
        ]);

        await userEvent.selectOptions(selector, 'fr');

        expect(onChange).toHaveBeenCalledWith('fr');
    });
});

describe('translation status list', () => {
    it('distinguishes translated, incomplete and not translated, counted from what is saved', () => {
        expect(translationStatus(undefined)).toBe('missing');
        expect(translationStatus({ filled: 0, total: 4, complete: false })).toBe('missing');
        expect(translationStatus({ filled: 2, total: 4, complete: false })).toBe('incomplete');
        expect(translationStatus({ filled: 3, total: 4, complete: true })).toBe('translated');
    });

    it('lists each language with its state, and switches to one when asked', async () => {
        const onSelect = vi.fn();

        render(
            <TranslationStatusList
                current="ar"
                languages={LANGUAGES}
                onSelect={onSelect}
                states={{
                    ar: { filled: 4, total: 4, complete: true },
                    en: { filled: 1, total: 4, complete: false },
                }}
            />,
        );

        const list = screen.getByRole('list', { name: 'Translation status' });
        const rows = within(list).getAllByRole('button');

        expect(rows[0]).toHaveTextContent('العربية');
        expect(rows[0]).toHaveTextContent('Translated');
        expect(rows[0]).toHaveAttribute('aria-current', 'true');
        expect(rows[1]).toHaveTextContent('Incomplete (1 of 4)');
        expect(rows[2]).toHaveTextContent('Not translated');
        expect(rows[2]).toHaveTextContent('Draft — not served');

        await userEvent.click(rows[2] as HTMLElement);

        expect(onSelect).toHaveBeenCalledWith('fr');
    });
});
