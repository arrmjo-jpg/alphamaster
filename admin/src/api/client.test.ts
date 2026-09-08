import { afterEach, describe, expect, it, vi } from 'vitest';

import { request } from './client';
import { ApiError, ErrorCode } from './errors';

function jsonResponse(body: unknown, init: ResponseInit = {}): Response {
    return new Response(JSON.stringify(body), {
        status: 200,
        headers: { 'Content-Type': 'application/json' },
        ...init,
    });
}

function stubFetch(response: Response | Error) {
    // Typed with fetch's own parameters so `mock.calls` carries the url and init
    // rather than an empty tuple.
    const fetchMock = vi.fn((..._args: Parameters<typeof fetch>) =>
        response instanceof Error ? Promise.reject(response) : Promise.resolve(response),
    );

    vi.stubGlobal('fetch', fetchMock);

    return fetchMock;
}

afterEach(() => {
    vi.unstubAllGlobals();
});

describe('request', () => {
    it('sends credentials so the HttpOnly session cookie travels with the call', async () => {
        const fetchMock = stubFetch(jsonResponse({ success: true, data: { id: '1' } }));

        await request('/auth/me');

        const init = fetchMock.mock.calls[0]?.[1] as RequestInit;
        expect(init.credentials).toBe('include');
    });

    it('never sets an Authorization header, because it holds no token', async () => {
        const fetchMock = stubFetch(jsonResponse({ success: true, data: null }));

        await request('/auth/me');

        const init = fetchMock.mock.calls[0]?.[1] as RequestInit;
        expect(Object.keys(init.headers as Record<string, string>)).not.toContain('Authorization');
    });

    it('addresses the API relatively, so one build is correct in every environment', async () => {
        const fetchMock = stubFetch(jsonResponse({ success: true, data: null }));

        await request('/settings/mail');

        expect(fetchMock.mock.calls[0]?.[0]).toBe('/api/v1/settings/mail');
    });

    it('appends only the query values that are present', async () => {
        const fetchMock = stubFetch(jsonResponse({ success: true, data: [] }));

        await request('/admin/audit', { query: { page: 2, search: undefined, active: true } });

        expect(fetchMock.mock.calls[0]?.[0]).toBe('/api/v1/admin/audit?page=2&active=true');
    });

    it('quotes If-Match, which is how the concurrency guard expects it', async () => {
        const fetchMock = stubFetch(jsonResponse({ success: true, data: null }));

        await request('/admin/settings/mail', { method: 'PUT', body: {}, ifMatch: 'v7' });

        const init = fetchMock.mock.calls[0]?.[1] as RequestInit;
        expect((init.headers as Record<string, string>)['If-Match']).toBe('"v7"');
    });

    it('unwraps the envelope and reports the ETag the caller will need to write back', async () => {
        stubFetch(
            jsonResponse(
                { success: true, message: 'Loaded', data: { name: 'mail' }, meta: { total: 1 } },
                { headers: { 'Content-Type': 'application/json', ETag: '"v3"' } },
            ),
        );

        const result = await request<{ name: string }>('/admin/settings/mail');

        expect(result.data).toEqual({ name: 'mail' });
        expect(result.message).toBe('Loaded');
        expect(result.meta).toEqual({ total: 1 });
        expect(result.etag).toBe('v3');
    });

    it('turns the failure envelope into an ApiError carrying the contract code', async () => {
        stubFetch(
            jsonResponse(
                {
                    success: false,
                    error: {
                        code: 'SETTING_VERSION_CONFLICT',
                        message: 'Someone else saved first.',
                        details: { current_version: 'v9' },
                    },
                },
                { status: 412, headers: { 'Content-Type': 'application/json' } },
            ),
        );

        const error = await request('/admin/settings/mail', { method: 'PUT' }).catch(
            (caught: unknown) => caught,
        );

        expect(error).toBeInstanceOf(ApiError);
        expect((error as ApiError).code).toBe(ErrorCode.SettingVersionConflict);
        expect((error as ApiError).status).toBe(412);
        expect((error as ApiError).currentVersion).toBe('v9');
    });

    it('reports a response that is not the envelope as an ApiError all the same', async () => {
        stubFetch(new Response('<html>502</html>', { status: 502 }));

        const error = (await request('/auth/me').catch((caught: unknown) => caught)) as ApiError;

        expect(error).toBeInstanceOf(ApiError);
        expect(error.code).toBe('HTTP_502');
    });

    it('distinguishes a request that never arrived from one the platform refused', async () => {
        stubFetch(new TypeError('Failed to fetch'));

        const error = (await request('/auth/me').catch((caught: unknown) => caught)) as ApiError;

        expect(error.code).toBe(ErrorCode.Transport);
        expect(error.status).toBe(0);
    });
});
