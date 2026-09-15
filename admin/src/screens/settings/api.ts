import { fetchData, request } from '@/api/client';
import type {
    AdminAuthSocialLoginSetupResponses,
    AdminSettingsDefinitionsResponses,
    AdminSettingsHistoryResponses,
    AdminSettingsRollbackPreviewResponses,
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
export type RollbackPlan = AdminSettingsRollbackPreviewResponses[200]['data'];

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
    contentLocale?: string,
    signal?: AbortSignal,
): Promise<GroupState> {
    const result = await request<SettingRow[]>(`/admin/settings/${name}`, {
        // The content language, as a parameter of its own. Never `X-Locale`: that one
        // decides the language the platform answers in, and an operator reading the
        // console in Arabic must be able to read the English site name without the
        // labels around it turning English.
        ...(contentLocale === undefined ? {} : { query: { locale: contentLocale } }),
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
    contentLocale?: string,
): Promise<UpdateResult> {
    const result = await request<{ group: string }>(`/admin/settings/${name}`, {
        method: 'PUT',
        // The content language travels in the body, beside the values it applies to,
        // and not as the header that would also change the language of the answer.
        body: {
            settings: values,
            ...(contentLocale === undefined ? {} : { locale: contentLocale }),
        },
        ifMatch: version,
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

export type SocialLoginSetupState = AdminAuthSocialLoginSetupResponses[200]['data'];

/**
 * What social sign-in still needs, and the return addresses to register with Google.
 *
 * Assembled by the platform from configured values only. It never suggests an address:
 * no frontend domain is assumed, so the operator names every one of them.
 */
export async function socialLoginSetup(signal?: AbortSignal): Promise<SocialLoginSetupState> {
    return fetchData<SocialLoginSetupState>('/admin/auth/social-login/setup', {
        ...(signal ? { signal } : {}),
    });
}

/**
 * What a rollback would do, before it does any of it.
 *
 * A read: it writes nothing and carries no precondition. The plan names what would be
 * restored and what would be skipped, which is what makes restoring a group nobody
 * has inspected a considered act rather than a hopeful one.
 */
export async function rollbackPreview(
    name: string,
    revisionId: string,
    signal?: AbortSignal,
): Promise<RollbackPlan> {
    return fetchData<RollbackPlan>(`/admin/settings/${name}/rollback/preview`, {
        query: { revision_id: revisionId },
        ...(signal ? { signal } : {}),
    });
}

/**
 * Replace a stored credential.
 *
 * One operation, not a wizard. The platform verifies the candidate and commits only
 * if that verification permits — a failed verification is a 422 carrying the result,
 * and the stored credential is exactly what it was: not cleared, not replaced, not
 * partially applied (ADR 0038). Presenting this as separate verify and commit steps
 * would describe a sequence the backend does not have.
 */
export async function rotateSecret(
    name: string,
    key: string,
    credential: string,
    version: string,
): Promise<void> {
    await request(`/admin/settings/${name}/secrets/${key}/rotate`, {
        method: 'POST',
        body: { credential },
        ifMatch: version,
    });
}

// A media setting stores a media id. Its upload and preview go through the shared helpers
// in `@/screens/content/images`, the same ones content uses (ADR 0057 §4), and the group write
// that stages the id stays the ordinary atomic one under `If-Match`.
