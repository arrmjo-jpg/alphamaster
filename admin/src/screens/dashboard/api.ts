import { fetchData, request } from '@/api/client';
import type {
    AdminAuditIndexResponses,
    AdminIntegrationsProvidersIndexResponses,
    AdminIntegrationsUsageResponses,
} from '@/api/generated';

// The liveness probe is shared with the sign-in cover, so it lives beside the client
// rather than inside this screen.
export { platformHealth, type PlatformHealth } from '@/api/health';

/**
 * What the dashboard asks the platform, and nothing it invents.
 *
 * Every row type below is taken from the generated contract rather than restated, so
 * a renamed field breaks the typecheck here instead of rendering as `undefined` in a
 * panel nobody is watching closely.
 */

export type AuditRecord = AdminAuditIndexResponses[200]['data'][number];
export type IntegrationProvider = AdminIntegrationsProvidersIndexResponses[200]['data'][number];
export type IntegrationUsage = AdminIntegrationsUsageResponses[200]['data'][number];

export async function integrationProviders(signal?: AbortSignal): Promise<IntegrationProvider[]> {
    return fetchData<IntegrationProvider[]>('/admin/integrations/providers', {
        ...(signal ? { signal } : {}),
    });
}

export async function integrationUsage(signal?: AbortSignal): Promise<IntegrationUsage[]> {
    return fetchData<IntegrationUsage[]>('/admin/integrations/usage', {
        ...(signal ? { signal } : {}),
    });
}

export interface AuditPage {
    records: AuditRecord[];
    /** Total across every page, from the envelope's pagination meta. */
    total: number | null;
}

interface PaginationMeta {
    pagination?: { total?: unknown };
}

/** The most recent entries, with the total the trail holds behind them. */
export async function recentAudit(perPage: number, signal?: AbortSignal): Promise<AuditPage> {
    const result = await request<AuditRecord[]>('/admin/audit', {
        query: { per_page: perPage },
        ...(signal ? { signal } : {}),
    });

    const total = (result.meta as PaginationMeta | undefined)?.pagination?.total;

    return {
        records: result.data,
        total: typeof total === 'number' ? total : null,
    };
}
