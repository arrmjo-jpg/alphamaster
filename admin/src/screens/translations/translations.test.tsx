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
 * The item is the unit (ADR 0056): one status and one "3 / 5" per item, translated with AI in
 * one action, reviewed once and accepted once. What is asserted is mostly what the screen
 * refuses to do — prefill an empty target with the source, offer an accept for a single
 * field, send a field nobody touched, or filter a catalogue it was never given.
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
    {
        code: 'en',
        name: 'English',
        native_name: 'English',
        direction: 'ltr',
        is_default: true,
        is_active: true,
    },
    {
        code: 'ar',
        name: 'Arabic',
        native_name: 'العربية',
        direction: 'rtl',
        is_default: false,
        is_active: true,
    },
    {
        code: 'fr',
        name: 'French',
        native_name: 'Français',
        direction: 'ltr',
        is_default: false,
        is_active: false,
    },
];

function field(overrides: Record<string, unknown> = {}) {
    return {
        name: 'value',
        label: 'Value',
        multiline: false,
        required: true,
        type: 'plain_text',
        group: 'content',
        max_length: null,
        translatable: true,
        values: { en: 'AlphaMaster' },
        ...overrides,
    };
}

function entry(overrides: Record<string, unknown> = {}) {
    return {
        source: 'settings',
        id: 'general.site_name',
        title: 'Site name',
        context: 'The name a visitor reads.',
        status: 'not_translated',
        status_label: 'Not translated',
        progress: { filled: 0, total: 1, complete: false },
        fields: [field()],
        batch: null,
        ...overrides,
    };
}

function tagline() {
    return entry({
        id: 'general.tagline',
        title: 'Tagline',
        context: null,
        status: 'translated',
        status_label: 'Translated',
        progress: { filled: 1, total: 1, complete: true },
        fields: [field({ values: { en: 'Everything in one place', ar: 'كل شيء في مكان واحد' } })],
    });
}

function statuses(overrides: Record<string, number> = {}) {
    return {
        not_translated: 1,
        incomplete: 0,
        pending: 0,
        ready: 0,
        translated: 1,
        failed: 0,
        ...overrides,
    };
}

function workshop(overrides: Record<string, unknown> = {}) {
    return {
        locales: LOCALES,
        source_locale: 'en',
        target: 'ar',
        coverage: { total: 2, translated: 1 },
        sources: [
            {
                key: 'settings',
                label: 'Settings copy',
                may_write: true,
                completeness: { total: 2, translated: 1 },
                statuses: statuses(),
            },
        ],
        entries: [entry(), tagline()],
        pagination: { page: 1, per_page: 25, total: 2, last_page: 1 },
        ...overrides,
    };
}

/** The site name, translated by AI and waiting for review. */
function readyForReview(text = 'ألفاماستر') {
    return entry({
        status: 'ready',
        status_label: 'Ready for review',
        batch: {
            id: '01hzzbatch',
            status: 'ready',
            status_label: 'Ready for review',
            fields_total: 1,
            fields_ready: 1,
            fields_failed: 0,
            error_code: null,
            error_message: null,
            completed_at: '2026-09-14T10:00:00+00:00',
            suggestions: [
                { field: 'value', status: 'ready', text, error_code: null, error_message: null },
            ],
        },
    });
}

function withEntries(first: unknown, sourceStatuses: Record<string, number>) {
    return workshop({
        entries: [first, tagline()],
        sources: [
            {
                key: 'settings',
                label: 'Settings copy',
                may_write: true,
                completeness: { total: 2, translated: 1 },
                statuses: statuses(sourceStatuses),
            },
        ],
    });
}

function renderScreen(
    body: unknown = workshop(),
    extra: RequestHandler[] = [],
    permissions: string[] = ['settings.view', 'settings.update'],
    at = '/translations',
    aiAvailable = true,
) {
    server.use(
        LANGUAGES,
        HEALTH,
        http.get('*/api/v1/admin/translations/overview', () =>
            HttpResponse.json({
                success: true,
                data: {
                    source_locale: 'en',
                    ai: { available: aiAvailable, may_use: permissions.includes('ai.use') },
                    languages: [],
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
        http.get('*/api/v1/admin/translations', () =>
            HttpResponse.json({ success: true, data: body }),
        ),
        ...extra,
    );

    render(
        <MemoryRouter initialEntries={[at]}>
            <AppProviders>
                <AuthGate>
                    <TranslationsScreen />
                </AuthGate>
            </AppProviders>
        </MemoryRouter>,
    );
}

/** Wait for the workshop to have been asked for something matching. */
async function workshopRequest(fragment: string): Promise<URL> {
    for (let attempt = 0; attempt < 100; attempt += 1) {
        const match = observed
            .map((request) => new URL(request.url))
            .find(
                (url) =>
                    url.pathname.endsWith('/admin/translations') && url.search.includes(fragment),
            );

        if (match !== undefined) {
            return match;
        }

        await new Promise((resolve) => setTimeout(resolve, 10));
    }

    throw new Error(`The workshop was never asked for ${fragment}.`);
}

async function item(title: string): Promise<HTMLElement> {
    return screen.findByRole('article', { name: title });
}

describe('the translation workshop', () => {
    it('translates from the default language into another, without offering a choice of source', async () => {
        renderScreen();

        expect(await screen.findByText('Translating from')).toBeInTheDocument();
        expect(screen.queryByLabelText('Translating from')).not.toBeInTheDocument();
        expect(screen.getByLabelText('Into')).toHaveValue('ar');
    });

    it('shows each item with one status and how much of it is written', async () => {
        renderScreen();

        const siteName = await item('Site name');
        const translated = await item('Tagline');

        expect(within(siteName).getByText('Not translated')).toBeInTheDocument();
        expect(within(siteName).getByText('0 / 1')).toBeInTheDocument();
        expect(within(translated).getByText('Translated')).toBeInTheDocument();
        expect(within(translated).getByText('1 / 1')).toBeInTheDocument();
    });

    it('counts items rather than fields, and shows the coverage the server counted', async () => {
        renderScreen();

        expect(
            await screen.findByText('Items: 2 · Not translated: 1 · Translated: 1'),
        ).toBeInTheDocument();
        expect(screen.getByText('50%')).toBeInTheDocument();
        expect(screen.getByRole('progressbar')).toHaveAttribute('aria-valuenow', '50');
    });

    it('leaves an untranslated field empty rather than seeding it with the source', async () => {
        renderScreen();

        await userEvent.click(
            within(await item('Site name')).getByRole('button', { name: 'Edit' }),
        );

        expect(screen.getByLabelText('Value · العربية')).toHaveValue('');
    });

    it('sends only the field that changed, for the language being written', async () => {
        const sent: Array<Record<string, unknown>> = [];

        renderScreen(workshop(), [
            http.put('*/api/v1/admin/translations/settings/:id', async ({ request }) => {
                sent.push((await request.json()) as Record<string, unknown>);

                return HttpResponse.json({
                    success: true,
                    message: 'saved',
                    data: { source: 'settings', id: 'general.site_name', locale: 'ar' },
                });
            }),
        ]);

        await userEvent.click(
            within(await item('Site name')).getByRole('button', { name: 'Edit' }),
        );
        await userEvent.type(screen.getByLabelText('Value · العربية'), 'ألفاماستر');
        await userEvent.click(screen.getByRole('button', { name: 'Save' }));

        await screen.findByText('Saved');

        expect(sent).toEqual([{ locale: 'ar', values: { value: 'ألفاماستر' } }]);
    });

    it('offers no way to save content the platform says may not be written', async () => {
        renderScreen(
            workshop({
                sources: [
                    {
                        key: 'settings',
                        label: 'Settings copy',
                        may_write: false,
                        completeness: { total: 2, translated: 1 },
                        statuses: statuses(),
                    },
                ],
            }),
        );

        const siteName = await item('Site name');

        expect(
            screen.getAllByText('You can read this content but not change it.').length,
        ).toBeGreaterThan(0);
        expect(within(siteName).queryByRole('button', { name: 'Edit' })).not.toBeInTheDocument();

        await userEvent.click(within(siteName).getByRole('button', { name: 'Show fields' }));

        expect(screen.getByLabelText('Value · العربية')).toBeDisabled();
        expect(screen.queryByRole('button', { name: 'Save' })).not.toBeInTheDocument();
    });

    it('asks the server for a status rather than filtering what it was given', async () => {
        renderScreen();

        await userEvent.click(await screen.findByRole('radio', { name: 'Not translated' }));

        const url = await workshopRequest('state=not_translated');

        expect(url.searchParams.get('page')).toBe('1');
    });

    it('offers exactly the statuses an item can have', async () => {
        renderScreen();

        const group = await screen.findByRole('radiogroup', { name: 'Show' });

        expect(
            within(group)
                .getAllByRole('radio')
                .map((radio) => radio.textContent),
        ).toEqual([
            'All',
            'Not translated',
            'Incomplete',
            'Processing',
            'Ready for review',
            'Failed',
            'Translated',
        ]);
    });

    it('moves between the filters from the keyboard', async () => {
        renderScreen();

        const all = await screen.findByRole('radio', { name: 'All' });
        all.focus();

        await userEvent.keyboard('{ArrowRight}');

        expect(screen.getByRole('radio', { name: 'Not translated' })).toHaveFocus();
        await workshopRequest('state=not_translated');
    });

    it('searches on the server', async () => {
        renderScreen();

        await userEvent.type(await screen.findByRole('searchbox', { name: 'Search' }), 'tag');
        await userEvent.click(screen.getByRole('button', { name: 'Search' }));

        await workshopRequest('search=tag');
    });

    it('pages through the server’s pages', async () => {
        renderScreen(workshop({ pagination: { page: 1, per_page: 25, total: 30, last_page: 2 } }));

        expect(await screen.findAllByText('Page 1 of 2')).not.toHaveLength(0);

        await userEvent.click(screen.getByRole('button', { name: 'Next' }));

        await workshopRequest('page=2');
    });

    it('offers a language added in Language Management as a target and says it is not served', async () => {
        renderScreen(
            workshop({ target: 'fr', coverage: { total: 2, translated: 0 } }),
            [],
            undefined,
            '/translations?target=fr',
        );

        expect(
            await screen.findByRole('option', { name: 'Français · draft, not served' }),
        ).toBeInTheDocument();
        expect(screen.getByText(/Français is a draft/)).toBeInTheDocument();
        expect(screen.getByLabelText('Into')).toHaveValue('fr');
    });

    it('says so plainly when there is nothing the operator may read', async () => {
        renderScreen(
            workshop({
                sources: [],
                entries: [],
                coverage: { total: 0, translated: 0 },
                pagination: { page: 1, per_page: 25, total: 0, last_page: 1 },
            }),
        );

        expect(
            await screen.findByText(
                'There is no translatable content you have permission to read.',
            ),
        ).toBeInTheDocument();
    });

    it('marks each column with its own language and direction', async () => {
        renderScreen();

        await userEvent.click(
            within(await item('Site name')).getByRole('button', { name: 'Edit' }),
        );

        const target = screen.getByLabelText('Value · العربية');

        expect(target).toHaveAttribute('lang', 'ar');
        expect(target).toHaveAttribute('dir', 'rtl');

        const pair = target.closest('div.grid');

        expect(within(pair as HTMLElement).getByText('AlphaMaster')).toHaveAttribute('dir', 'ltr');
    });

    it('describes a field by its metadata: optional, rich text and a length limit', async () => {
        renderScreen(
            withEntries(
                entry({
                    source: 'settings',
                    fields: [
                        field({
                            name: 'body',
                            label: 'Body',
                            type: 'html',
                            multiline: true,
                            values: { en: '<p>Hi</p>' },
                        }),
                        field({
                            name: 'seo_title',
                            label: 'SEO title',
                            group: 'seo',
                            required: false,
                            max_length: 255,
                            values: {},
                        }),
                    ],
                    progress: { filled: 0, total: 2, complete: false },
                }),
                {},
            ),
        );

        await userEvent.click(
            within(await item('Site name')).getByRole('button', { name: 'Edit' }),
        );

        expect(
            screen.getByText('Rich text: keep every tag and link as it is.'),
        ).toBeInTheDocument();
        expect(screen.getByText('SEO')).toBeInTheDocument();
        expect(screen.getByText('SEO title · English · optional')).toBeInTheDocument();
        expect(screen.getByText('0 / 255 characters')).toBeInTheDocument();
    });
});

describe('translating an item with AI', () => {
    it('reviews an item once, with every field that came back, and saves nothing until it is accepted', async () => {
        const writes: unknown[] = [];

        renderScreen(withEntries(readyForReview(), { not_translated: 0, ready: 1 }), [
            http.post('*/api/v1/admin/translations/batches/:id/accept', () => {
                writes.push(true);

                return HttpResponse.json({ success: true, message: 'ok', data: {} });
            }),
        ]);

        const siteName = await item('Site name');

        expect(within(siteName).getByText('Ready for review')).toBeInTheDocument();

        await userEvent.click(within(siteName).getByRole('button', { name: 'Review translation' }));

        expect(screen.getByLabelText('Value · العربية')).toHaveValue('ألفاماستر');
        expect(screen.getByText('Nothing is saved until you accept.')).toBeInTheDocument();
        expect(writes).toHaveLength(0);

        // One decision for the item. There is no accept beside a field.
        expect(within(siteName).getAllByRole('button', { name: /^Accept/ })).toHaveLength(1);
        expect(screen.getByRole('button', { name: 'Accept translation' })).toBeInTheDocument();
    });

    it('accepts the whole item once, as it was generated', async () => {
        const sent: unknown[] = [];

        renderScreen(withEntries(readyForReview(), { not_translated: 0, ready: 1 }), [
            http.post(
                '*/api/v1/admin/translations/batches/:id/accept',
                async ({ request, params }) => {
                    sent.push({ id: params.id, body: await request.json() });

                    return HttpResponse.json({ success: true, message: 'ok', data: {} });
                },
            ),
        ]);

        await userEvent.click(
            within(await item('Site name')).getByRole('button', { name: 'Review translation' }),
        );
        await userEvent.click(screen.getByRole('button', { name: 'Accept translation' }));

        await expect.poll(() => sent.length).toBe(1);
        expect(sent[0]).toEqual({ id: '01hzzbatch', body: {} });
    });

    it('sends only the fields the reviewer changed, and says the item was edited', async () => {
        const sent: unknown[] = [];

        renderScreen(withEntries(readyForReview(), { not_translated: 0, ready: 1 }), [
            http.post('*/api/v1/admin/translations/batches/:id/accept', async ({ request }) => {
                sent.push(await request.json());

                return HttpResponse.json({ success: true, message: 'ok', data: {} });
            }),
        ]);

        await userEvent.click(
            within(await item('Site name')).getByRole('button', { name: 'Review translation' }),
        );

        const value = screen.getByLabelText('Value · العربية');
        await userEvent.clear(value);
        await userEvent.type(value, 'نصّ من إنسان');

        expect(screen.getByText('Edited')).toBeInTheDocument();

        await userEvent.click(screen.getByRole('button', { name: 'Accept translation' }));

        await expect.poll(() => sent.length).toBe(1);
        expect(sent[0]).toEqual({ values: { value: 'نصّ من إنسان' } });
    });

    it('discards a translation without writing anything', async () => {
        const writes: unknown[] = [];
        let dismissed = false;

        renderScreen(withEntries(readyForReview(), { not_translated: 0, ready: 1 }), [
            http.delete('*/api/v1/admin/translations/batches/:id', () => {
                dismissed = true;

                return HttpResponse.json({ success: true, message: 'ok', data: {} });
            }),
            http.put('*/api/v1/admin/translations/settings/:id', () => {
                writes.push(true);

                return HttpResponse.json({ success: true, message: 'ok', data: {} });
            }),
        ]);

        await userEvent.click(
            within(await item('Site name')).getByRole('button', { name: 'Review translation' }),
        );
        await userEvent.click(screen.getByRole('button', { name: 'Discard' }));

        await expect.poll(() => dismissed).toBe(true);
        expect(writes).toHaveLength(0);
    });

    it('shows an item being translated as processing, with nothing to accept', async () => {
        renderScreen(
            withEntries(entry({ status: 'pending', status_label: 'Processing' }), {
                not_translated: 0,
                pending: 1,
            }),
            [],
            ['settings.view', 'settings.update', 'ai.use'],
        );

        const siteName = await item('Site name');

        expect(within(siteName).getByText('Translating…')).toBeInTheDocument();
        expect(
            within(siteName).queryByRole('button', { name: 'Review translation' }),
        ).not.toBeInTheDocument();
        expect(
            within(siteName).queryByRole('button', { name: 'Translate with AI' }),
        ).not.toBeInTheDocument();
    });

    it('shows a failed item with its reason, and retries the item as a whole', async () => {
        const asked: unknown[] = [];

        renderScreen(
            withEntries(
                entry({
                    status: 'failed',
                    status_label: 'Failed',
                    batch: {
                        id: '01hzzfailed',
                        status: 'failed',
                        status_label: 'Failed',
                        fields_total: 2,
                        fields_ready: 1,
                        fields_failed: 1,
                        error_code: 'model_not_found',
                        error_message: 'No such model.',
                        completed_at: '2026-09-14T10:00:00+00:00',
                        suggestions: [],
                    },
                }),
                { not_translated: 0, failed: 1 },
            ),
            [
                http.post('*/api/v1/admin/translations/batches', async ({ request }) => {
                    asked.push(await request.json());

                    return HttpResponse.json({
                        success: true,
                        message: 'ok',
                        data: { queued: 1, existing: 0, skipped: 0 },
                    });
                }),
            ],
            ['settings.view', 'settings.update', 'ai.use'],
        );

        const siteName = await item('Site name');

        expect(
            within(siteName).getByText('This translation did not complete.'),
        ).toBeInTheDocument();
        expect(within(siteName).getByText('No such model.')).toBeInTheDocument();
        expect(within(siteName).getByText('1 of 2 fields came back.')).toBeInTheDocument();

        await userEvent.click(within(siteName).getByRole('button', { name: 'Retry with AI' }));

        await expect.poll(() => asked.length).toBe(1);
        expect(asked[0]).toEqual({ locale: 'ar', source: 'settings', item: 'general.site_name' });
    });

    it('translates everything missing in the language with one command', async () => {
        const asked: unknown[] = [];

        renderScreen(
            workshop(),
            [
                http.post('*/api/v1/admin/translations/batches', async ({ request }) => {
                    asked.push(await request.json());

                    return HttpResponse.json({
                        success: true,
                        message: 'ok',
                        data: { queued: 1, existing: 0, skipped: 1 },
                    });
                }),
            ],
            ['settings.view', 'settings.update', 'ai.use'],
        );

        await userEvent.click(
            await screen.findByRole('button', { name: 'Translate all missing into العربية' }),
        );

        expect(
            await screen.findByText(
                'Started 1; 0 already in progress or waiting for review; 1 needed nothing.',
            ),
        ).toBeInTheDocument();
        // The language, and nothing about which items or which fields.
        expect(asked).toEqual([{ locale: 'ar' }]);
    });

    it('translates everything missing in one source', async () => {
        const asked: unknown[] = [];

        renderScreen(
            workshop(),
            [
                http.post('*/api/v1/admin/translations/batches', async ({ request }) => {
                    asked.push(await request.json());

                    return HttpResponse.json({
                        success: true,
                        message: 'ok',
                        data: { queued: 1, existing: 0, skipped: 0 },
                    });
                }),
            ],
            ['settings.view', 'settings.update', 'ai.use'],
        );

        await userEvent.click(await screen.findByRole('button', { name: 'Translate all missing' }));

        await expect.poll(() => asked.length).toBe(1);
        expect(asked[0]).toEqual({ locale: 'ar', source: 'settings' });
    });

    it('accepts every ready item and reports the ones that were refused', async () => {
        const asked: unknown[] = [];

        renderScreen(withEntries(readyForReview(), { not_translated: 0, ready: 2 }), [
            http.post('*/api/v1/admin/translations/batches/accept-ready', async ({ request }) => {
                asked.push(await request.json());

                return HttpResponse.json({
                    success: true,
                    message: '1 accepted / 1 failed.',
                    data: {
                        accepted: 1,
                        failed: 1,
                        results: [
                            {
                                batch: 'b1',
                                source: 'settings',
                                item_id: 'general.site_name',
                                status: 'accepted',
                                error_code: null,
                                message: null,
                            },
                            {
                                batch: 'b2',
                                source: 'settings',
                                item_id: 'general.tagline',
                                status: 'failed',
                                error_code: 'TRANSLATION_MOVED',
                                message: 'This item changed after its translation was generated.',
                            },
                        ],
                    },
                });
            }),
        ]);

        await userEvent.click(await screen.findByRole('button', { name: 'Accept all ready (2)' }));

        expect(await screen.findByText('1 accepted / 1 failed')).toBeInTheDocument();
        expect(
            screen.getByText('Tagline: This item changed after its translation was generated.'),
        ).toBeInTheDocument();
        expect(asked).toEqual([{ locale: 'ar' }]);
    });

    it('offers no way to translate with AI without the permission that governs spending', async () => {
        renderScreen();

        expect(await item('Site name')).toBeInTheDocument();
        expect(screen.queryByRole('button', { name: /Translate/ })).not.toBeInTheDocument();
    });

    it('disables translating with AI, with the reason, when no provider is configured', async () => {
        renderScreen(
            workshop(),
            [],
            ['settings.view', 'settings.update', 'ai.use'],
            '/translations',
            false,
        );

        const all = await screen.findByRole('button', {
            name: 'Translate all missing into العربية',
        });

        await expect.poll(() => (all as HTMLButtonElement).disabled).toBe(true);
        expect(screen.getByText(/AI provider not configured/)).toBeInTheDocument();
    });
});
