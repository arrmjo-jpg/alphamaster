import { fetchData, request } from '@/api/client';
import type { AdminAuditIndexResponses, AdminLanguagesIndexResponses } from '@/api/generated';

// The liveness probe is shared with the sign-in cover, so it lives beside the client
// rather than inside this screen.
export { platformHealth, type PlatformHealth } from '@/api/health';

// Integrations are a module of their own now, and the dashboard summarises them
// rather than owning them. Re-exported so the panels here keep one import, and so
// that there is one definition of what a provider is rather than a dashboard copy.
export {
    integrationProviders,
    integrationUsage,
    type IntegrationProvider,
    type IntegrationUsage,
} from '@/screens/integrations/api';

/**
 * What the dashboard asks the platform, and nothing it invents.
 *
 * Every row type below is taken from the generated contract rather than restated, so
 * a renamed field breaks the typecheck here instead of rendering as `undefined` in a
 * panel nobody is watching closely.
 */

export type AuditRecord = AdminAuditIndexResponses[200]['data'][number];
export type AdminLanguage = AdminLanguagesIndexResponses[200]['data'][number];

export async function adminLanguages(signal?: AbortSignal): Promise<AdminLanguage[]> {
    return fetchData<AdminLanguage[]>('/admin/languages', { ...(signal ? { signal } : {}) });
}

export interface AuditPage {
    records: AuditRecord[];
    /** Total across every page, from the envelope's pagination meta. */
    total: number | null;
}

interface PaginationMeta {
    pagination?: { total?: unknown };
}

/**
 * The most recent entries, with the total the trail holds behind them.
 *
 * `outcome` is the endpoint's own filter, so asking for failures is a question the
 * server answers rather than a page this client fetches and sifts — which matters,
 * because a failure eight pages back is exactly the one worth surfacing.
 */
export async function recentAudit(
    perPage: number,
    outcome?: 'failed',
    signal?: AbortSignal,
): Promise<AuditPage> {
    const result = await request<AuditRecord[]>('/admin/audit', {
        query: { per_page: perPage, ...(outcome === undefined ? {} : { outcome }) },
        ...(signal ? { signal } : {}),
    });

    const total = (result.meta as PaginationMeta | undefined)?.pagination?.total;

    return {
        records: result.data,
        total: typeof total === 'number' ? total : null,
    };
}

/**
 * Whether the platform is closed for maintenance.
 *
 * Read from the public settings group rather than from an endpoint of its own,
 * because that is where it lives: `general.maintenance_mode` is a public setting, so
 * this needs no permission and answers the same for anyone who asks. An administrator
 * whose token bypasses maintenance sees a working platform, which is exactly why the
 * board has to say the platform is closed to everybody else.
 */
export async function maintenanceState(signal?: AbortSignal): Promise<boolean> {
    const general = await fetchData<Record<string, unknown>>('/settings/general', {
        ...(signal ? { signal } : {}),
    });

    return general['maintenance_mode'] === true;
}
