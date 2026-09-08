import { fetchData, request } from '@/api/client';
import type {
    AdminSettingsDefinitionsResponses,
    AdminSettingsHistoryResponses,
    AdminSettingsShowResponses,
} from '@/api/generated';

/**
 * The settings endpoints, and the concurrency contract they are used under.
 *
 * Every write to a group carries `If-Match` with the version the read handed back
 * (ADR 0038). The platform refuses a write without one — 428 — rather than waving it
 * through, so there is no path here that can silently overwrite someone else's
 * change; the only question is whether this client noticed, and it does.
 */

export type SettingDefinition = AdminSettingsDefinitionsResponses[200]['data'][string][number];
export type SettingRow = AdminSettingsShowResponses[200]['data'][number];
export type HistoryRow = AdminSettingsHistoryResponses[200]['data'][number];

/** Every definition, keyed by group. This is the catalogue, not the values. */
export async function definitions(
    signal?: AbortSignal,
): Promise<Record<string, SettingDefinition[]>> {
    return fetchData<Record<string, SettingDefinition[]>>('/admin/settings/definitions', {
        ...(signal ? { signal } : {}),
    });
}

export interface GroupState {
    rows: SettingRow[];
    /** The precondition every write to this group must carry. */
    version: string;
}

/**
 * A group's current values, with the version that makes a write to it safe.
 *
 * The version arrives twice — as an ETag and in `meta` — and the meta copy is used:
 * both say the same thing, and one of them survives a proxy that strips headers.
 */
export async function group(
    name: string,
    locale: string,
    signal?: AbortSignal,
): Promise<GroupState> {
    const result = await request<SettingRow[]>(`/admin/settings/${name}`, {
        locale,
        ...(signal ? { signal } : {}),
    });

    const meta = result.meta as { version?: unknown } | undefined;
    const version = typeof meta?.version === 'string' ? meta.version : (result.etag ?? '');

    return { rows: result.data, version };
}

export interface UpdateResult {
    version: string;
}

/**
 * Write a batch of values, atomically.
 *
 * A batch that fails anywhere applies nowhere, which is why the editor submits every
 * changed field at once rather than a request per field: a partial apply would leave
 * a group in a state nobody chose.
 */
export async function updateGroup(
    name: string,
    values: Record<string, unknown>,
    version: string,
    locale: string,
): Promise<UpdateResult> {
    const result = await request<{ group: string }>(`/admin/settings/${name}`, {
        method: 'PUT',
        body: { settings: values },
        ifMatch: version,
        locale,
    });

    const meta = result.meta as { version?: unknown } | undefined;

    return { version: typeof meta?.version === 'string' ? meta.version : (result.etag ?? '') };
}

export async function history(
    name: string,
    limit: number,
    signal?: AbortSignal,
): Promise<HistoryRow[]> {
    return fetchData<HistoryRow[]>(`/admin/settings/${name}/history`, {
        query: { limit },
        ...(signal ? { signal } : {}),
    });
}

/**
 * Restore a group to the state a revision recorded (ADR 0040).
 *
 * Carries a precondition for the same reason a write does, and more so: a rollback
 * changes many values at once, from a state the operator may not have inspected, and
 * it is the operation most likely to be run from a page left open under pressure.
 */
export async function rollback(name: string, revisionId: string, version: string): Promise<void> {
    await request(`/admin/settings/${name}/rollback`, {
        method: 'POST',
        body: { revision_id: revisionId },
        ifMatch: version,
    });
}

export async function testMail(): Promise<void> {
    await request('/admin/settings/mail/test', { method: 'POST' });
}
