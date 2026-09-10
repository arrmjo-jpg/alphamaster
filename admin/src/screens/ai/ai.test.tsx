import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { RequestHandler } from 'msw';
import { HttpResponse, http } from 'msw';
import { MemoryRouter } from 'react-router';
import { describe, expect, it } from 'vitest';

import { AppProviders } from '@/app/AppProviders';
import { AuthGate } from '@/auth/AuthGate';
import { AiScreen } from '@/screens/AiScreen';
import { server } from '@/test/server';

import '@/i18n';

/**
 * The AI control centre.
 *
 * What is asserted is mostly what the screen refuses to conflate: a vendor that is not
 * chosen, a vendor without a key, and a vendor that did not answer are three different
 * problems with three different fixes, and an interface that ran them together would
 * send an operator to the wrong one.
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

function state(overrides: Record<string, unknown> = {}) {
    return {
        configured: true,
        provider: { driver: 'openai', label: 'OpenAI', has_credentials: true },
        available_drivers: ['openai', 'anthropic'],
        last_attempt: null,
        recent_failures: 0,
        ...overrides,
    };
}

function renderScreen(
    data: Record<string, unknown>,
    permissions: string[] = ['integrations.view', 'ai.use'],
    extra: RequestHandler[] = [],
) {
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
                    permissions,
                },
            }),
        ),
        http.get('*/api/v1/admin/ai', () => HttpResponse.json({ success: true, data })),
        ...extra,
    );

    render(
        <MemoryRouter>
            <AppProviders>
                <AuthGate>
                    <AiScreen />
                </AuthGate>
            </AppProviders>
        </MemoryRouter>,
    );
}

describe('the AI control centre', () => {
    it('says plainly when no provider is switched on', async () => {
        renderScreen(state({ configured: false, provider: null }));

        expect(await screen.findByText('No AI provider is switched on.')).toBeInTheDocument();
        expect(screen.getByText('Not ready')).toBeInTheDocument();
    });

    it('separates a chosen vendor from a stored credential', async () => {
        renderScreen(
            state({
                configured: false,
                provider: { driver: 'openai', label: 'OpenAI', has_credentials: false },
            }),
        );

        // Two facts, and the difference is what the operator has to act on.
        expect(await screen.findByText('OpenAI')).toBeInTheDocument();
        expect(screen.getByText('No credential')).toBeInTheDocument();
        expect(screen.getByText('Not ready')).toBeInTheDocument();
    });

    it('never renders anything credential-shaped', async () => {
        const { container } = { container: document.body };

        renderScreen(state());

        await screen.findByText('OpenAI');

        // The API does not send one, and this asserts the screen has not invented a
        // field for it either.
        expect(container.textContent).not.toMatch(/api[_ ]?key/i);
        expect(container.textContent).not.toMatch(/sk-/);
    });

    it('reports the last attempt, and a run of failures as its own warning', async () => {
        renderScreen(
            state({
                last_attempt: {
                    status: 'failure',
                    at: '2026-09-10T10:00:00+00:00',
                    error_code: 'model_not_found',
                    error_message: 'No such model.',
                    duration_ms: 240,
                    units: null,
                },
                recent_failures: 4,
            }),
        );

        expect(await screen.findByText('Did not answer')).toBeInTheDocument();
        expect(screen.getByText('No such model.')).toBeInTheDocument();
        // "It failed once" and "it has been failing" are different problems.
        expect(screen.getByText('4 failures in the last day.')).toBeInTheDocument();
    });

    it('runs a check and reports a vendor refusal as a finding rather than an error', async () => {
        renderScreen(
            state(),
            ['integrations.view', 'ai.use'],
            [
                http.post('*/api/v1/admin/ai/check', () =>
                    HttpResponse.json({
                        success: true,
                        message: 'checked',
                        data: {
                            answered: false,
                            driver: 'openai',
                            units: null,
                            error_code: 'invalid_api_key',
                            error_message: 'Bad key.',
                        },
                    }),
                ),
            ],
        );

        await userEvent.click(await screen.findByRole('button', { name: 'Ask the provider' }));

        // The check ran. What it found is the payload — a failed check is a successful
        // diagnostic, and rendering it as a broken screen would hide the finding.
        expect(await screen.findByText('Bad key.')).toBeInTheDocument();
        expect(screen.getByText('(invalid_api_key)')).toBeInTheDocument();
    });

    it('offers no check to somebody who may not spend on the vendor', async () => {
        renderScreen(state(), ['integrations.view']);

        expect(
            await screen.findByText('Running a check needs permission to use AI.'),
        ).toBeInTheDocument();

        expect(screen.queryByRole('button', { name: 'Ask the provider' })).not.toBeInTheDocument();
    });

    it('says where the vendor and the model are actually configured', async () => {
        renderScreen(state());

        // No form here. The vendor is a provider row and the model is a setting;
        // a second control would be a second place to disagree with the first.
        expect(
            await screen.findByText(
                'The vendor and its credential are set on the Integrations screen. The model, the answer ceiling and the timeout are settings.',
            ),
        ).toBeInTheDocument();
    });
});
