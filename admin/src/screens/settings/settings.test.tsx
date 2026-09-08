import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { HttpResponse, http } from 'msw';
import { MemoryRouter, Route, Routes } from 'react-router';
import { describe, expect, it } from 'vitest';

import { AppProviders } from '@/app/AppProviders';
import { AuthGate } from '@/auth/AuthGate';
import { SettingsScreen } from '@/screens/SettingsScreen';
import { observed, server } from '@/test/server';

import type { SettingDefinition, SettingRow } from './api';
import { changedValues, fieldStates } from './draft';

import '@/i18n';

/**
 * The two things worth proving about a settings editor: that it stages a change
 * rather than writing one, and that it refuses to overwrite somebody else.
 */

/**
 * The catalogue's `key` is the qualified reference and its `name` is the bare key.
 * These fixtures held the same string in both, which made every test that exercised
 * the pairing vacuous — the screen rendered empty fields and submitted keys the API
 * refused, and nothing here noticed until it was run against the platform.
 */
function definition(overrides: Partial<SettingDefinition>): SettingDefinition {
    return {
        key: 'general.site_name',
        group: 'general',
        name: 'site_name',
        label: 'Site name',
        help: null,
        type: 'string',
        type_label: 'String',
        nullable: false,
        editable: true,
        is_secret: false,
        is_public: true,
        is_localized: false,
        default: null,
        depends_on: [],
        rules: [],
        permission: null,
        deprecated: false,
        ...overrides,
    };
}

function row(overrides: Partial<SettingRow>): SettingRow {
    return {
        id: 's1',
        group: 'general',
        key: 'site_name',
        value: 'AlphaMaster',
        is_localized: false,
        locale: null,
        type: 'string',
        type_label: 'String',
        is_secret: false,
        is_public: true,
        description: null,
        updated_at: null,
        ...overrides,
    };
}

describe('what counts as a change', () => {
    it('reports nothing changed until a value actually differs', () => {
        const fields = fieldStates([definition({})], [row({})], {}, []);

        expect(fields[0]?.changed).toBe(false);
        expect(changedValues(fields)).toEqual({});
    });

    it('does not treat retyping the same value as a change', () => {
        // Otherwise a save would carry keys the operator never touched, and a
        // conflict would be reported over a value nobody edited.
        const fields = fieldStates([definition({})], [row({})], { site_name: 'AlphaMaster' }, []);

        expect(fields[0]?.changed).toBe(false);
        expect(changedValues(fields)).toEqual({});
    });

    it('stages only what differs', () => {
        const fields = fieldStates(
            [
                definition({}),
                definition({ key: 'general.tagline', name: 'tagline', label: 'Tagline' }),
            ],
            [row({}), row({ id: 's2', key: 'tagline', value: 'Old' })],
            { tagline: 'New' },
            [],
        );

        expect(changedValues(fields)).toEqual({ tagline: 'New' });
    });

    it('marks a setting uneditable when the account lacks the permission it names', () => {
        const guarded = definition({
            key: 'security.throttle',
            name: 'throttle',
            permission: 'settings.security.update',
        });

        expect(fieldStates([guarded], [], {}, [])[0]?.editable).toBe(false);
        expect(fieldStates([guarded], [], {}, ['settings.security.update'])[0]?.editable).toBe(
            true,
        );
    });

    it('names a dependency that is not configured yet', () => {
        // `depends_on` is published so an interface can say what is missing rather
        // than letting an operator switch on something that silently does nothing.
        const fields = fieldStates(
            [
                definition({
                    key: 'general.captcha_enabled',
                    name: 'captcha_enabled',
                    // Declared as a qualified reference, which is the only form the
                    // catalogue accepts.
                    depends_on: ['general.captcha_site_key'],
                }),
            ],
            [row({ id: 's2', key: 'captcha_site_key', value: '' })],
            {},
            [],
        );

        expect(fields[0]?.unmet).toEqual(['general.captcha_site_key']);
    });
});

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

const DEFINITIONS = http.get('*/api/v1/admin/settings/definitions', () =>
    HttpResponse.json({ success: true, data: { general: [definition({})] } }),
);

const HISTORY = http.get('*/api/v1/admin/settings/general/history', () =>
    HttpResponse.json({ success: true, data: [], meta: { group: 'general', count: 0 } }),
);

function groupResponse(version: string) {
    return http.get('*/api/v1/admin/settings/general', () =>
        HttpResponse.json({ success: true, data: [row({})], meta: { version } }),
    );
}

function renderSettings(permissions: string[]) {
    server.use(
        LANGUAGES,
        HEALTH,
        DEFINITIONS,
        HISTORY,
        groupResponse('v1'),
        http.get('*/api/v1/auth/me', () =>
            HttpResponse.json({
                success: true,
                data: {
                    id: '01hzz',
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

    // Mounted the way the shell mounts it — a wildcard route, one router — because
    // the screen has its own nested routes and react-router refuses a router inside
    // a router.
    return render(
        <MemoryRouter initialEntries={['/settings/general']}>
            <AppProviders>
                <AuthGate>
                    <Routes>
                        <Route element={<SettingsScreen />} path="/settings/*" />
                    </Routes>
                </AuthGate>
            </AppProviders>
        </MemoryRouter>,
    );
}

describe('saving a group', () => {
    it('carries the version the page was read at, so a stale write is refusable', async () => {
        server.use(
            http.put('*/api/v1/admin/settings/general', () =>
                HttpResponse.json({
                    success: true,
                    message: 'Updated.',
                    data: { group: 'general', updated: {} },
                    meta: { version: 'v2' },
                }),
            ),
        );

        renderSettings(['settings.view', 'settings.update']);

        const input = await screen.findByLabelText('Site name');
        await userEvent.clear(input);
        await userEvent.type(input, 'Renamed');
        await userEvent.click(screen.getByRole('button', { name: 'Save 1 change' }));

        expect(await screen.findByText('Saved.')).toBeInTheDocument();

        const write = observed.find((request) => request.method === 'PUT');
        expect(write?.headers.get('If-Match')).toBe('"v1"');

        // The bare key, not the qualified reference. Sending `general.site_name`
        // is refused by the platform as not being a setting key at all, and that
        // is what the running application did before the two were told apart.
        expect(await write?.clone().json()).toEqual({ settings: { site_name: 'Renamed' } });
    });

    it('shows a refusal that names no field instead of doing nothing', async () => {
        // A malformed batch is rejected against `settings` itself, which matches no
        // input. Without somewhere for it to go the save button simply did nothing,
        // twice, and then the operator reloaded.
        server.use(
            http.put('*/api/v1/admin/settings/general', () =>
                HttpResponse.json(
                    {
                        success: false,
                        error: {
                            code: 'VALIDATION_ERROR',
                            message: 'The given data was invalid.',
                            details: { settings: ['Setting keys must be lowercase identifiers.'] },
                        },
                    },
                    { status: 422 },
                ),
            ),
        );

        renderSettings(['settings.view', 'settings.update']);

        const input = await screen.findByLabelText('Site name');
        await userEvent.type(input, '!');
        await userEvent.click(screen.getByRole('button', { name: 'Save 1 change' }));

        expect(
            await screen.findByText('Setting keys must be lowercase identifiers.'),
        ).toBeInTheDocument();
    });

    it('refuses to lose an operator’s edits when someone else saved first', async () => {
        server.use(
            http.put('*/api/v1/admin/settings/general', () =>
                HttpResponse.json(
                    {
                        success: false,
                        error: {
                            code: 'SETTING_VERSION_CONFLICT',
                            message: 'This group changed.',
                            details: { current_version: 'v9' },
                        },
                    },
                    { status: 412 },
                ),
            ),
        );

        renderSettings(['settings.view', 'settings.update']);

        const input = await screen.findByLabelText('Site name');
        await userEvent.clear(input);
        await userEvent.type(input, 'Renamed');
        await userEvent.click(screen.getByRole('button', { name: 'Save 1 change' }));

        expect(await screen.findByText('Someone else saved first')).toBeInTheDocument();
        // The edit is still on screen. Discarding it would be the worst possible
        // response to "your work is out of date".
        expect(screen.getByLabelText('Site name')).toHaveValue('Renamed');
    });

    it('puts the platform’s refusal next to the field it refused', async () => {
        server.use(
            http.put('*/api/v1/admin/settings/general', () =>
                HttpResponse.json(
                    {
                        success: false,
                        error: {
                            code: 'SETTING_VALUE_REJECTED',
                            message: 'A value was rejected.',
                            details: { key: 'site_name', message: 'The site name is too long.' },
                        },
                    },
                    { status: 422 },
                ),
            ),
        );

        renderSettings(['settings.view', 'settings.update']);

        const input = await screen.findByLabelText('Site name');
        await userEvent.type(input, '!');
        await userEvent.click(screen.getByRole('button', { name: 'Save 1 change' }));

        expect(await screen.findByText('The site name is too long.')).toBeInTheDocument();
    });

    it('offers nothing to save until something is edited', async () => {
        renderSettings(['settings.view', 'settings.update']);

        expect(await screen.findByRole('button', { name: 'Nothing to save' })).toBeDisabled();
    });
});
