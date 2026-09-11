import { render, screen, within } from '@testing-library/react';
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
 * The AI control centre: provider, API key, model, test, save.
 *
 * What is asserted is mostly what the screen refuses to do: carry one vendor's model to
 * another, send a key nobody typed, keep a key in the form after saving, show a stored
 * key, or save before the form could have been tested.
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

const CONFIGURE = ['integrations.view', 'integrations.update', 'ai.use'];

// A key an operator types into the form. Built from parts so the repository's secret
// scan, which rightly flags a key-shaped literal assigned to `api_key`, does not
// mistake a test fixture for a leaked credential.
const TYPED_KEY = ['sk', 'ant', 'typed'].join('-');

function provider(overrides: Record<string, unknown>) {
    return {
        has_key: false,
        is_default: false,
        model: null,
        ...overrides,
    };
}

const OPENAI = provider({
    driver: 'openai',
    label: 'OpenAI',
    has_key: true,
    is_default: true,
    model: 'gpt-5.6-terra',
    effective_model: 'gpt-5.6-terra',
    default_model: 'gpt-5.6-luna',
    suggested_models: ['gpt-5.6-luna', 'gpt-5.6-terra', 'gpt-5.6-sol'],
});

const ANTHROPIC = provider({
    driver: 'anthropic',
    label: 'Anthropic',
    effective_model: 'claude-sonnet-5',
    default_model: 'claude-sonnet-5',
    suggested_models: ['claude-sonnet-5', 'claude-haiku-4-5', 'claude-opus-5'],
});

const GEMINI = provider({
    driver: 'gemini',
    label: 'Google Gemini',
    model: 'my-tuned-gemini',
    effective_model: 'my-tuned-gemini',
    default_model: 'gemini-3.5-flash-lite',
    suggested_models: ['gemini-3.5-flash-lite', 'gemini-3.8-flash', 'gemini-2.5-pro'],
});

function state(overrides: Record<string, unknown> = {}) {
    return {
        configured: true,
        provider: {
            driver: 'openai',
            label: 'OpenAI',
            has_credentials: true,
            model: 'gpt-5.6-terra',
        },
        providers: [OPENAI, ANTHROPIC, GEMINI],
        last_attempt: null,
        recent_failures: 0,
        ...overrides,
    };
}

function renderScreen(
    data: Record<string, unknown> = state(),
    permissions: string[] = CONFIGURE,
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
    it('says plainly when no provider answers', async () => {
        renderScreen(state({ configured: false, provider: null }));

        expect(
            await screen.findByText(
                'No AI provider answers yet. Save a provider with its API key, or make a configured one the default.',
            ),
        ).toBeInTheDocument();
        expect(screen.getByText('Not ready')).toBeInTheDocument();
    });

    it('lists each provider with its own key, state and model', async () => {
        renderScreen();

        const list = await screen.findByRole('list');

        expect(within(list).getAllByText('Key stored')).toHaveLength(1);
        expect(within(list).getAllByText('No key')).toHaveLength(2);
        expect(within(list).getByText('Default')).toBeInTheDocument();
        expect(within(list).getByText('claude-sonnet-5')).toBeInTheDocument();
        expect(within(list).getByText('my-tuned-gemini')).toBeInTheDocument();
    });

    it('opens the form on the default provider, with its saved model and no key shown', async () => {
        renderScreen();

        expect(await screen.findByLabelText('Provider')).toHaveValue('openai');
        expect(screen.getByLabelText('Model')).toHaveValue('gpt-5.6-terra');
        expect(screen.getByLabelText('OpenAI API key')).toHaveValue('');
        expect(screen.getByLabelText('OpenAI API key')).toHaveAttribute('type', 'password');
        expect(screen.getByText(/A key is stored/)).toBeInTheDocument();
    });

    it('asks for a provider, its key and its model, and nothing about the driver', async () => {
        renderScreen();

        await screen.findByLabelText('Provider');

        // The form's own choices: which provider, and which of its models. The key is
        // the one other field, and it is a password field rather than a name/value pair.
        expect(screen.getAllByRole('combobox').map((field) => field.id)).toEqual([
            'ai-provider',
            'ai-model',
        ]);
        expect(screen.queryByRole('checkbox')).not.toBeInTheDocument();
        expect(screen.queryByText('Enabled')).not.toBeInTheDocument();
        expect(screen.queryByText(/priority/i)).not.toBeInTheDocument();
        expect(screen.queryByText(/base url/i)).not.toBeInTheDocument();
        expect(screen.queryByText(/credential/i)).not.toBeInTheDocument();
        expect(document.body.textContent).not.toMatch(/api_key/);
    });

    it('loads each provider’s own model when the provider changes, never the previous one', async () => {
        renderScreen();

        await userEvent.selectOptions(await screen.findByLabelText('Provider'), 'anthropic');

        expect(screen.getByLabelText('Model')).toHaveValue('claude-sonnet-5');
        expect(screen.getByLabelText('Anthropic API key')).toBeInTheDocument();
        expect(
            within(screen.getByLabelText('Model'))
                .getAllByRole('option')
                .map((option) => option.getAttribute('value')),
        ).toEqual(['claude-sonnet-5', 'claude-haiku-4-5', 'claude-opus-5', '__other__']);

        await userEvent.selectOptions(screen.getByLabelText('Provider'), 'gemini');

        // Saved but not among the suggestions: shown as typed, not replaced.
        expect(screen.getByLabelText('Model')).toHaveValue('__other__');
        expect(screen.getByLabelText('Model ID')).toHaveValue('my-tuned-gemini');
    });

    it('accepts a model typed by hand', async () => {
        const bodies: unknown[] = [];

        renderScreen(state(), CONFIGURE, [
            http.put('*/api/v1/admin/ai/providers/:driver', async ({ request }) => {
                bodies.push(await request.json());

                return HttpResponse.json({ success: true, message: 'saved', data: OPENAI });
            }),
        ]);

        await userEvent.selectOptions(await screen.findByLabelText('Model'), '__other__');
        await userEvent.clear(screen.getByLabelText('Model ID'));
        await userEvent.type(screen.getByLabelText('Model ID'), 'gpt-custom-1');
        await userEvent.click(screen.getByRole('button', { name: 'Save' }));

        await expect.poll(() => bodies.length).toBe(1);
        // No key typed, so none sent: the stored one is kept.
        expect(bodies[0]).toEqual({ model: 'gpt-custom-1' });
    });

    it('sends a key only when one is typed, and does not keep it in the form', async () => {
        const saved: Array<{ driver: string; body: unknown }> = [];

        renderScreen(state(), CONFIGURE, [
            http.put('*/api/v1/admin/ai/providers/:driver', async ({ request, params }) => {
                saved.push({ driver: String(params.driver), body: await request.json() });

                return HttpResponse.json({
                    success: true,
                    message: 'saved',
                    data: { ...ANTHROPIC, has_key: true },
                });
            }),
        ]);

        await userEvent.selectOptions(await screen.findByLabelText('Provider'), 'anthropic');
        await userEvent.type(screen.getByLabelText('Anthropic API key'), TYPED_KEY);
        await userEvent.click(screen.getByRole('button', { name: 'Save' }));

        await expect.poll(() => saved.length).toBe(1);
        expect(saved[0]).toEqual({
            driver: 'anthropic',
            body: { model: 'claude-sonnet-5', api_key: TYPED_KEY },
        });

        expect(await screen.findByText('Anthropic was saved.')).toBeInTheDocument();
        expect(screen.getByLabelText('Anthropic API key')).toHaveValue('');
    });

    it('tests the form as it stands, before anything is saved', async () => {
        const tested: unknown[] = [];
        const saved: unknown[] = [];

        renderScreen(state(), CONFIGURE, [
            http.post('*/api/v1/admin/ai/check', async ({ request }) => {
                tested.push(await request.json());

                return HttpResponse.json({
                    success: true,
                    message: 'checked',
                    data: {
                        answered: true,
                        driver: 'anthropic',
                        model: 'claude-sonnet-5',
                        units: 4,
                        error_code: null,
                        error_message: null,
                    },
                });
            }),
            http.put('*/api/v1/admin/ai/providers/:driver', () => {
                saved.push(true);

                return HttpResponse.json({ success: true, message: 'saved', data: ANTHROPIC });
            }),
        ]);

        await userEvent.selectOptions(await screen.findByLabelText('Provider'), 'anthropic');
        await userEvent.type(screen.getByLabelText('Anthropic API key'), TYPED_KEY);
        await userEvent.click(screen.getByRole('button', { name: 'Test connection' }));

        expect(
            await screen.findByText('Anthropic answered using claude-sonnet-5.'),
        ).toBeInTheDocument();
        expect(tested).toEqual([
            { provider: 'anthropic', model: 'claude-sonnet-5', api_key: TYPED_KEY },
        ]);
        expect(saved).toHaveLength(0);
    });

    it('shows a vendor refusal during a test as a finding', async () => {
        renderScreen(state(), CONFIGURE, [
            http.post('*/api/v1/admin/ai/check', () =>
                HttpResponse.json({
                    success: true,
                    message: 'checked',
                    data: {
                        answered: false,
                        driver: 'openai',
                        model: 'gpt-5.6-terra',
                        units: null,
                        error_code: 'invalid_api_key',
                        error_message: 'Bad key.',
                    },
                }),
            ),
        ]);

        await userEvent.click(await screen.findByRole('button', { name: 'Test connection' }));

        expect(await screen.findByText('Bad key.')).toBeInTheDocument();
        expect(screen.getByText('(invalid_api_key)')).toBeInTheDocument();
    });

    it('cannot test or save a provider with no key until one is typed', async () => {
        renderScreen();

        await userEvent.selectOptions(await screen.findByLabelText('Provider'), 'anthropic');

        expect(screen.getByRole('button', { name: 'Test connection' })).toBeDisabled();
        expect(screen.getByRole('button', { name: 'Save' })).toBeDisabled();

        await userEvent.type(screen.getByLabelText('Anthropic API key'), TYPED_KEY);

        expect(screen.getByRole('button', { name: 'Test connection' })).toBeEnabled();
        expect(screen.getByRole('button', { name: 'Save' })).toBeEnabled();
    });

    it('never shows a stored key', async () => {
        renderScreen();

        await screen.findByLabelText('Provider');

        expect(document.body.textContent).not.toMatch(/sk-/);
    });

    it('makes a configured provider the default only when asked', async () => {
        const promoted: string[] = [];

        renderScreen(
            state({ providers: [OPENAI, { ...ANTHROPIC, has_key: true }, GEMINI] }),
            CONFIGURE,
            [
                http.post('*/api/v1/admin/ai/providers/:driver/default', ({ params }) => {
                    promoted.push(String(params.driver));

                    return HttpResponse.json({ success: true, message: 'ok', data: ANTHROPIC });
                }),
            ],
        );

        const list = await screen.findByRole('list');

        // Offered for the configured provider that is not the default, and for no other.
        expect(within(list).getAllByRole('button', { name: 'Make default' })).toHaveLength(1);

        await userEvent.click(within(list).getByRole('button', { name: 'Make default' }));

        await expect.poll(() => promoted).toEqual(['anthropic']);
    });

    it('asks before removing a key', async () => {
        const removed: string[] = [];

        renderScreen(state(), CONFIGURE, [
            http.delete('*/api/v1/admin/ai/providers/:driver/key', ({ params }) => {
                removed.push(String(params.driver));

                return HttpResponse.json({
                    success: true,
                    message: 'removed',
                    data: { ...OPENAI, has_key: false },
                });
            }),
        ]);

        await userEvent.click(await screen.findByRole('button', { name: 'Remove key' }));

        expect(removed).toHaveLength(0);
        expect(screen.getByText(/Remove the OpenAI key\?/)).toBeInTheDocument();
        // OpenAI is the default, so the warning says what stops — and what does not.
        expect(screen.getByText(/the platform’s AI tasks stop/)).toBeInTheDocument();
        expect(screen.getByText(/Other providers are not affected/)).toBeInTheDocument();

        await userEvent.click(screen.getByRole('button', { name: 'Remove it' }));

        await expect.poll(() => removed).toEqual(['openai']);
    });

    it('shows the providers but no form to somebody who may not configure them', async () => {
        renderScreen(state(), ['integrations.view', 'ai.use']);

        expect(
            await screen.findByText(
                'Setting up a provider needs permission to change integrations.',
            ),
        ).toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'Save' })).not.toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Ask the provider' })).toBeInTheDocument();
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
        expect(screen.getByText('4 failures in the last day.')).toBeInTheDocument();
    });
});
