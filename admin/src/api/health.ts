import { fetchData } from './client';

export interface PlatformHealth {
    status: string;
    timestamp: string;
    framework: string;
}

/**
 * The liveness probe, which answers from the application itself.
 *
 * It reports that PHP is executing, not that the database or the cache is reachable
 * — the route's own comment says so, and so does every surface that renders it. A
 * green light that means less than a reader assumes is worse than no light.
 *
 * It lives here rather than inside a screen because two unrelated places need it:
 * the operations dashboard, and the sign-in cover, where it is the one honest way to
 * say "this is a running system" without inventing a decorative graphic.
 *
 * Unauthenticated, so the cover may ask before anyone has signed in.
 */
export async function platformHealth(signal?: AbortSignal): Promise<PlatformHealth> {
    return fetchData<PlatformHealth>('/health', { ...(signal ? { signal } : {}) });
}
