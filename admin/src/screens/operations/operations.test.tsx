import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { HttpResponse, http } from 'msw';
import { MemoryRouter } from 'react-router';
import { describe, expect, it } from 'vitest';

import { AppProviders } from '@/app/AppProviders';
import { AuthGate } from '@/auth/AuthGate';
import { OperationsScreen } from '@/screens/OperationsScreen';
import { observed, server } from '@/test/server';

import '@/i18n';

/**
 * Operations, against the endpoints Core actually publishes.
 *
 * The properties under test are the ones that make the trail trustworthy: every filter
 * reaches the server, so an answer is drawn from the whole trail rather than the page
 * on screen; nothing edits or removes an entry; and archiving — the one operation that
 * takes records away — confirms first and says what it is about to do.
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

function entry(over: Record<string, unknown> = {}) {
    return {
        id: 'aud-1',
        actor_id: '01hzzadmin',
        action: 'setting.updated',
        action_label: 'Setting updated',
        subject: 'branding.logo',
        outcome: 'succeeded',
        context: { from: null, to: 'med-1' },
        correlation_id: 'corr-1',
        created_at: '2026-09-08T09:00:00+00:00',
        ...over,
    };
}

function pagination(over: Record<string, unknown> = {}) {
    return {
        current_page: 1,
        per_page: 25,
        total: 1,
        last_page: 1,
        has_more_pages: false,
        ...over,
    };
}

function renderScreen(permissions: string[], records = [entry()], meta = pagination()) {
    server.use(
        LANGUAGES,
        HEALTH,
        http.get('*/api/v1/admin/audit', () =>
            HttpResponse.json({ success: true, data: records, meta: { pagination: meta } }),
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
                    <OperationsScreen />
                </AuthGate>
            </AppProviders>
        </MemoryRouter>,
    );
}

async function auditRequests(): Promise<URL[]> {
    for (let attempt = 0; attempt < 60; attempt += 1) {
        const urls = observed
            .filter((request) => request.method === 'GET')
            .map((request) => new URL(request.url))
            .filter((url) => url.pathname.endsWith('/admin/audit'));

        if (urls.length > 0) {
            return urls;
        }

        await new Promise((resolve) => setTimeout(resolve, 10));
    }

    throw new Error('The trail was never requested.');
}

describe('the operations workspace', () => {
    it('asks the server to narrow the trail, not the page on screen', async () => {
        renderScreen(['audit.view']);

        await screen.findByRole('button', { name: /Setting updated/ });

        await userEvent.selectOptions(screen.getByLabelText('Outcome'), 'failed');

        const last = (await auditRequests()).at(-1);

        expect(last?.searchParams.get('outcome')).toBe('failed');
        expect(last?.searchParams.get('page')).toBe('1');
    });

    it('offers no free-text search, because the endpoint has no such parameter', async () => {
        renderScreen(['audit.view']);

        await screen.findByRole('button', { name: /Setting updated/ });

        expect(screen.queryByRole('searchbox')).not.toBeInTheDocument();
        expect(screen.getByText(/There is no free-text search/)).toBeInTheDocument();
    });

    it('never offers to edit or remove a single entry', async () => {
        renderScreen(['audit.view', 'audit.manage']);

        await userEvent.click(await screen.findByRole('button', { name: /Setting updated/ }));

        const panel = await screen.findByRole('complementary', { name: 'Trail entry' });

        expect(within(panel).queryByRole('button', { name: /delete|remove|edit/i })).toBeNull();
        expect(within(panel).queryByRole('textbox')).toBeNull();
    });

    it('narrows to an actor from the entry rather than making one retype an identifier', async () => {
        renderScreen(['audit.view']);

        await userEvent.click(await screen.findByRole('button', { name: /Setting updated/ }));

        const panel = await screen.findByRole('complementary', { name: 'Trail entry' });

        await userEvent.click(within(panel).getByRole('button', { name: '01hzzadmin' }));

        const last = (await auditRequests()).at(-1);

        expect(last?.searchParams.get('actor_id')).toBe('01hzzadmin');
    });

    it('shows the recorded context as it was stored', async () => {
        renderScreen(['audit.view']);

        await userEvent.click(await screen.findByRole('button', { name: /Setting updated/ }));

        expect(await screen.findByText(/"to": "med-1"/)).toBeInTheDocument();
    });

    it('confirms an archive, and says what the window is and is not', async () => {
        server.use(
            http.post('*/api/v1/admin/audit/archive', () =>
                HttpResponse.json({
                    success: true,
                    data: {
                        count: 4,
                        location: 'audit/2026-09-09.json',
                        removed: true,
                        window: { oldest: '2026-01-01', newest: '2026-06-01' },
                        failure: null,
                    },
                }),
            ),
        );

        renderScreen(['audit.view', 'audit.manage']);

        await userEvent.click(await screen.findByRole('button', { name: 'Archive old entries' }));

        expect(
            screen.getByText(/set in operations settings rather than chosen here/),
        ).toBeInTheDocument();

        // The trigger stays put and disabled, and the reversible control comes first.
        const trigger = screen.getByRole('button', { name: 'Archive old entries' });
        const cancel = screen.getByRole('button', { name: 'Cancel' });
        const confirm = screen.getByRole('button', { name: 'Archive them' });

        expect(trigger).toBeDisabled();
        expect(trigger.compareDocumentPosition(cancel) & Node.DOCUMENT_POSITION_FOLLOWING).toBe(
            Node.DOCUMENT_POSITION_FOLLOWING,
        );
        expect(cancel.compareDocumentPosition(confirm) & Node.DOCUMENT_POSITION_FOLLOWING).toBe(
            Node.DOCUMENT_POSITION_FOLLOWING,
        );

        await userEvent.click(confirm);

        expect(
            await screen.findByText('4 entries were moved out of the active trail.'),
        ).toBeInTheDocument();
    });

    it('hides archiving from an account that may read the trail but not remove from it', async () => {
        renderScreen(['audit.view']);

        await screen.findByRole('button', { name: /Setting updated/ });

        expect(
            screen.queryByRole('button', { name: 'Archive old entries' }),
        ).not.toBeInTheDocument();
    });

    it('hides configuration transfer without the permission that guards both directions', async () => {
        renderScreen(['audit.view']);

        await screen.findByRole('button', { name: /Setting updated/ });

        expect(screen.queryByRole('button', { name: 'Write an export' })).not.toBeInTheDocument();
        expect(screen.queryByLabelText(/Location on the configured disk/)).not.toBeInTheDocument();
    });

    it('sends the operator choice about carrying ciphertext, defaulting to not', async () => {
        server.use(
            http.post('*/api/v1/admin/configuration/export', () =>
                HttpResponse.json({
                    success: true,
                    data: {
                        location: 'configuration/2026-09-09.json',
                        sections: ['settings'],
                        includes_secrets: false,
                        omitted_secrets: ['security.api_secret_key'],
                    },
                }),
            ),
        );

        renderScreen(['audit.view', 'settings.backup.manage']);

        await userEvent.click(await screen.findByRole('button', { name: 'Write an export' }));

        const write = await waitForRequest('POST');

        expect(await write.json()).toEqual({ include_secrets: false });

        // The receipt names what it left out, because a restore elsewhere will need
        // those supplied by hand.
        expect(await screen.findByText(/security.api_secret_key/)).toBeInTheDocument();
    });

    it('points at settings for configuration history rather than growing a second one', async () => {
        renderScreen(['audit.view']);

        await screen.findByRole('button', { name: /Setting updated/ });

        expect(
            screen.getByText(/live in Settings, on the group they belong to/),
        ).toBeInTheDocument();
    });
});

/** The last request of a method the suite has seen, once one has arrived. */
async function waitForRequest(method: string): Promise<Request> {
    for (let attempt = 0; attempt < 60; attempt += 1) {
        const match = observed.findLast((request) => request.method === method);

        if (match !== undefined) {
            return match;
        }

        await new Promise((resolve) => setTimeout(resolve, 10));
    }

    throw new Error(`No ${method} request was sent.`);
}
