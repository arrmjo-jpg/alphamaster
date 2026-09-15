import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { RequestHandler } from 'msw';
import { HttpResponse, http } from 'msw';
import { MemoryRouter } from 'react-router';
import { describe, expect, it } from 'vitest';

import { AppProviders } from '@/app/AppProviders';
import { AuthGate } from '@/auth/AuthGate';
import { PagesScreen } from '@/screens/PagesScreen';
import { server } from '@/test/server';

import '@/i18n';

/**
 * Static pages in the Admin (ADR 0055): one page, a content language chosen from every language
 * Language Management knows, and nothing of one language shown in another's fields.
 */

const LANGUAGE_LIST = [
    {
        id: 'l-en',
        code: 'en',
        name: 'English',
        native_name: 'English',
        direction: 'ltr',
        is_active: true,
        is_default: true,
        sort_order: 0,
        created_at: null,
        updated_at: null,
    },
    {
        id: 'l-ar',
        code: 'ar',
        name: 'Arabic',
        native_name: 'العربية',
        direction: 'rtl',
        is_active: true,
        is_default: false,
        sort_order: 1,
        created_at: null,
        updated_at: null,
    },
    {
        id: 'l-fr',
        code: 'fr',
        name: 'French',
        native_name: 'Français',
        direction: 'ltr',
        is_active: false,
        is_default: false,
        sort_order: 2,
        created_at: null,
        updated_at: null,
    },
];

function page(overrides: Record<string, unknown> = {}) {
    return {
        id: '01hzzpage',
        status: 'draft',
        status_label: 'Draft',
        published_at: null,
        sort_order: 0,
        default_locale: 'en',
        title: 'Privacy policy',
        translations: {
            en: {
                locale: 'en',
                title: 'Privacy policy',
                slug: 'privacy',
                summary: null,
                body: '<p>We keep little.</p>',
                updated_at: null,
            },
        },
        seo: {},
        progress: {
            en: { filled: 3, total: 4, complete: true },
            ar: { filled: 0, total: 4, complete: false },
            fr: { filled: 0, total: 4, complete: false },
        },
        publishable: true,
        available_locales: [],
        created_at: null,
        updated_at: null,
        ...overrides,
    };
}

function renderScreen(
    pages: unknown[] = [page()],
    extra: RequestHandler[] = [],
    permissions: string[] = [
        'pages.view',
        'pages.create',
        'pages.update',
        'pages.publish',
        'pages.delete',
    ],
) {
    server.use(
        http.get('*/api/v1/languages', () =>
            HttpResponse.json({
                success: true,
                data: LANGUAGE_LIST.filter((language) => language.is_active),
            }),
        ),
        http.get('*/api/v1/health', () =>
            HttpResponse.json({
                success: true,
                data: {
                    status: 'healthy',
                    timestamp: '2026-09-14T10:00:00+00:00',
                    framework: 'Laravel 13',
                },
            }),
        ),
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
                    permissions,
                },
            }),
        ),
        http.get('*/api/v1/admin/languages', () =>
            HttpResponse.json({ success: true, data: LANGUAGE_LIST }),
        ),
        http.get('*/api/v1/admin/pages', () => HttpResponse.json({ success: true, data: pages })),
        ...extra,
    );

    render(
        <MemoryRouter initialEntries={['/content/pages']}>
            <AppProviders>
                <AuthGate>
                    <PagesScreen />
                </AuthGate>
            </AppProviders>
        </MemoryRouter>,
    );
}

async function openPrivacyPolicy(): Promise<HTMLElement> {
    await userEvent.click(await screen.findByRole('button', { name: /Privacy policy/ }));

    return screen.getByRole('region', { name: 'Privacy policy' });
}

describe('static pages', () => {
    it('offers every language Language Management knows, the default first, and says which is a draft', async () => {
        renderScreen();

        const editor = await openPrivacyPolicy();
        const selector = within(editor).getByLabelText('Content language');

        expect(
            within(selector)
                .getAllByRole('option')
                .map((option) => option.textContent),
        ).toEqual(['English', 'العربية', 'Français (draft — not served)']);
        expect(selector).toHaveValue('en');
        expect(within(editor).getByLabelText(/Title/)).toHaveValue('Privacy policy');
    });

    it('shows a language’s own text, and empty fields where nothing is written — never the default’s', async () => {
        renderScreen();

        const editor = await openPrivacyPolicy();

        await userEvent.selectOptions(within(editor).getByLabelText('Content language'), 'ar');

        const title = within(editor).getByLabelText(/Title/);

        expect(title).toHaveValue('');
        expect(title).toHaveAttribute('dir', 'rtl');
        expect(title).toHaveAttribute('lang', 'ar');
        expect(within(editor).getByLabelText(/Body/)).toHaveValue('');
    });

    it('writes only the fields that changed, in the language chosen', async () => {
        const sent: Array<{ locale: string; body: unknown }> = [];

        renderScreen(
            [page()],
            [
                http.put(
                    '*/api/v1/admin/pages/:id/translations/:locale',
                    async ({ request, params }) => {
                        sent.push({ locale: String(params.locale), body: await request.json() });

                        return HttpResponse.json({ success: true, message: 'saved', data: page() });
                    },
                ),
            ],
        );

        const editor = await openPrivacyPolicy();

        await userEvent.selectOptions(within(editor).getByLabelText('Content language'), 'ar');
        await userEvent.type(within(editor).getByLabelText(/Title/), 'سياسة الخصوصية');
        await userEvent.click(within(editor).getByRole('button', { name: 'Save translation' }));

        await expect.poll(() => sent.length).toBe(1);
        expect(sent[0]).toEqual({ locale: 'ar', body: { title: 'سياسة الخصوصية' } });
    });

    it('sets a sharing image for one language as a media reference, saved with that translation', async () => {
        const sent: Array<{ locale: string; body: { seo?: { og_media_id?: unknown } } }> = [];

        renderScreen(
            [page()],
            [
                http.get('*/api/v1/admin/media', () =>
                    HttpResponse.json({
                        success: true,
                        data: [
                            {
                                id: '01hzzshare',
                                url: '/storage/share.png',
                                original_filename: 'share.png',
                                visibility: 'public',
                            },
                        ],
                        meta: {},
                    }),
                ),
                http.put(
                    '*/api/v1/admin/pages/:id/translations/:locale',
                    async ({ request, params }) => {
                        sent.push({
                            locale: String(params.locale),
                            body: (await request.json()) as { seo?: { og_media_id?: unknown } },
                        });

                        return HttpResponse.json({ success: true, message: 'saved', data: page() });
                    },
                ),
            ],
            ['pages.view', 'pages.update', 'media.view'],
        );

        const editor = await openPrivacyPolicy();

        await userEvent.click(
            within(editor).getByRole('button', { name: 'Choose from the library' }),
        );
        await userEvent.click(await within(editor).findByRole('button', { name: 'share.png' }));

        // Choosing stages the id into this language's SEO; nothing is written until the save.
        expect(sent).toHaveLength(0);

        await userEvent.click(within(editor).getByRole('button', { name: 'Save translation' }));

        await expect.poll(() => sent.length).toBe(1);
        expect(sent[0]?.locale).toBe('en');
        expect(sent[0]?.body.seo?.og_media_id).toBe('01hzzshare');
    });

    it('shows each language’s translation status and switches to a language from it', async () => {
        renderScreen();

        const editor = await openPrivacyPolicy();
        const statuses = within(editor).getByRole('list', { name: 'Translation status' });

        expect(
            within(statuses)
                .getAllByRole('button')
                .map((button) => button.textContent),
        ).toEqual([
            'EnglishTranslated',
            'العربيةNot translated',
            'FrançaisNot translatedDraft — not served',
        ]);

        await userEvent.click(within(statuses).getByRole('button', { name: /Français/ }));

        expect(within(editor).getByLabelText('Content language')).toHaveValue('fr');
    });

    it('refuses to publish, with the reason, until the default language is complete', async () => {
        renderScreen([page({ publishable: false })]);

        const editor = await openPrivacyPolicy();

        expect(within(editor).getByRole('button', { name: 'Publish' })).toBeDisabled();
        expect(
            within(editor).getByText(
                'Publishing needs a complete English translation: a title and a body.',
            ),
        ).toBeInTheDocument();
    });

    it('publishes a page whose default language is complete', async () => {
        let published = false;

        renderScreen(
            [page()],
            [
                http.post('*/api/v1/admin/pages/:id/publish', () => {
                    published = true;

                    return HttpResponse.json({
                        success: true,
                        message: 'ok',
                        data: page({ status: 'published', status_label: 'Published' }),
                    });
                }),
            ],
        );

        const editor = await openPrivacyPolicy();

        await userEvent.click(within(editor).getByRole('button', { name: 'Publish' }));

        await expect.poll(() => published).toBe(true);
    });

    it('offers no editing, publishing or deleting without the permissions for them', async () => {
        renderScreen([page()], [], ['pages.view']);

        const editor = await openPrivacyPolicy();

        expect(
            within(editor).getByText('You can read pages but not change them.'),
        ).toBeInTheDocument();
        expect(within(editor).getByLabelText(/Title/)).toBeDisabled();
        expect(
            within(editor).queryByRole('button', { name: 'Save translation' }),
        ).not.toBeInTheDocument();
        expect(within(editor).queryByRole('button', { name: 'Publish' })).not.toBeInTheDocument();
        expect(within(editor).queryByRole('button', { name: /Delete/ })).not.toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'New page' })).not.toBeInTheDocument();
    });
});
