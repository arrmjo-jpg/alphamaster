import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { RequestHandler } from 'msw';
import { HttpResponse, http } from 'msw';
import { MemoryRouter } from 'react-router';
import { describe, expect, it } from 'vitest';

import { AppProviders } from '@/app/AppProviders';
import { AuthGate } from '@/auth/AuthGate';
import { TranslationsScreen } from '@/screens/TranslationsScreen';
import { observed, server } from '@/test/server';

import '@/i18n';

/**
 * The translation workshop, against the shape the platform actually publishes.
 *
 * What is asserted here is mostly what the screen refuses to do: prefill an empty
 * target with the source, offer a save on content the caller may not write, or send a
 * field nobody touched. Each of those is a way of turning "untranslated" into
 * something that looks finished.
 */

const LANGUAGES = http.get('*/api/v1/languages', () =>
    HttpResponse.json({
        success: true,
        data: [
            {
                code: 'en',
                name: 'English',
                native_name: 'English',
                direction: 'ltr',
                is_default: true,
            },
        ],
    }),
);

const HEALTH = http.get('*/api/v1/health', () =>
    HttpResponse.json({
        success: true,
        data: {
            status: 'healthy',
            timestamp: '2026-09-08T10:00:00+00:00',
            framework: 'Laravel 13',
        },
    }),
);

const LOCALES = [
    { code: 'en', name: 'English', native_name: 'English', direction: 'ltr', is_default: true },
    { code: 'ar', name: 'Arabic', native_name: 'العربية', direction: 'rtl', is_default: false },
];

function settingsSource(overrides: Record<string, unknown> = {}) {
    return {
        key: 'settings',
        label: 'Settings copy',
        may_write: true,
        entries: [
            {
                id: 'general.site_name',
                title: 'Site name',
                context: 'The name a visitor reads.',
                fields: [
                    {
                        name: 'value',
                        label: 'Value',
                        multiline: false,
                        values: { en: 'AlphaMaster' },
                    },
                ],
            },
            {
                id: 'general.tagline',
                title: 'Tagline',
                context: null,
                fields: [
                    {
                        name: 'value',
                        label: 'Value',
                        multiline: false,
                        values: { en: 'Everything in one place', ar: 'كل شيء في مكان واحد' },
                    },
                ],
            },
        ],
        completeness: { en: { total: 2, translated: 2 }, ar: { total: 2, translated: 1 } },
        ...overrides,
    };
}

function renderScreen(sources: unknown[], extra: RequestHandler[] = []) {
    server.use(
        LANGUAGES,
        HEALTH,
        http.get('*/api/v1/auth/me', () =>
            HttpResponse.json({
                success: true,
                data: {
                    id: '01hzzviewer',
                    name: 'Nadia Haddad',
                    email: 'nadia@example.test',
                    account_type: 'admin',
                    is_active: true,
                    email_verified: true,
                    email_verified_at: '2026-01-01T00:00:00+00:00',
                    phone: null,
                    phone_verified: false,
                    phone_verified_at: null,
                    abilities: ['admin:access'],
                    roles: [],
                    permissions: ['settings.view', 'settings.update'],
                },
            }),
        ),
        http.get('*/api/v1/admin/translations', () =>
            HttpResponse.json({ success: true, data: { locales: LOCALES, sources } }),
        ),
        ...extra,
    );

    render(
        <MemoryRouter>
            <AppProviders>
                <AuthGate>
                    <TranslationsScreen />
                </AuthGate>
            </AppProviders>
        </MemoryRouter>,
    );
}

describe('the translation workshop', () => {
    it('translates from the default language into another, without offering a choice of source', async () => {
        renderScreen([settingsSource()]);

        expect(await screen.findByText('Translating from')).toBeInTheDocument();

        // One default language, and it is the one everything falls back to. A picker
        // would imply the platform could be read from somewhere else.
        expect(screen.queryByLabelText('Translating from')).not.toBeInTheDocument();
        expect(screen.getByLabelText('Into')).toHaveValue('ar');
    });

    it('leaves an untranslated field empty rather than seeding it with the source', async () => {
        renderScreen([settingsSource()]);

        // Both entries carry a field with this label, and the first is the one with
        // nothing written for it.
        const field = (await screen.findAllByLabelText(/Value · العربية/))[0]!;

        // Prefilling with the English is how a platform ends up with English inside its
        // Arabic column and no way to tell which of those were deliberate.
        expect(field).toHaveValue('');
    });

    it('counts fields rather than items when it says what is outstanding', async () => {
        renderScreen([settingsSource()]);

        expect(await screen.findByText('1 of 2 fields still untranslated')).toBeInTheDocument();
    });

    it('sends only the field that changed, for the language being written', async () => {
        const sent: Array<Record<string, unknown>> = [];

        renderScreen(
            [settingsSource()],
            [
                http.put('*/api/v1/admin/translations/settings/:id', async ({ request }) => {
                    sent.push((await request.json()) as Record<string, unknown>);

                    return HttpResponse.json({
                        success: true,
                        message: 'saved',
                        data: { source: 'settings', id: 'general.site_name', locale: 'ar' },
                    });
                }),
            ],
        );

        const fields = await screen.findAllByLabelText(/Value · العربية/);

        await userEvent.type(fields[0]!, 'ألفاماستر');
        await userEvent.click(screen.getAllByRole('button', { name: 'Save' })[0]!);

        await screen.findByText('Saved');

        expect(sent).toHaveLength(1);
        expect(sent[0]).toEqual({ locale: 'ar', values: { value: 'ألفاماستر' } });

        const requests = observed.filter((request) =>
            request.url.includes('/admin/translations/settings/'),
        );

        expect(requests[0]?.url).toContain('general.site_name');
    });

    it('offers no way to save content the platform says may not be written', async () => {
        renderScreen([settingsSource({ may_write: false })]);

        expect(
            await screen.findByText('You can read this content but not change it.'),
        ).toBeInTheDocument();

        expect(screen.queryByRole('button', { name: 'Save' })).not.toBeInTheDocument();
        expect(screen.getAllByLabelText(/Value · العربية/)[0]).toBeDisabled();
    });

    it('hides the finished entries when only the outstanding ones are wanted', async () => {
        renderScreen([settingsSource()]);

        expect(await screen.findByText('Tagline')).toBeInTheDocument();

        await userEvent.click(screen.getByRole('checkbox', { name: 'Only what is untranslated' }));

        expect(screen.getByText('Site name')).toBeInTheDocument();
        expect(screen.queryByText('Tagline')).not.toBeInTheDocument();
    });

    it('says so plainly when there is nothing the operator may read', async () => {
        renderScreen([]);

        expect(
            await screen.findByText(
                'There is no translatable content you have permission to read.',
            ),
        ).toBeInTheDocument();
    });

    it('marks each column with its own language and direction', async () => {
        renderScreen([settingsSource()]);

        const target = (await screen.findAllByLabelText(/Value · العربية/))[0]!;

        expect(target).toHaveAttribute('lang', 'ar');
        expect(target).toHaveAttribute('dir', 'rtl');

        // The source is text rather than a disabled input: it is what is being
        // translated, not something the editor is being stopped from changing.
        const section = target.closest('div.grid');

        expect(within(section as HTMLElement).getByText('AlphaMaster')).toHaveAttribute(
            'dir',
            'ltr',
        );
    });
});
