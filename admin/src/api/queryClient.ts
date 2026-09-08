import { QueryClient } from '@tanstack/react-query';

import { ApiError } from './errors';

/**
 * Retry only what retrying can fix.
 *
 * A 401 will still be a 401 on the second attempt, a 422 will still be invalid, and
 * a 412 means someone else wrote first — retrying any of them wastes the user's time
 * and, for a write, risks doing the work twice. Transport failures and 5xx are the
 * genuinely transient cases, so those get two more chances.
 */
function shouldRetry(failureCount: number, error: unknown): boolean {
    if (failureCount >= 2) {
        return false;
    }

    if (error instanceof ApiError) {
        return error.status === 0 || error.status >= 500;
    }

    return false;
}

export function createQueryClient(): QueryClient {
    return new QueryClient({
        defaultOptions: {
            queries: {
                retry: shouldRetry,
                // Operational data goes stale quickly; a stale queue depth is a wrong
                // queue depth. Screens that need something slower say so themselves.
                staleTime: 30_000,
                refetchOnWindowFocus: true,
            },
            mutations: {
                // A write is never retried automatically. The operator decides.
                retry: false,
            },
        },
    });
}
