import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { HttpResponse, http } from 'msw';
import { describe, expect, it } from 'vitest';

import { observed, server } from '@/test/server';

import type { CacheNamespaceRow } from './api';
import { CacheWorkspace } from './CacheWorkspace';

import '@/i18n';

/**
 * The cache workspace. What matters: the operator sees each namespace's policy, the two
 * protected namespaces offer no action, invalidating asks first, and nothing is offered
 * to an operator who may not invalidate.
 */

function row(overrides: Partial<CacheNamespaceRow>): CacheNamespaceRow {
    return {
        namespace: 'settings',
        ttl_seconds: 86400,
        failure_mode: 'fail_open',
        version: 1,
        generation: 3,
        flushable: true,
        ...overrides,
    };
}

function show(rows: CacheNamespaceRow[], mayInvalidate = true) {
    server.use(
        http.get('*/api/v1/admin/cache', () => HttpResponse.json({ success: true, data: rows })),
        http.post('*/api/v1/admin/cache/:namespace/flush', ({ params }) =>
            HttpResponse.json({
                success: true,
                message: 'The cache namespace was invalidated.',
                data: row({ namespace: String(params.namespace), generation: 4 }),
            }),
        ),
    );

    const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });

    return render(
        <QueryClientProvider client={client}>
            <CacheWorkspace mayInvalidate={mayInvalidate} />
        </QueryClientProvider>,
    );
}

describe('cache workspace', () => {
    it('lists each namespace with its policy, and the protected one offers no action', async () => {
        show([
            row({}),
            row({
                namespace: 'auth',
                ttl_seconds: 900,
                failure_mode: 'fail_closed',
                flushable: false,
            }),
        ]);

        expect(await screen.findByText('settings')).toBeInTheDocument();
        expect(screen.getByText('auth')).toBeInTheDocument();
        expect(screen.getByText('Cannot be invalidated here')).toBeInTheDocument();
        expect(screen.getAllByRole('button', { name: 'Invalidate' })).toHaveLength(1);
    });

    it('asks before invalidating, then invalidates', async () => {
        show([row({})]);

        await userEvent.click(await screen.findByRole('button', { name: 'Invalidate' }));

        expect(observed.some((request) => request.method === 'POST')).toBe(false);

        await userEvent.click(screen.getByRole('button', { name: 'Invalidate now' }));

        expect(await screen.findByText(/cache was invalidated/)).toBeInTheDocument();
        expect(
            observed.some(
                (request) =>
                    request.method === 'POST' &&
                    request.url.endsWith('/admin/cache/settings/flush'),
            ),
        ).toBe(true);
    });

    it('offers nothing to an operator who may not invalidate', async () => {
        show([row({})], false);

        expect(await screen.findByText('settings')).toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'Invalidate' })).not.toBeInTheDocument();
    });
});
