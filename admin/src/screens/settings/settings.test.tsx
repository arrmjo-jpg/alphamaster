import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { HttpResponse, http } from 'msw';
import { MemoryRouter, Route, Routes } from 'react-router';
import { describe, expect, it } from 'vitest';

import { AppProviders } from '@/app/AppProviders';
import { AuthGate } from '@/auth/AuthGate';
import { SettingsScreen } from '@/screens/SettingsScreen';
import { observed, server } from '@/test/server';

import type { SettingDefinition, SettingRow } from './api';
import { changedValues, fieldStates, invalidFields } from './draft';
import { validate } from './validation';

import '@/i18n';

/**
 * The settings workstation.
 *
 * What is worth proving is not that a form renders: it is that a change is staged
 * rather than written, that the platform's own rules decide what may be sent, that a
 * version travels with every write, and that none of the four ways a save can be
 * refused is silently swallowed.
 *
 * The catalogue's `key` is the qualified reference and its `name` is the bare key —
 * fixtures that held the same string in both once made these tests agree with a bug,
 * so they hold the real shapes.
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

const base = (over: Partial<Parameters<typeof fieldStates>[0]> = {}) =>
    fieldStates({
        definitions: [definition({})],
        rows: [row({})],
        draft: {},
        permissions: [],
        ...over,
    });

describe('what counts as a change', () => {
    it('reports nothing changed until a value actually differs', () => {
        const fields = base();

        expect(fields[0]?.changed).toBe(false);
        expect(fields[0]?.status).toBe('unchanged');
        expect(changedValues(fields)).toEqual({});
    });

    it('does not treat retyping the same value as a change', () => {
        // Otherwise a save would carry keys the operator never touched, and a
        // conflict would be reported over a value nobody edited.
        const fields = base({ draft: { site_name: 'AlphaMaster' } });

        expect(fields[0]?.changed).toBe(false);
        expect(changedValues(fields)).toEqual({});
    });

    it('stages only what differs, under the bare key the platform accepts', () => {
        const fields = fieldStates({
            definitions: [
                definition({}),
                definition({ key: 'general.tagline', name: 'tagline', label: 'Tagline' }),
            ],
            rows: [row({}), row({ id: 's2', key: 'tagline', value: 'Old' })],
            draft: { tagline: 'New' },
            permissions: [],
        });

        expect(changedValues(fields)).toEqual({ tagline: 'New' });
    });
});

describe('the six states a setting can be in', () => {
    it('marks an edited value modified', () => {
        expect(base({ draft: { site_name: 'Renamed' } })[0]?.status).toBe('modified');
    });

    it('marks it pending while the write is in flight', () => {
        expect(base({ draft: { site_name: 'Renamed' }, saving: true })[0]?.status).toBe('pending');
    });

    it('marks it conflicted when someone else saved first', () => {
        expect(base({ draft: { site_name: 'Renamed' }, conflicted: true })[0]?.status).toBe(
            'conflict',
        );
    });

    it('marks it failed when the platform refused it, which outranks the rest', () => {
        // A refusal is the most recent thing the platform said.
        const fields = base({
            draft: { site_name: 'Renamed' },
            conflicted: true,
            rejections: { site_name: 'Too long.' },
        });

        expect(fields[0]?.status).toBe('failed');
        expect(fields[0]?.rejected).toBe('Too long.');
    });

    it('marks a configured secret unverified, because set is not the same as working', () => {
        const fields = fieldStates({
            definitions: [definition({ key: 'mail.password', name: 'password', is_secret: true })],
            rows: [row({ key: 'password', value: '••••' })],
            draft: {},
            permissions: [],
        });

        expect(fields[0]?.status).toBe('unverified');
    });
});

describe('what the platform will not let you change', () => {
    it('separates a permission refusal from a setting nobody may edit', () => {
        const guarded = definition({
            key: 'security.throttle',
            name: 'throttle',
            permission: 'settings.security.update',
        });

        expect(
            fieldStates({ definitions: [guarded], rows: [], draft: {}, permissions: [] })[0],
        ).toMatchObject({ editable: false, readOnlyReason: 'permission' });

        expect(
            fieldStates({
                definitions: [guarded],
                rows: [],
                draft: {},
                permissions: ['settings.security.update'],
            })[0],
        ).toMatchObject({ editable: true, readOnlyReason: null });

        expect(
            fieldStates({
                definitions: [definition({ editable: false })],
                rows: [],
                draft: {},
                permissions: [],
            })[0],
        ).toMatchObject({ editable: false, readOnlyReason: 'declared' });
    });

    it('names a dependency that is not configured, as the reference it was declared as', () => {
        // `depends_on` is published so an interface can say what is missing rather
        // than letting an operator switch on something that silently does nothing.
        const fields = fieldStates({
            definitions: [
                definition({
                    key: 'general.captcha_enabled',
                    name: 'captcha_enabled',
                    depends_on: ['general.captcha_site_key'],
                }),
            ],
            rows: [row({ id: 's2', key: 'captcha_site_key', value: '' })],
            draft: {},
            permissions: [],
        });

        expect(fields[0]?.unmet).toEqual(['general.captcha_site_key']);
    });

    it('carries the deprecated flag through', () => {
        expect(
            fieldStates({
                definitions: [definition({ deprecated: true })],
                rows: [],
                draft: {},
                permissions: [],
            })[0]?.deprecated,
        ).toBe(true);
    });
});

describe('validation, taken from the rules the platform publishes', () => {
    it('refuses an empty value only when the definition is not nullable', () => {
        expect(validate(definition({ nullable: false }), '')).not.toBeNull();
        expect(validate(definition({ nullable: true }), '')).toBeNull();
    });

    it('counts characters for a string and magnitude for a number', () => {
        // The same split Laravel makes. Getting it backwards rejects a valid short
        // number or a valid small string.
        expect(validate(definition({ rules: ['min:5'] }), 'abc')).not.toBeNull();
        expect(validate(definition({ type: 'integer', rules: ['min:5'] }), 3)).not.toBeNull();
        expect(validate(definition({ type: 'integer', rules: ['min:5'] }), 8)).toBeNull();
    });

    it('enforces the values an `in` rule names', () => {
        const enumerated = definition({ rules: ['in:v2,v3'] });

        expect(validate(enumerated, 'v4')).not.toBeNull();
        expect(validate(enumerated, 'v3')).toBeNull();
    });

    it('ignores a rule it does not recognise rather than guessing', () => {
        // Being stricter than the server blocks a value the platform would have
        // accepted. The API is still the boundary.
        expect(validate(definition({ rules: ['regex:/^x$/', 'starts_with:a'] }), 'zzz')).toBeNull();
    });

    it('blocks a save while a staged value is invalid', () => {
        const fields = fieldStates({
            definitions: [definition({ type: 'integer', rules: ['min:5'] })],
            rows: [row({ value: 9 })],
            draft: { site_name: 1 },
            permissions: [],
        });

        expect(invalidFields(fields)).toHaveLength(1);
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

/** Edit the one field, then walk the review step the workstation requires. */
async function editAndReview(next: string) {
    const input = await screen.findByLabelText('Site name');
    await userEvent.clear(input);
    await userEvent.type(input, next);
    await userEvent.click(screen.getByRole('button', { name: 'Review 1 change' }));
}

describe('staging, reviewing and saving', () => {
    it('shows old beside new before anything is written', async () => {
        renderSettings(['settings.view', 'settings.update']);
        await editAndReview('Renamed');

        const review = screen.getByRole('region', { name: 'Review changes' });

        expect(within(review).getByText('AlphaMaster')).toBeInTheDocument();
        expect(within(review).getByText('Renamed')).toBeInTheDocument();
        // Nothing has been sent yet. The review is the interruption.
        expect(observed.some((request) => request.method === 'PUT')).toBe(false);
    });

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
        await editAndReview('Renamed');
        await userEvent.click(screen.getByRole('button', { name: 'Save 1 change' }));

        expect(await screen.findByText('Saved.')).toBeInTheDocument();

        const write = observed.find((request) => request.method === 'PUT');
        expect(write?.headers.get('If-Match')).toBe('"v1"');
        // The bare key, not the qualified reference.
        expect(await write?.clone().json()).toEqual({ settings: { site_name: 'Renamed' } });
    });

    it('offers nothing to review until something is edited', async () => {
        renderSettings(['settings.view', 'settings.update']);

        expect(await screen.findByRole('button', { name: 'Nothing to save' })).toBeDisabled();
    });
});

describe('the four ways a save is refused', () => {
    it('names a 428 as a missing precondition rather than a generic failure', async () => {
        // Unreachable from this client, which is exactly why it is worth saying
        // plainly: it means the version never arrived, not that anything is contended.
        server.use(
            http.put('*/api/v1/admin/settings/general', () =>
                HttpResponse.json(
                    {
                        success: false,
                        error: { code: 'PRECONDITION_REQUIRED', message: 'No precondition.' },
                    },
                    { status: 428 },
                ),
            ),
        );

        renderSettings(['settings.view', 'settings.update']);
        await editAndReview('Renamed');
        await userEvent.click(screen.getByRole('button', { name: 'Save 1 change' }));

        expect(await screen.findByText('The save carried no version')).toBeInTheDocument();
    });

    it('offers rebase or discard on a 412, and keeps the edits either way', async () => {
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
        await editAndReview('Renamed');
        await userEvent.click(screen.getByRole('button', { name: 'Save 1 change' }));

        expect(await screen.findByText('Someone else saved first')).toBeInTheDocument();
        expect(
            screen.getByRole('button', { name: 'Load current values, keep my edits' }),
        ).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Discard my edits' })).toBeInTheDocument();
        // Discarding the edit would be the worst possible response to "your work is
        // out of date".
        expect(screen.getByLabelText('Site name')).toHaveValue('Renamed');
    });

    it('puts a rejected value next to the field it belongs to', async () => {
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
        await editAndReview('Renamed');
        await userEvent.click(screen.getByRole('button', { name: 'Save 1 change' }));

        expect(await screen.findByText('The site name is too long.')).toBeInTheDocument();
    });

    it('shows a refusal that names no field instead of doing nothing', async () => {
        // A malformed batch is rejected against `settings` itself, which matches no
        // input. Without somewhere for it to go the save button simply did nothing.
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
        await editAndReview('Renamed');
        await userEvent.click(screen.getByRole('button', { name: 'Save 1 change' }));

        expect(
            await screen.findByText('Setting keys must be lowercase identifiers.'),
        ).toBeInTheDocument();
    });
});
