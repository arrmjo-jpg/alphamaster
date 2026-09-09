import { fetchData, request } from '@/api/client';
import type {
    AdminAuditArchiveResponses,
    AdminAuditIndexResponses,
    AdminConfigurationExportResponses,
    AdminConfigurationRestoreResponses,
} from '@/api/generated';

/**
 * The operational surfaces Core publishes, mapped operation for operation.
 *
 * Three things live here and nothing else does. The administrative trail, which is
 * read and archived under separate permissions. And moving configuration in and out of
 * the deployment, which is one permission for both directions and deliberately not
 * `settings.update`.
 *
 * Configuration *history* is not here and must not be: the settings workspace already
 * owns per-group history and rollback (ADR 0040), and a second history built on the
 * audit trail would be a second answer to the same question that disagreed with the
 * first the moment either changed.
 */

export type AuditRecord = AdminAuditIndexResponses[200]['data'][number];
export type ArchiveOutcome = AdminAuditArchiveResponses[200]['data'];
export type ExportOutcome = AdminConfigurationExportResponses[200]['data'];
export type RestoreOutcome = AdminConfigurationRestoreResponses[200]['data'];

export interface Pagination {
    current_page: number;
    per_page: number;
    total: number;
    last_page: number;
    has_more_pages: boolean;
}

export interface AuditPage {
    records: AuditRecord[];
    pagination: Pagination | null;
}

/**
 * The filters the endpoint takes, and only those.
 *
 * Every one is an equality or a bound on an indexed column. There is deliberately no
 * free-text search across `context`: it would be a scan over the one column whose
 * contents vary per action, and the questions an operator actually asks — what did this
 * person do, what happened to this setting, what happened that day — are the ones the
 * indexes were built for.
 */
export interface AuditFilters {
    page: number;
    per_page: number;
    action?: string;
    subject?: string;
    actor_id?: string;
    outcome?: string;
    /** ISO 8601; the endpoint compares against `created_at`. */
    from?: string;
    to?: string;
}

export async function auditPage(filters: AuditFilters, signal?: AbortSignal): Promise<AuditPage> {
    const result = await request<AuditRecord[]>('/admin/audit', {
        query: {
            page: filters.page,
            per_page: filters.per_page,
            ...(filters.action === undefined ? {} : { action: filters.action }),
            ...(filters.subject === undefined ? {} : { subject: filters.subject }),
            ...(filters.actor_id === undefined ? {} : { actor_id: filters.actor_id }),
            ...(filters.outcome === undefined ? {} : { outcome: filters.outcome }),
            ...(filters.from === undefined ? {} : { from: filters.from }),
            ...(filters.to === undefined ? {} : { to: filters.to }),
        },
        ...(signal ? { signal } : {}),
    });

    const meta = result.meta as { pagination?: Pagination } | undefined;

    return { records: result.data, pagination: meta?.pagination ?? null };
}

/**
 * Move records past the retention window out of the active trail.
 *
 * Triggered by a person, never scheduled — a silent periodic cleanup is
 * indistinguishable, from outside, from evidence disappearing. The window is the
 * platform's `operations.audit_retention_days` and is not a parameter here, because it
 * is not one there.
 *
 * Export, then verification, then removal. A failure to verify removes nothing and
 * comes back as a 500 rather than as an archive of zero records.
 */
export async function archiveAudit(): Promise<ArchiveOutcome> {
    return fetchData<ArchiveOutcome>('/admin/audit/archive', { method: 'POST' });
}

/**
 * Write a configuration export to the configured disk.
 *
 * The response says where it went and what it left out. It does not contain the export:
 * the artefact on the disk is the artefact, and a response body reproducing it would be
 * a second copy travelling by a route with different access controls — which is also
 * why this console offers no download.
 */
export async function exportConfiguration(includeSecrets: boolean): Promise<ExportOutcome> {
    return fetchData<ExportOutcome>('/admin/configuration/export', {
        method: 'POST',
        body: { include_secrets: includeSecrets },
    });
}

/**
 * Read a configuration export back over what is running.
 *
 * Takes a location on the configured disk rather than an upload, because that is what
 * the endpoint takes: the disk is where the deployment's own access controls apply.
 */
export async function restoreConfiguration(location: string): Promise<RestoreOutcome> {
    return fetchData<RestoreOutcome>('/admin/configuration/restore', {
        method: 'POST',
        body: { location },
    });
}
