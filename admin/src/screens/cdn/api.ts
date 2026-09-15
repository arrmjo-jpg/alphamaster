import { fetchData, request } from '@/api/client';
import type {
    AdminCdnPurgesIndexResponses,
    AdminCdnShowResponses,
    AdminCdnVerifyResponses,
} from '@/api/generated';

/**
 * The CDN workspace's endpoints (ADR 0053).
 *
 * Vendor-neutral, like the API behind them: the fields a provider needs, its limits and
 * what verification found all arrive from the platform, so this client never learns that
 * Cloudflare calls its scope a zone. Saving the connection is the ordinary provider update
 * in `@/screens/integrations/api`; the delivery settings are the ordinary settings group.
 */

export type CdnState = AdminCdnShowResponses[200]['data'];
export type CdnPurge = AdminCdnPurgesIndexResponses[200]['data'][number];
export type CdnVerification = AdminCdnVerifyResponses[200]['data'];
export type PurgeKind = 'urls' | 'tags' | 'prefixes' | 'hosts' | 'everything';
export type PurgeStatus = 'pending' | 'processing' | 'succeeded' | 'failed';

/** At most this many items in one request; the platform splits them per vendor call. */
export const MAX_PURGE_ITEMS = 500;

export async function cdnState(signal?: AbortSignal): Promise<CdnState> {
    return fetchData<CdnState>('/admin/cdn', { ...(signal ? { signal } : {}) });
}

/** Ask the vendor about the configured scope. A refusal is a 200 with `reachable: false`. */
export async function verifyCdn(): Promise<CdnVerification> {
    return fetchData<CdnVerification>('/admin/cdn/verify', { method: 'POST' });
}

export interface PurgePage {
    rows: CdnPurge[];
    page: number;
    pages: number;
    total: number;
}

export async function cdnPurges(
    status: PurgeStatus | null,
    page: number,
    signal?: AbortSignal,
): Promise<PurgePage> {
    const result = await request<CdnPurge[]>('/admin/cdn/purges', {
        query: { page, ...(status === null ? {} : { status }) },
        ...(signal ? { signal } : {}),
    });

    const meta = result.meta as
        { current_page?: number; last_page?: number; total?: number } | undefined;

    return {
        rows: result.data,
        page: meta?.current_page ?? page,
        pages: meta?.last_page ?? 1,
        total: meta?.total ?? result.data.length,
    };
}

export interface PurgeBody {
    kind: PurgeKind;
    items?: string[];
    reason?: string;
    confirm?: string;
}

/** Queued, never performed on the spot: the answer is the recorded requests. */
export async function queuePurge(body: PurgeBody): Promise<CdnPurge[]> {
    return fetchData<CdnPurge[]>('/admin/cdn/purges', { method: 'POST', body });
}

export async function retryPurge(id: string): Promise<CdnPurge> {
    return fetchData<CdnPurge>(`/admin/cdn/purges/${id}/retry`, { method: 'POST' });
}
