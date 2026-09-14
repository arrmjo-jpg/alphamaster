import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { RequestHandler } from 'msw';
import { HttpResponse, http } from 'msw';
import { MemoryRouter } from 'react-router';
import { describe, expect, it } from 'vitest';

import { AppProviders } from '@/app/AppProviders';
import { AuthGate } from '@/auth/AuthGate';
import { TeamScreen } from '@/screens/TeamScreen';
import { server } from '@/test/server';

import '@/i18n';

/**
 * The team directory in the Admin (ADR 0055): a profile per language, chosen from Language
 * Management's languages, with what is the same in every language edited once.
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
];

function member(overrides: Record<string, unknown> = {}) {
    return {
        id: '01hzzmember',
        is_active: false,
        sort_order: 0,
        avatar: null,
        avatar_media_id: null,
        social_links: { website: 'https://nadia.example.test' },
        default_locale: 'en',
        name: 'Nadia Haddad',
        translations: {
            en: {
                locale: 'en',
                name: 'Nadia Haddad',
                position: 'Editor',
                bio: null,
                slug: 'nadia-haddad',
                updated_at: null,
            },
        },
        seo: {},
        progress: {
            en: { filled: 3, total: 4, complete: true },
            ar: { filled: 0, total: 4, complete: false },
        },
        activatable: true,
        available_locales: [],
        created_at: null,
        updated_at: null,
        ...overrides,
    };
}

function renderScreen(
    members: unknown[] = [member()],
    extra: RequestHandler[] = [],
    permissions: string[] = ['team.view', 'team.create', 'team.update', 'team.delete'],
) {
    server.use(
        http.get('*/api/v1/languages', () =>
            HttpResponse.json({ success: true, data: LANGUAGE_LIST }),
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
        http.get('*/api/v1/admin/team', () => HttpResponse.json({ success: true, data: members })),
        ...extra,
    );

    render(
        <MemoryRouter initialEntries={['/content/team']}>
            <AppProviders>
                <AuthGate>
                    <TeamScreen />
                </AuthGate>
            </AppProviders>
        </MemoryRouter>,
    );
}

async function openNadia(): Promise<HTMLElement> {
    await userEvent.click(await screen.findByRole('button', { name: /Nadia Haddad/ }));

    return screen.getByRole('region', { name: 'Nadia Haddad' });
}

describe('the team directory', () => {
    it('writes a profile in the language chosen, sending only what changed', async () => {
        const sent: Array<{ locale: string; body: unknown }> = [];

        renderScreen(
            [member()],
            [
                http.put(
                    '*/api/v1/admin/team/:id/translations/:locale',
                    async ({ request, params }) => {
                        sent.push({ locale: String(params.locale), body: await request.json() });

                        return HttpResponse.json({
                            success: true,
                            message: 'saved',
                            data: member(),
                        });
                    },
                ),
            ],
        );

        const editor = await openNadia();

        await userEvent.selectOptions(within(editor).getByLabelText('Content language'), 'ar');

        expect(within(editor).getByLabelText(/Name/)).toHaveValue('');

        await userEvent.type(within(editor).getByLabelText(/Name/), 'نادية حداد');
        await userEvent.type(within(editor).getByLabelText(/Position/), 'محرّرة');
        await userEvent.click(within(editor).getByRole('button', { name: 'Save profile' }));

        await expect.poll(() => sent.length).toBe(1);
        expect(sent[0]).toEqual({ locale: 'ar', body: { name: 'نادية حداد', position: 'محرّرة' } });
    });

    it('refuses to activate, with the reason, until the default-language profile is complete', async () => {
        renderScreen([member({ activatable: false })]);

        const editor = await openNadia();

        expect(within(editor).getByRole('button', { name: 'Activate' })).toBeDisabled();
        expect(
            within(editor).getByText(
                'Activating needs a complete English profile: a name and a position.',
            ),
        ).toBeInTheDocument();
    });

    it('activates a member whose default-language profile is complete', async () => {
        const sent: unknown[] = [];

        renderScreen(
            [member()],
            [
                http.patch('*/api/v1/admin/team/:id', async ({ request }) => {
                    sent.push(await request.json());

                    return HttpResponse.json({
                        success: true,
                        message: 'ok',
                        data: member({ is_active: true }),
                    });
                }),
            ],
        );

        const editor = await openNadia();

        await userEvent.click(within(editor).getByRole('button', { name: 'Activate' }));

        await expect.poll(() => sent.length).toBe(1);
        expect(sent[0]).toEqual({ is_active: true });
    });

    it('saves the social links once, for every language', async () => {
        const sent: Array<Record<string, unknown>> = [];

        renderScreen(
            [member()],
            [
                http.patch('*/api/v1/admin/team/:id', async ({ request }) => {
                    sent.push((await request.json()) as Record<string, unknown>);

                    return HttpResponse.json({ success: true, message: 'ok', data: member() });
                }),
            ],
        );

        const editor = await openNadia();

        await userEvent.type(within(editor).getByLabelText('GitHub'), 'https://github.com/nadia');
        await userEvent.click(within(editor).getByRole('button', { name: 'Save links' }));

        await expect.poll(() => sent.length).toBe(1);
        expect(sent[0]?.social_links).toMatchObject({
            website: 'https://nadia.example.test',
            github: 'https://github.com/nadia',
            x: null,
        });
    });

    it('offers no changes without the permission for them', async () => {
        renderScreen([member()], [], ['team.view']);

        const editor = await openNadia();

        expect(
            within(editor).getByText('You can read the team but not change it.'),
        ).toBeInTheDocument();
        expect(within(editor).queryByRole('button', { name: 'Activate' })).not.toBeInTheDocument();
        expect(
            within(editor).queryByRole('button', { name: 'Save links' }),
        ).not.toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'Add member' })).not.toBeInTheDocument();
    });
});
