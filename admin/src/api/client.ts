import { ApiError, type ApiErrorBody } from './errors';

/**
 * The one place a request leaves the Admin.
 *
 * Three things are settled here and nowhere else: how the credential travels, how
 * the platform's response envelope is unwrapped, and what a failure looks like by
 * the time a caller sees it.
 */

/**
 * Relative, always. The Admin and the API share an origin (ADR 0042) — nginx in
 * production, the Vite proxy in development — so there is no base URL to configure
 * per environment and one built image is correct everywhere.
 */
const BASE = '/api/v1';

/** The platform's success envelope (ADR 0031). */
interface Envelope<T> {
    success: true;
    message?: string;
    data?: T;
    meta?: unknown;
}

export interface RequestOptions {
    method?: 'GET' | 'POST' | 'PUT' | 'PATCH' | 'DELETE';
    body?: unknown;
    /** Sent as `If-Match`; the settings write requires it (ADR 0038). */
    ifMatch?: string;
    query?: Record<string, string | number | boolean | undefined>;
    locale?: string;
    signal?: AbortSignal;
}

/** A response and the envelope metadata a caller occasionally needs. */
export interface ApiResult<T> {
    data: T;
    message?: string;
    meta?: unknown;
    /** From the `ETag` header, which the settings group read supplies. */
    etag?: string;
}

function buildUrl(path: string, query?: RequestOptions['query']): string {
    const url = `${BASE}${path}`;

    if (!query) {
        return url;
    }

    const params = new URLSearchParams();

    for (const [key, value] of Object.entries(query)) {
        if (value !== undefined && value !== '') {
            params.append(key, String(value));
        }
    }

    const serialised = params.toString();

    return serialised === '' ? url : `${url}?${serialised}`;
}

/**
 * Perform a request and return the unwrapped payload.
 *
 * `credentials: 'include'` is the load-bearing line. The session travels in an
 * HttpOnly cookie the page cannot read (ADR 0042); without this the browser omits
 * it and every authenticated call fails as a bare 401 that looks like a broken
 * backend. There is deliberately no token handling anywhere in this module —
 * nothing reads `document.cookie`, nothing writes storage.
 */
export async function request<T>(
    path: string,
    options: RequestOptions = {},
): Promise<ApiResult<T>> {
    const { method = 'GET', body, ifMatch, query, locale, signal } = options;

    const headers: Record<string, string> = { Accept: 'application/json' };

    if (body !== undefined) {
        headers['Content-Type'] = 'application/json';
    }

    if (ifMatch !== undefined) {
        headers['If-Match'] = `"${ifMatch}"`;
    }

    if (locale !== undefined) {
        headers['X-Locale'] = locale;
    }

    let response: Response;

    try {
        response = await fetch(buildUrl(path, query), {
            method,
            headers,
            credentials: 'include',
            ...(body !== undefined ? { body: JSON.stringify(body) } : {}),
            ...(signal ? { signal } : {}),
        });
    } catch (cause) {
        // A transport failure is not an API error and must not be reported as one:
        // there is no code to branch on and no message the platform wrote.
        throw ApiError.transport(cause);
    }

    const payload: unknown = await response.json().catch(() => null);

    if (!response.ok) {
        throw ApiError.fromResponse(response, payload as ApiErrorBody | null);
    }

    const envelope = payload as Envelope<T> | null;
    const etag = response.headers.get('ETag')?.replace(/"/g, '');

    return {
        data: (envelope?.data ?? null) as T,
        ...(envelope?.message !== undefined ? { message: envelope.message } : {}),
        ...(envelope?.meta !== undefined ? { meta: envelope.meta } : {}),
        ...(etag !== undefined && etag !== '' ? { etag } : {}),
    };
}

/** The payload alone, for the common case that wants nothing else. */
export async function fetchData<T>(path: string, options: RequestOptions = {}): Promise<T> {
    return (await request<T>(path, options)).data;
}
