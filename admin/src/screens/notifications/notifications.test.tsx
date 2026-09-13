import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { HttpResponse, http } from 'msw';
import { MemoryRouter } from 'react-router';
import { describe, expect, it } from 'vitest';

import { AppProviders } from '@/app/AppProviders';
import { AuthGate } from '@/auth/AuthGate';
import { NotificationsScreen } from '@/screens/NotificationsScreen';
import { observed, server } from '@/test/server';

import '@/i18n';

/**
 * Notifications, against the four surfaces the platform actually has.
 *
 * Two of them are the caller's own — their preferences and their in-app records — and
 * two are administrative: the wording every recipient reads, and sending an
 * announcement. The inbox was the absence this file used to assert; the endpoint
 * exists now, so what is asserted instead is that nothing in it deletes a record and
 * that the count on the badge is the server's rather than the page's.
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
                is_active: true,
                is_default: true,
                sort_order: 0,
                created_at: null,
                updated_at: null,
            },
            {
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

/** The shape the platform publishes: every combination, defaults included. */
const MATRIX = [
    {
        type: 'security.alert',
        type_label: 'Security alert',
        channel: 'database',
        channel_label: 'In app',
        enabled: true,
        silenceable: false,
    },
    {
        type: 'security.alert',
        type_label: 'Security alert',
        channel: 'mail',
        channel_label: 'Email',
        enabled: true,
        silenceable: false,
    },
    {
        type: 'account.updated',
        type_label: 'Account updated',
        channel: 'database',
        channel_label: 'In app',
        enabled: true,
        silenceable: false,
    },
    {
        type: 'account.updated',
        type_label: 'Account updated',
        channel: 'mail',
        channel_label: 'Email',
        enabled: true,
        silenceable: true,
    },
];

const TEMPLATE = {
    id: 'tpl-1',
    type: 'account.updated',
    type_label: 'Account updated',
    is_active: true,
    translations: [{ locale: 'en', subject: 'Your account changed', body: 'Hello {{name}}.' }],
    updated_at: '2026-09-01T10:00:00+00:00',
};

const RECORD = {
    id: 'ntf-1',
    type: 'admin.announcement',
    type_label: 'Administrator announcement',
    subject: 'Scheduled maintenance',
    body: 'The platform will be unavailable on Sunday.',
    locale: 'en',
    read_at: null,
    created_at: '2026-09-09T10:00:00+00:00',
};

function renderScreen(
    permissions: string[],
    templates = [TEMPLATE],
    matrix = MATRIX,
    records: Array<typeof RECORD> = [RECORD],
) {
    server.use(
        LANGUAGES,
        HEALTH,
        http.get('*/api/v1/notifications/preferences', () =>
            HttpResponse.json({ success: true, data: matrix }),
        ),
        http.get('*/api/v1/admin/notifications/templates', () =>
            HttpResponse.json({ success: true, data: templates }),
        ),
        http.get('*/api/v1/notifications', () =>
            HttpResponse.json({
                success: true,
                data: records,
                meta: {
                    pagination: {
                        current_page: 1,
                        per_page: 25,
                        total: records.length,
                        last_page: 1,
                        has_more_pages: false,
                    },
                    unread: records.filter((record) => record.read_at === null).length,
                },
            }),
        ),
        http.get('*/api/v1/auth/me', () =>
            HttpResponse.json({
                success: true,
                data: {
                    id: '01hzzadmin',
                    name: 'Nadia Haddad',
                    email: 'nadia@example.test',
                    account_type: 'admin',
                    is_active: true,
                    email_verified: true,
                    email_verified_at: '2026-01-01T00:00:00+00:00',
                    abilities: ['admin:access'],
                    roles: ['administrator'],
                    permissions,
                },
            }),
        ),
    );

    return render(
        <MemoryRouter>
            <AppProviders>
                <AuthGate>
                    <NotificationsScreen />
                </AuthGate>
            </AppProviders>
        </MemoryRouter>,
    );
}

describe('the notifications workspace', () => {
    it('renders the matrix the platform published, on both of its axes', async () => {
        renderScreen([]);

        expect(await screen.findByText('Security alert')).toBeInTheDocument();
        expect(screen.getByText('Account updated')).toBeInTheDocument();
        expect(screen.getAllByText('In app').length).toBeGreaterThan(0);
        expect(screen.getAllByText('Email').length).toBeGreaterThan(0);
    });

    it('refuses to switch off what the platform says cannot be silenced', async () => {
        renderScreen([]);

        await screen.findByText('Security alert');

        // Three of the four cells are not silenceable, and the platform said so; the
        // rule is not reimplemented here.
        const boxes = screen.getAllByRole('checkbox');

        expect(boxes.filter((box) => (box as HTMLInputElement).disabled)).toHaveLength(3);
        expect(screen.getByText(/A locked row cannot be switched off/)).toBeInTheDocument();
    });

    it('sends only the cells that changed', async () => {
        server.use(
            http.put('*/api/v1/notifications/preferences', () =>
                HttpResponse.json({
                    success: true,
                    data: MATRIX.map((row) =>
                        row.type === 'account.updated' && row.channel === 'mail'
                            ? { ...row, enabled: false }
                            : row,
                    ),
                }),
            ),
        );

        renderScreen([]);

        await screen.findByText('Account updated');

        const silenceable = screen
            .getAllByRole('checkbox')
            .find((box) => !(box as HTMLInputElement).disabled);

        expect(silenceable).toBeDefined();

        await userEvent.click(silenceable as HTMLElement);
        await userEvent.click(screen.getByRole('button', { name: 'Save one change' }));

        const write = await waitForRequest('PUT');

        expect(await write.json()).toEqual({
            preferences: [{ type: 'account.updated', channel: 'mail', enabled: false }],
        });
    });

    it('shows the account its own records, without asking for a permission', async () => {
        // Reading your own inbox is not administrative: the endpoint is scoped to
        // whoever asks, so there is nothing to gate.
        renderScreen([]);

        expect(await screen.findByText('Scheduled maintenance')).toBeInTheDocument();
        expect(screen.getByText('One unread notification.')).toBeInTheDocument();
    });

    it('offers no way to delete a record, because it is the evidence it was sent', async () => {
        renderScreen([]);

        await screen.findByText('Scheduled maintenance');

        expect(screen.queryByRole('button', { name: /Delete/i })).not.toBeInTheDocument();
        expect(screen.queryByRole('button', { name: /Remove/i })).not.toBeInTheDocument();
    });

    it('marks one record read through the endpoint rather than in the browser', async () => {
        let called = '';

        server.use(
            http.post('*/api/v1/notifications/:id/read', ({ params }) => {
                called = String(params['id']);

                return HttpResponse.json({
                    success: true,
                    data: { ...RECORD, read_at: '2026-09-09T11:00:00+00:00' },
                });
            }),
        );

        renderScreen([]);

        await userEvent.click(await screen.findByRole('button', { name: 'Mark read' }));

        await expect.poll(() => called).toBe('ntf-1');
    });

    it('says so plainly when there is nothing to read', async () => {
        renderScreen([], [TEMPLATE], MATRIX, []);

        expect(
            await screen.findByText('The platform has not sent this account anything yet.'),
        ).toBeInTheDocument();
    });

    it('hides template wording from an account that may not read it', async () => {
        renderScreen([]);

        await screen.findByText('Security alert');

        // The preferences half is theirs and stays; the wording half is everyone's
        // and is not offered at all, rather than offered and refused.
        expect(screen.getByText(/These are your own settings/)).toBeInTheDocument();
        expect(screen.queryByText('Wording')).not.toBeInTheDocument();
        expect(screen.queryByLabelText('Subject')).not.toBeInTheDocument();
        expect(
            screen.queryByRole('button', { name: /Activate|Deactivate/ }),
        ).not.toBeInTheDocument();
    });

    it('offers a tab per active language, named as the language names itself', async () => {
        renderScreen(['notifications.view', 'notifications.update']);

        await userEvent.click(await screen.findByRole('button', { name: /Account updated/ }));

        const panel = await screen.findByRole('complementary', { name: 'Template detail' });

        expect(within(panel).getByRole('radio', { name: 'English' })).toBeInTheDocument();
        expect(within(panel).getByRole('radio', { name: 'العربية' })).toBeInTheDocument();
    });

    it('sends only the languages edited, so one translation cannot overwrite another', async () => {
        server.use(
            http.put('*/api/v1/admin/notifications/templates/:id', () =>
                HttpResponse.json({ success: true, data: TEMPLATE }),
            ),
        );

        renderScreen(['notifications.view', 'notifications.update']);

        await userEvent.click(await screen.findByRole('button', { name: /Account updated/ }));

        const panel = await screen.findByRole('complementary', { name: 'Template detail' });

        await userEvent.click(within(panel).getByRole('radio', { name: 'العربية' }));
        await userEvent.type(within(panel).getByLabelText('Subject'), 'تغيّر حسابك');
        await userEvent.type(within(panel).getByLabelText('Body'), 'مرحبًا');
        await userEvent.click(within(panel).getByRole('button', { name: 'Save one language' }));

        const write = await waitForRequest('PUT');
        const body = (await write.json()) as { translations: { locale: string }[] };

        expect(body.translations).toHaveLength(1);
        expect(body.translations[0]?.locale).toBe('ar');
    });

    it('shows a reader the wording without the controls to change it', async () => {
        renderScreen(['notifications.view']);

        await userEvent.click(await screen.findByRole('button', { name: /Account updated/ }));

        const panel = await screen.findByRole('complementary', { name: 'Template detail' });

        expect(
            within(panel).getByText(/may read this wording but not change it/),
        ).toBeInTheDocument();
        expect(within(panel).getByLabelText('Subject')).toBeDisabled();
        expect(within(panel).queryByRole('button', { name: /^Save/ })).not.toBeInTheDocument();
    });
});

/** The last request of a method the suite has seen, once one has arrived. */
async function waitForRequest(method: string): Promise<Request> {
    for (let attempt = 0; attempt < 50; attempt += 1) {
        const match = observed.findLast((request) => request.method === method);

        if (match !== undefined) {
            return match;
        }

        await new Promise((resolve) => setTimeout(resolve, 10));
    }

    throw new Error(`No ${method} request was sent.`);
}

describe('announcements', () => {
    it('are not offered without the permission the endpoint requires', async () => {
        // `notifications.update` is the power to change wording. Sending is its own
        // permission, and holding the first is not a reason to hold the second.
        renderScreen(['notifications.view', 'notifications.update']);

        await screen.findByText('Security alert');

        expect(screen.queryByRole('button', { name: 'Send announcement' })).not.toBeInTheDocument();
    });

    it('confirm before sending, naming the audience rather than asking if you are sure', async () => {
        let sent: unknown = null;

        server.use(
            http.post('*/api/v1/admin/notifications/announcements', async ({ request }) => {
                sent = await request.json();

                return HttpResponse.json({
                    success: true,
                    message: 'Queued.',
                    data: {
                        audience: 'administrators',
                        audience_label: 'Administrators',
                        recipients: 3,
                    },
                });
            }),
        );

        renderScreen(['notifications.send']);

        await userEvent.type(await screen.findByLabelText(/^Subject/), 'Rotate your credentials');
        await userEvent.type(screen.getByLabelText(/^Message/), 'Please rotate them this week.');
        await userEvent.click(screen.getByRole('button', { name: 'Send announcement' }));

        // Nothing has been sent yet: opening a confirmation is not the operation.
        expect(sent).toBeNull();
        // The confirmation names who it reaches. Scoped to the paragraph rather than
        // the page, because the audience also appears as the selected option.
        expect(screen.getByText(/cannot be unsent/)).toHaveTextContent('Administrators only');

        const trigger = screen.getByRole('button', { name: 'Send announcement' });
        const cancel = screen.getByRole('button', { name: 'Cancel' });
        const confirm = screen.getByRole('button', { name: 'Send it' });

        expect(trigger).toBeDisabled();
        expect(trigger.compareDocumentPosition(cancel) & Node.DOCUMENT_POSITION_FOLLOWING).toBe(
            Node.DOCUMENT_POSITION_FOLLOWING,
        );
        expect(cancel.compareDocumentPosition(confirm) & Node.DOCUMENT_POSITION_FOLLOWING).toBe(
            Node.DOCUMENT_POSITION_FOLLOWING,
        );

        await userEvent.click(confirm);

        await expect
            .poll(() => sent)
            .toEqual({
                subject: 'Rotate your credentials',
                body: 'Please rotate them this week.',
                audience: 'administrators',
            });

        expect(await screen.findByText('Queued for 3 recipients.')).toBeInTheDocument();
    });

    it('offers only the audiences the platform defines', async () => {
        renderScreen(['notifications.send']);

        const audience = await screen.findByLabelText('Audience');
        const options = within(audience)
            .getAllByRole('option')
            .map((option) => option.textContent);

        // A closed set, not a query an operator composes: anything narrower belongs to
        // an application built on this foundation rather than to the foundation.
        expect(options).toEqual(['Everyone who can sign in', 'Administrators only']);
    });
});
