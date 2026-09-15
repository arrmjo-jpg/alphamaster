import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { HttpResponse, http } from 'msw';
import { describe, expect, it } from 'vitest';

import { server } from '@/test/server';

import type { CdnPurge, CdnState } from './api';
import { CdnWorkspace, type CdnWorkspaceProps } from './CdnWorkspace';

import '@/i18n';

/**
 * The CDN workspace. What matters: readiness and what is missing are said plainly, a
 * credential field left empty never overwrites the stored one, a purge is queued rather
 * than claimed done, purging everything needs the verified scope typed back, and a failed
 * purge can be retried.
 */

function state(overrides: Partial<CdnState> = {}): CdnState {
    return {
        configured: true,
        provider: {
            id: '01provider',
            driver: 'cloudflare',
            label: 'Cloudflare',
            is_active: true,
            has_credentials: true,
            settings: { zone_id: '023e105f4ecef8ad9ca31a8372d0c353' },
        },
        fields: { settings: ['zone_id'], credentials: ['api_token'] },
        missing: [],
        verification: {
            scope_name: 'example.test',
            scope_status: 'active',
            plan: 'pro',
            verified_at: '2026-09-14T10:00:00+00:00',
            error_code: null,
            error_message: null,
        },
        limits: [
            { kind: 'urls', supported: true, items_per_request: 100, requests_per_minute: null },
            { kind: 'tags', supported: true, items_per_request: 100, requests_per_minute: 300 },
            { kind: 'prefixes', supported: true, items_per_request: 100, requests_per_minute: 300 },
            { kind: 'hosts', supported: true, items_per_request: 100, requests_per_minute: 300 },
            { kind: 'everything', supported: true, items_per_request: 1, requests_per_minute: 300 },
        ],
        tag_header: 'Cache-Tag',
        delivery: { enabled: false, base_url: null },
        queue: { pending: 0, processing: 0, failed: 1, succeeded_last_day: 3 },
        last_attempt: null,
        ...overrides,
    };
}

function purge(overrides: Partial<CdnPurge> = {}): CdnPurge {
    return {
        id: '01purge',
        driver: 'cloudflare',
        kind: 'tags',
        kind_label: 'Cache tags',
        items: ['settings:public'],
        item_count: 1,
        status: 'failed',
        status_label: 'Failed',
        attempts: 5,
        reason: null,
        requested_by: null,
        error_code: 'CDN_VENDOR_ERROR',
        error_message: 'HTTP 502',
        provider_reference: null,
        available_at: null,
        completed_at: '2026-09-14T10:00:00+00:00',
        created_at: '2026-09-14T09:59:00+00:00',
        ...overrides,
    };
}

interface Sent {
    method: string;
    path: string;
    body: unknown;
}

function show(
    current: CdnState,
    permissions: Partial<CdnWorkspaceProps> = {},
    rows: CdnPurge[] = [],
) {
    const sent: Sent[] = [];
    const record = async (request: Request) => {
        const text = await request.text();
        sent.push({
            method: request.method,
            path: new URL(request.url).pathname,
            body: text === '' ? null : (JSON.parse(text) as unknown),
        });
    };

    server.use(
        http.get('*/api/v1/admin/cdn', () => HttpResponse.json({ success: true, data: current })),
        http.get('*/api/v1/admin/cdn/purges', () =>
            HttpResponse.json({
                success: true,
                data: rows,
                meta: { current_page: 1, last_page: 1, per_page: 25, total: rows.length },
            }),
        ),
        http.get('*/api/v1/admin/settings/definitions', () =>
            HttpResponse.json({ success: true, data: {} }),
        ),
        http.post('*/api/v1/admin/cdn/purges', async ({ request }) => {
            await record(request);

            return HttpResponse.json(
                {
                    success: true,
                    message: 'queued',
                    data: [purge({ status: 'pending', attempts: 0 })],
                },
                { status: 202 },
            );
        }),
        http.post('*/api/v1/admin/cdn/purges/:id/retry', async ({ request }) => {
            await record(request);

            return HttpResponse.json(
                { success: true, data: purge({ status: 'pending', attempts: 0 }) },
                { status: 202 },
            );
        }),
        http.put('*/api/v1/admin/integrations/providers/:id', async ({ request }) => {
            await record(request);

            return HttpResponse.json({ success: true, data: {} });
        }),
    );

    const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });

    render(
        <QueryClientProvider client={client}>
            <CdnWorkspace
                mayConfigure={permissions.mayConfigure ?? true}
                mayPurge={permissions.mayPurge ?? true}
                mayPurgeEverything={permissions.mayPurgeEverything ?? false}
            />
        </QueryClientProvider>,
    );

    return sent;
}

describe('cdn workspace', () => {
    it('says what is missing and offers no purge until the provider is ready', async () => {
        show(state({ configured: false, missing: ['zone_id', 'api_token'], verification: null }));

        expect(await screen.findByText('Not ready to purge')).toBeInTheDocument();
        expect(screen.getByText('Still needed: Zone ID, API token')).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Queue purge' })).toBeDisabled();
    });

    it('never sends an empty credential when the connection is saved', async () => {
        const sent = show(state());

        await userEvent.click(await screen.findByRole('button', { name: 'Save connection' }));

        const put = await vi_waitFor(() => sent.find((entry) => entry.method === 'PUT'));

        expect(put.body).toEqual({
            settings: { zone_id: '023e105f4ecef8ad9ca31a8372d0c353' },
            is_active: true,
        });
    });

    it('queues a purge of the lines entered and reports it as queued, not done', async () => {
        const sent = show(state());

        await userEvent.type(
            await screen.findByLabelText('One per line'),
            'https://cdn.example.test/a.png{enter}https://cdn.example.test/b.png',
        );
        await userEvent.click(screen.getByRole('button', { name: 'Queue purge' }));

        expect(await screen.findByText('1 purge request was queued.')).toBeInTheDocument();
        expect(sent.find((entry) => entry.method === 'POST')?.body).toEqual({
            kind: 'urls',
            items: ['https://cdn.example.test/a.png', 'https://cdn.example.test/b.png'],
        });
    });

    it('purges everything only once the verified scope name is typed back', async () => {
        const sent = show(state(), { mayPurgeEverything: true });

        const button = await screen.findByRole('button', { name: 'Purge everything' });
        expect(button).toBeDisabled();

        await userEvent.type(screen.getByLabelText('Type example.test to confirm'), 'example.test');
        expect(button).toBeEnabled();

        await userEvent.click(button);

        await vi_waitFor(() => sent.find((entry) => entry.method === 'POST'));
        expect(sent.find((entry) => entry.method === 'POST')?.body).toEqual({
            kind: 'everything',
            confirm: 'example.test',
        });
    });

    it('offers nothing but the reason to an operator without the permission to purge everything', async () => {
        show(state());

        expect(
            await screen.findByText(
                'Purging everything needs the cdn.purge_everything permission.',
            ),
        ).toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'Purge everything' })).not.toBeInTheDocument();
    });

    it('lists a failed purge with the vendor error, and retries it', async () => {
        const sent = show(state(), {}, [purge()]);

        expect(await screen.findByText('CDN_VENDOR_ERROR')).toBeInTheDocument();

        await userEvent.click(screen.getByRole('button', { name: 'Retry' }));

        expect(await screen.findByText('The purge was queued again.')).toBeInTheDocument();
        expect(sent.some((entry) => entry.path.endsWith('/admin/cdn/purges/01purge/retry'))).toBe(
            true,
        );
    });
});

async function vi_waitFor<T>(find: () => T | undefined): Promise<T> {
    for (let attempt = 0; attempt < 50; attempt++) {
        const found = find();

        if (found !== undefined) {
            return found;
        }

        await new Promise((resolve) => setTimeout(resolve, 20));
    }

    throw new Error('Timed out waiting for a request');
}
