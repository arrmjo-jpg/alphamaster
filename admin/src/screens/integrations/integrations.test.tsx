import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { HttpResponse, http } from 'msw';
import { MemoryRouter } from 'react-router';
import { describe, expect, it } from 'vitest';

import { AppProviders } from '@/app/AppProviders';
import { AuthGate } from '@/auth/AuthGate';
import { IntegrationsScreen } from '@/screens/IntegrationsScreen';
import { observed, server } from '@/test/server';

import '@/i18n';

/**
 * Integrations, against the four operations the platform actually has.
 *
 * The assertions worth reading are the negative ones. There is no create and no
 * delete, a credential is never rendered, and the request that saves a label carries
 * no `credentials` key — because the endpoint treats a present key as a replacement,
 * and a form that always sent every field would destroy a stored secret on an edit
 * that had nothing to do with it.
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

function provider(over: Record<string, unknown> = {}) {
    return {
        id: 'prov-log',
        capability: 'sms',
        capability_label: 'SMS',
        driver: 'log',
        label: 'Log driver',
        settings: null,
        has_credentials: false,
        is_active: true,
        is_default: true,
        priority: 0,
        updated_at: '2026-09-01T10:00:00+00:00',
        ...over,
    };
}

function attempt(over: Record<string, unknown> = {}) {
    return {
        id: 'use-1',
        capability: 'sms',
        driver: 'log',
        status: 'success',
        reference: null,
        error_code: null,
        error_message: null,
        duration_ms: 12,
        created_at: '2026-09-08T09:00:00+00:00',
        ...over,
    };
}

function renderScreen(
    permissions: string[],
    providers: ReturnType<typeof provider>[] = [provider()],
    usage: ReturnType<typeof attempt>[] = [],
) {
    server.use(
        LANGUAGES,
        HEALTH,
        http.get('*/api/v1/admin/integrations/providers', () =>
            HttpResponse.json({ success: true, data: providers }),
        ),
        http.get('*/api/v1/admin/integrations/usage', () =>
            HttpResponse.json({ success: true, data: usage }),
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
                    <IntegrationsScreen />
                </AuthGate>
            </AppProviders>
        </MemoryRouter>,
    );
}

describe('the integrations workspace', () => {
    it('groups providers under their capability and states its condition', async () => {
        renderScreen(
            ['integrations.view'],
            [
                provider(),
                provider({
                    id: 'prov-twilio',
                    driver: 'twilio',
                    label: 'Twilio',
                    is_active: false,
                    is_default: false,
                    priority: 10,
                }),
            ],
            [attempt(), attempt({ id: 'use-2', status: 'failure', error_code: 'VENDOR_REFUSED' })],
        );

        expect(await screen.findByRole('heading', { name: 'SMS' })).toBeInTheDocument();
        expect(screen.getByText('Log driver')).toBeInTheDocument();
        expect(screen.getByText('Twilio')).toBeInTheDocument();

        // Half the attempts failed, so the capability is degraded rather than working
        // — the judgement comes from the usage log, not from the provider rows.
        expect(screen.getAllByText('Some failures').length).toBeGreaterThan(0);
    });

    it('offers no way to create or delete a provider, because the API has neither', async () => {
        renderScreen(['integrations.view', 'integrations.update']);

        await screen.findByText('Log driver');

        expect(screen.queryByRole('button', { name: /add provider/i })).not.toBeInTheDocument();
        expect(screen.queryByRole('button', { name: /delete/i })).not.toBeInTheDocument();
    });

    it('sends only what changed, so saving a label cannot rewrite a credential', async () => {
        server.use(
            http.put('*/api/v1/admin/integrations/providers/:id', () =>
                HttpResponse.json({ success: true, data: provider({ label: 'Renamed' }) }),
            ),
        );

        renderScreen(['integrations.view', 'integrations.update']);

        await userEvent.click(await screen.findByText('Log driver'));

        const label = await screen.findByLabelText('Label');
        await userEvent.clear(label);
        await userEvent.type(label, 'Renamed');
        await userEvent.click(screen.getByRole('button', { name: 'Save configuration' }));

        const write = await waitForRequest('PUT');
        const body = (await write.json()) as Record<string, unknown>;

        expect(body).toEqual({ label: 'Renamed' });
        expect(Object.keys(body)).not.toContain('credentials');
    });

    it('never renders a stored credential, and says so when replacing one', async () => {
        renderScreen(
            ['integrations.view', 'integrations.update'],
            [provider({ has_credentials: true })],
        );

        await userEvent.click(await screen.findByText('Log driver'));

        expect(await screen.findAllByText('Credentials set')).not.toHaveLength(0);
        expect(
            screen.getByText(/never returned by the platform, so they cannot be shown here/),
        ).toBeInTheDocument();

        await userEvent.click(screen.getByRole('button', { name: 'Replace credentials' }));

        // The warning is the point: a submitted map becomes the whole stored set.
        expect(
            screen.getByText(/becomes the whole credential set for this provider/),
        ).toBeInTheDocument();
    });

    it('refuses to make an inactive provider the default, as the platform does', async () => {
        renderScreen(
            ['integrations.view', 'integrations.update'],
            [
                provider({ id: 'p1', is_default: true }),
                provider({
                    id: 'p2',
                    driver: 'twilio',
                    label: 'Twilio',
                    is_active: false,
                    is_default: false,
                }),
            ],
        );

        await userEvent.click(await screen.findByText('Twilio'));

        expect(screen.getByRole('button', { name: /Make default/ })).toBeDisabled();
        expect(
            screen.getByText(
                'A provider has to be active before its capability can be pointed at it.',
            ),
        ).toBeInTheDocument();
    });

    it('shows a reader every control as absent rather than as refused', async () => {
        renderScreen(['integrations.view'], [provider({ has_credentials: true })]);

        await userEvent.click(await screen.findByText('Log driver'));

        expect(
            await screen.findByText(/may read this provider but not change it/),
        ).toBeInTheDocument();
        expect(
            screen.queryByRole('button', { name: 'Save configuration' }),
        ).not.toBeInTheDocument();
        expect(
            screen.queryByRole('button', { name: 'Replace credentials' }),
        ).not.toBeInTheDocument();
    });

    it('says the activity filter is applied here, over the window the API published', async () => {
        renderScreen(
            ['integrations.view'],
            [provider()],
            [attempt(), attempt({ id: 'use-2', status: 'failure', error_code: 'VENDOR_REFUSED' })],
        );

        const panel = await screen
            .findByRole('region', { name: 'Vendor activity' })
            .catch(() => null);
        const scope = panel ?? screen.getByText('Vendor activity').closest('section');

        expect(scope).not.toBeNull();
        expect(
            screen.getByText(/publishes the most recent attempts across every capability/),
        ).toBeInTheDocument();

        await userEvent.click(
            within(scope as HTMLElement).getByRole('radio', { name: 'Failures' }),
        );

        expect(screen.getByText('VENDOR_REFUSED')).toBeInTheDocument();
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
