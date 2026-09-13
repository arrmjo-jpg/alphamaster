import { fetchData, request } from '@/api/client';
import type { AdminMediaIndexResponses, AdminMediaShowResponses } from '@/api/generated';

/**
 * The media library, mapped operation for operation onto what the platform has.
 *
 * The administrative endpoints are deliberately narrow — list, inspect, remove — and
 * that is the moderation surface. Uploading is not administrative: media is a platform
 * capability, so it goes through the same `POST /media` any signed-in account uses,
 * which is why the upload below carries no `admin` in its path.
 *
 * What is absent matters as much. There is no replace, no rename, no move between
 * collections, and no free-text search, because the API has none of them. The two
 * filters below are the two the endpoint takes.
 */

export type AdminMediaFile = AdminMediaIndexResponses[200]['data'][number];
export type AdminMediaDetail = AdminMediaShowResponses[200]['data'];

/** The pagination the platform reports alongside a page of rows. */
export interface Pagination {
    current_page: number;
    per_page: number;
    total: number;
    last_page: number;
    has_more_pages: boolean;
}

export interface MediaPage {
    rows: AdminMediaFile[];
    pagination: Pagination | null;
}

export interface MediaQuery {
    page: number;
    /** One of the platform's media statuses, or undefined for every status. */
    status?: string;
    /** One of the platform's media types, or undefined for every type. */
    type?: string;
}

/**
 * A page of media, newest first.
 *
 * `status` and `type` are the endpoint's own filters, so narrowing by either is a
 * question the server answers rather than a page this client fetches and sifts —
 * which matters, because the answer would otherwise be drawn from twenty-five rows.
 * The page size is the server's and is not negotiable: the endpoint takes no
 * `per_page`.
 */
export async function mediaPage(query: MediaQuery, signal?: AbortSignal): Promise<MediaPage> {
    const result = await request<AdminMediaFile[]>('/admin/media', {
        query: {
            page: query.page,
            ...(query.status === undefined ? {} : { status: query.status }),
            ...(query.type === undefined ? {} : { type: query.type }),
        },
        ...(signal ? { signal } : {}),
    });

    const meta = result.meta as { pagination?: Pagination } | undefined;

    return { rows: result.data, pagination: meta?.pagination ?? null };
}

export async function mediaFile(id: string, signal?: AbortSignal): Promise<AdminMediaDetail> {
    return fetchData<AdminMediaDetail>(`/admin/media/${id}`, { ...(signal ? { signal } : {}) });
}

/**
 * Remove a record.
 *
 * A soft delete: the row goes, and the bytes are purged later by the retention job. So
 * this is recoverable in the sense that the file has not yet been destroyed — and it is
 * not recoverable from here, because nothing in the API restores one.
 */
export async function deleteMedia(id: string): Promise<void> {
    await request(`/admin/media/${id}`, { method: 'DELETE' });
}

export interface Upload {
    file: File;
    /** Lower-case, starting with a letter; the platform's own rule. */
    collection: string;
    visibility: 'public' | 'private';
}

/**
 * Upload a file.
 *
 * Multipart, and the browser sets the content type itself so that the boundary is the
 * one it actually used. What the file *is* gets decided from its bytes server-side, so
 * nothing here inspects or claims a type.
 */
export async function uploadMedia(upload: Upload): Promise<void> {
    const body = new FormData();

    // The filename is passed explicitly rather than left to the File. A browser fills
    // it in either way; some fetch implementations send `blob`, and the platform reads
    // the multipart filename to record `original_filename` — which is the one field an
    // operator identifies a file by.
    body.append('file', upload.file, upload.file.name);
    body.append('collection', upload.collection);
    body.append('visibility', upload.visibility);

    await request('/media', { method: 'POST', formData: body });
}

/**
 * Where the bytes are, for a viewer entitled to them.
 *
 * Relative and same-origin, so the session cookie travels with the request the browser
 * makes for an `<img>` exactly as it does for a fetch (ADR 0042). There is no signed
 * URL and no token in this path: the route is behind the same authentication as
 * everything else.
 */
export function fileUrl(id: string): string {
    return `/api/v1/media/${id}/file`;
}
