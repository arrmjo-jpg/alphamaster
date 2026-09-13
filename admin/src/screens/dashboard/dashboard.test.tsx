import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { HttpResponse, http } from 'msw';
import { MemoryRouter } from 'react-router';
import { describe, expect, it } from 'vitest';

import { AppProviders } from '@/app/AppProviders';
import { AuthGate } from '@/auth/AuthGate';
import { DashboardScreen } from '@/screens/DashboardScreen';
import { observed, server } from '@/test/server';

import type { AdminUser } from '@/screens/access/api';

import type { AuditRecord, IntegrationProvider, IntegrationUsage } from './api';
import { assessAttention } from './attention';
import { summarise } from '@/screens/integrations/capabilities';

import '@/i18n';

/**
 * The judgement is tested without rendering, and the rendering is tested without
 * re-deciding — because the interesting part of this screen is what it concludes from
 * several sources that can disagree, and what it refuses to conclude from sources it
 * was not allowed to read.
 */

function provider(overrides: Partial<IntegrationProvider>): IntegrationProvider {
    return {
        id: 'p1',
        capability: 'sms',
        capability_label: 'SMS',
        driver: 'twilio',
        label: 'Twilio',
        settings: null,
        has_credentials: true,
        is_active: true,
        is_default: true,
        priority: 1,
        updated_at: null,
        ...overrides,
    };
}

function usage(overrides: Partial<IntegrationUsage>): IntegrationUsage {
    return {
        id: 'u1',
        capability: 'sms',
        driver: 'twilio',
        status: 'success',
        reference: null,
        error_code: null,
        error_message: null,
        duration_ms: 120,
        created_at: '2026-09-08T10:00:00+00:00',
        ...overrides,
    };
}

function account(overrides: Partial<AdminUser> = {}): AdminUser {
    return {
        id: '01hzzuser',
        name: 'Sami Odeh',
        email: 'sami@example.test',
        account_type: 'admin',
        account_type_label: 'Administrator',
        is_active: true,
        phone: null,
        phone_verified: false,
        phone_verified_at: null,
        email_verified: true,
        email_verified_at: '2026-01-01T00:00:00+00:00',
        mfa_enrolled: true,
        roles: [],
        permissions: [],
        ...overrides,
    };
}

function record(overrides: Partial<AuditRecord> = {}): AuditRecord {
    return {
        id: 'a1',
        actor_id: null,
        action: 'settings.updated',
        action_label: 'Settings updated',
        subject: 'mail',
        outcome: 'failed',
        context: null,
        correlation_id: null,
        created_at: '2026-09-08T09:59:00+00:00',
        ...overrides,
    };
}

describe('what a capability is actually doing', () => {
    it('calls a configured provider with no attempts unused, not working', () => {
        const [row] = summarise([provider({})], [], (c) => c);

        expect(row?.state).toBe('idle');
        expect(row?.tone).toBe('info');
    });

    it('does not call a capability with no active provider a failure', () => {
        // A platform that does not send SMS has no SMS provider, and that is correct
        // rather than broken.
        const [row] = summarise([provider({ is_active: false })], [], (c) => c);

        expect(row?.state).toBe('unconfigured');
        expect(row?.tone).toBe('neutral');
    });

    it('reports a configured provider whose every attempt failed as failing', () => {
        // The case this panel exists for: perfectly configured, and not working.
        const [row] = summarise(
            [provider({})],
            [usage({ status: 'failure', error_code: 'AUTH_FAILED' })],
            (c) => c,
        );

        expect(row?.state).toBe('failing');
        expect(row?.tone).toBe('danger');
        expect(row?.lastFailure?.error_code).toBe('AUTH_FAILED');
    });

    it('separates some failures from all of them', () => {
        const [row] = summarise(
            [provider({})],
            [usage({ id: 'u1', status: 'failure' }), usage({ id: 'u2', status: 'success' })],
            (c) => c,
        );

        expect(row?.state).toBe('degraded');
        expect(row?.tone).toBe('warning');
        expect(row?.failures).toBe(1);
        expect(row?.attempts).toBe(2);
    });

    it('reports a working capability as working', () => {
        const [row] = summarise([provider({})], [usage({})], (c) => c);

        expect(row?.state).toBe('healthy');
        expect(row?.tone).toBe('success');
    });

    it('keeps capabilities apart rather than mixing their usage', () => {
        const rows = summarise(
            [
                provider({ id: 'p1', capability: 'sms' }),
                provider({ id: 'p2', capability: 'captcha', capability_label: 'Captcha' }),
            ],
            [usage({ capability: 'sms', status: 'failure' })],
            (c) => c,
        );

        expect(rows.map((row) => [row.capability, row.state])).toEqual([
            ['captcha', 'idle'],
            ['sms', 'failing'],
        ]);
    });
});

const CLEAR = {
    reachable: true,
    closed: false,
    accounts: [],
    capabilities: [],
    failedActions: [],
};

describe('what deserves attention', () => {
    it('finds nothing when there is nothing, and says what it looked at', () => {
        const attention = assessAttention(CLEAR);

        expect(attention.findings).toEqual([]);
        expect(attention.checked).toEqual([
            'platform',
            'maintenance',
            'administrators',
            'integrations',
            'actions',
        ]);
        expect(attention.skipped).toEqual([]);
    });

    it('reports a check it could not make rather than counting it as clear', () => {
        // The distinction the whole panel exists for: "nothing is wrong" and "I was
        // not allowed to look" are the same picture unless one of them is stated.
        const attention = assessAttention({ ...CLEAR, accounts: null, failedActions: null });

        expect(attention.findings).toEqual([]);
        expect(attention.skipped).toEqual(['administrators', 'actions']);
        expect(attention.checked).not.toContain('administrators');
    });

    it('says the platform is closed, which nothing else on the board would show', () => {
        // An administrator's own token bypasses maintenance, so every other panel
        // looks healthy while the platform refuses everybody else.
        const attention = assessAttention({ ...CLEAR, closed: true });

        expect(attention.findings.map((finding) => finding.kind)).toEqual(['platform-closed']);
        expect(attention.findings[0]?.severity).toBe('warning');
    });

    it('treats an unread maintenance setting as a check that has not run', () => {
        const attention = assessAttention({ ...CLEAR, closed: null });

        expect(attention.findings).toEqual([]);
        expect(attention.skipped).toContain('maintenance');
    });

    it('counts an administrator the perimeter would refuse, for each reason', () => {
        const attention = assessAttention({
            ...CLEAR,
            accounts: [
                account({ id: '1', name: 'Locked Out', mfa_enrolled: false }),
                account({ id: '2', name: 'Unconfirmed', email_verified: false }),
                account({ id: '3', name: 'Suspended', is_active: false }),
                account({ id: '4', name: 'Fine' }),
            ],
        });

        const finding = attention.findings.find((f) => f.kind === 'administrator-blocked');

        expect(finding?.count).toBe(3);
        expect(finding?.subjects).toEqual(['Locked Out', 'Unconfirmed', 'Suspended']);
    });

    it('leaves an ordinary account alone, however incomplete', () => {
        // These stages gate the admin perimeter. A user account without a second
        // factor is not a finding, and reporting it would bury the ones that are.
        const attention = assessAttention({
            ...CLEAR,
            accounts: [account({ account_type: 'user', mfa_enrolled: false, is_active: false })],
        });

        expect(attention.findings).toEqual([]);
    });

    it('keeps every attempt failing above some of them, and sorts critical first', () => {
        const attention = assessAttention({
            ...CLEAR,
            capabilities: summarise(
                [
                    provider({ id: 'p1', capability: 'sms' }),
                    provider({ id: 'p2', capability: 'mail', capability_label: 'Mail' }),
                ],
                [
                    usage({ id: 'u1', capability: 'sms', status: 'failure' }),
                    usage({ id: 'u2', capability: 'mail', status: 'failure' }),
                    usage({ id: 'u3', capability: 'mail', status: 'success' }),
                ],
                (c) => c,
            ),
            failedActions: [record()],
        });

        expect(attention.findings.map((f) => f.kind)).toEqual([
            'capability-failing',
            'capability-degraded',
            'action-failed',
        ]);
        expect(attention.findings[0]?.severity).toBe('critical');
    });

    it('raises a capability that will fail before it has failed once', () => {
        // `has_credentials` is published, so the refusal is knowable now. Waiting for
        // the first failure means learning it from a user rather than from here.
        const attention = assessAttention({
            ...CLEAR,
            capabilities: summarise(
                [
                    provider({
                        capability: 'mail',
                        capability_label: 'Mail',
                        has_credentials: false,
                    }),
                ],
                [],
                (c) => c,
            ),
        });

        const finding = attention.findings.find((f) => f.kind === 'capability-uncredentialed');

        expect(finding?.subjects).toEqual(['Mail']);
        expect(finding?.severity).toBe('warning');
    });

    it('does not call a pending probe a silent platform', () => {
        const attention = assessAttention({ ...CLEAR, reachable: null });

        expect(attention.findings).toEqual([]);
        expect(attention.checked).not.toContain('platform');
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

const ADMIN_LANGUAGES = http.get('*/api/v1/admin/languages', () =>
    HttpResponse.json({
        success: true,
        data: [
            {
                id: '01hzzlang',
                code: 'en',
                name: 'English',
                native_name: 'English',
                direction: 'ltr',
                is_default: true,
                is_active: true,
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

function admin(permissions: string[]) {
    return {
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
    };
}

/** Public settings. Read without a permission, so every render needs it. */
const GENERAL_SETTINGS = http.get('*/api/v1/settings/general', () =>
    HttpResponse.json({ success: true, data: { maintenance_mode: false } }),
);

function renderDashboard(permissions: string[]) {
    server.use(
        LANGUAGES,
        ADMIN_LANGUAGES,
        HEALTH,
        GENERAL_SETTINGS,
        http.get('*/api/v1/auth/me', () =>
            HttpResponse.json({ success: true, data: admin(permissions) }),
        ),
    );

    return render(
        <MemoryRouter>
            <AppProviders>
                <AuthGate>
                    <DashboardScreen />
                </AuthGate>
            </AppProviders>
        </MemoryRouter>,
    );
}

describe('the operations screen', () => {
    it('leads with attention, and names the checks behind a clear board', async () => {
        renderDashboard([]);

        expect(await screen.findByText('Nothing needs attention.')).toBeInTheDocument();
        expect(screen.getByText(/Checked: the platform probe/)).toBeInTheDocument();
        // An account with no grants was checked for one thing out of four, and is
        // told so rather than shown an all-clear that covers almost nothing.
        expect(
            screen.getByText(/Not checked.*administrator accounts.*vendor capabilities/),
        ).toBeInTheDocument();
    });

    it('omits a panel the account may not read, and does not request it', async () => {
        // Not merely hidden: the request is never made. Sending one that is certain
        // to be refused would render an error and tell the operator something is
        // wrong when nothing is.
        renderDashboard([]);

        await screen.findByText('Nothing needs attention.');

        expect(screen.queryByText('Integrations')).not.toBeInTheDocument();
        expect(screen.queryByText('Administrative trail')).not.toBeInTheDocument();

        const requested = observed
            .filter((request) => request.url.includes('/api/v1/admin/'))
            .map((request) => new URL(request.url).pathname);

        // `/admin/languages` is behind the perimeter and no permission, so it is the
        // one admin call an account with no grants may still make.
        expect(requested).toEqual(['/api/v1/admin/languages']);
    });

    it('names the administrators the perimeter would refuse, and where to go', async () => {
        server.use(
            http.get('*/api/v1/admin/users', () =>
                HttpResponse.json({
                    success: true,
                    data: [account({ name: 'Sami Odeh', mfa_enrolled: false })],
                }),
            ),
        );

        renderDashboard(['users.view']);

        expect(await screen.findByText('One administrator cannot sign in.')).toBeInTheDocument();
        expect(screen.getByText('Sami Odeh')).toBeInTheDocument();
        expect(screen.getByRole('link', { name: 'Open accounts' })).toHaveAttribute(
            'href',
            '/access/users',
        );
        // And it becomes work, not just an alarm.
        expect(screen.getByText('Restore access for one administrator')).toBeInTheDocument();
    });

    it('asks the server for failures rather than sifting one page of the trail', async () => {
        server.use(
            http.get('*/api/v1/admin/audit', ({ request }) =>
                HttpResponse.json({
                    success: true,
                    data:
                        new URL(request.url).searchParams.get('outcome') === 'failed'
                            ? [record()]
                            : [
                                  record({
                                      id: 'a2',
                                      action_label: 'Role changed',
                                      outcome: 'succeeded',
                                  }),
                              ],
                    meta: { pagination: { total: 41 } },
                }),
            ),
        );

        renderDashboard(['audit.view']);

        expect(await screen.findByText('One administrative action failed.')).toBeInTheDocument();

        const failureQuery = observed.some((request) => request.url.includes('outcome=failed'));
        expect(failureQuery).toBe(true);

        // The trail below shows everything until asked otherwise, and the total sits
        // in system state where the rest of the platform's size does.
        expect(screen.getByText('Role changed')).toBeInTheDocument();
        expect(screen.getByText('41 entries')).toBeInTheDocument();

        await userEvent.click(screen.getByRole('radio', { name: 'Failed' }));

        // Two of it now: the attention panel names the failed action as a subject,
        // and the trail lists the record itself.
        await expect.poll(() => screen.getAllByText('Settings updated').length).toBeGreaterThan(1);
        expect(screen.queryByText('Role changed')).not.toBeInTheDocument();
    });

    it('keeps a failing panel from taking the screen with it', async () => {
        server.use(
            http.get('*/api/v1/admin/audit', () =>
                HttpResponse.json(
                    { success: false, error: { code: 'FORBIDDEN', message: 'Not permitted.' } },
                    { status: 403 },
                ),
            ),
        );

        renderDashboard(['audit.view']);

        expect((await screen.findAllByText('Not permitted.')).length).toBeGreaterThan(0);
        // The rest of the dashboard is still there.
        expect(screen.getByText('Platform')).toBeInTheDocument();
        expect(screen.getByText('Reachability')).toBeInTheDocument();
    });
});
