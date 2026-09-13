import { fetchData } from '@/api/client';
import type { AdminCacheIndexResponses } from '@/api/generated';

/**
 * The application cache endpoints (ADR 0035, ADR 0051 §7).
 *
 * Policies and namespace invalidation, never keys: there is no endpoint that reads or
 * removes an individual entry, and none that empties the store.
 */

export type CacheNamespaceRow = AdminCacheIndexResponses[200]['data'][number];

export async function cacheNamespaces(signal?: AbortSignal): Promise<CacheNamespaceRow[]> {
    return fetchData<CacheNamespaceRow[]>('/admin/cache', { ...(signal ? { signal } : {}) });
}

/** Every entry in the namespace is rebuilt from its source on next use. Audited. */
export async function flushCacheNamespace(namespace: string): Promise<CacheNamespaceRow> {
    return fetchData<CacheNamespaceRow>(`/admin/cache/${namespace}/flush`, { method: 'POST' });
}
