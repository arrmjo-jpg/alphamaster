import { fetchData, request } from '@/api/client';
import type { MediaShowResponses } from '@/api/generated';

/**
 * Images content refers to, through the platform's one media capability (ADR 0024, ADR 0057).
 *
 * There is no content upload endpoint and none is added: a picture for a team member, a sharing
 * image for a page and anything a later module needs all go through `POST /media` as a public
 * image, and the content keeps the id. The platform then accepts the id only for a public image
 * that is ready to serve (ADR 0055 §10), so this module waits for readiness before handing an id
 * to an editor rather than letting the save find out.
 */

export type MediaRecord = MediaShowResponses[200]['data'];

/** The statuses from which a file never becomes ready. */
const FAILED = new Set(['scan_failed', 'processing_failed']);

export async function uploadPublicImage(file: File, collection: string): Promise<MediaRecord> {
    const body = new FormData();

    body.append('file', file, file.name);
    body.append('collection', collection);
    body.append('visibility', 'public');

    return fetchData<MediaRecord>('/media', { method: 'POST', formData: body });
}

export async function mediaRecord(id: string, signal?: AbortSignal): Promise<MediaRecord> {
    return fetchData<MediaRecord>(`/media/${encodeURIComponent(id)}`, {
        ...(signal ? { signal } : {}),
    });
}

export type Readiness = 'ready' | 'failed' | 'pending';

export function readinessOf(record: Pick<MediaRecord, 'status' | 'scan_status'>): Readiness {
    if (FAILED.has(record.status) || record.scan_status === 'infected') {
        return 'failed';
    }

    return record.status === 'ready' ? 'ready' : 'pending';
}

/**
 * Ask until the file is ready, has failed, or the attempts run out.
 *
 * The uploader may read their own file before it is ready, which is what makes asking possible.
 * Running out is not a failure: the file may still become ready, and the library offers it then.
 */
export async function waitForImage(
    first: MediaRecord,
    { intervalMs, attempts }: { intervalMs: number; attempts: number },
): Promise<{ readiness: Readiness; record: MediaRecord }> {
    let record = first;

    for (let attempt = 0; attempt < attempts; attempt += 1) {
        const readiness = readinessOf(record);

        if (readiness !== 'pending') {
            return { readiness, record };
        }

        await new Promise((resolve) => setTimeout(resolve, intervalMs));
        record = await mediaRecord(record.id);
    }

    return { readiness: readinessOf(record), record };
}

export interface LibraryImage {
    id: string;
    url: string | null;
    original_filename: string;
    visibility: string;
}

/**
 * The first page of the library's ready images, narrowed to the public ones content may use.
 *
 * The narrowing by visibility is here because the endpoint filters by type and status only; a
 * private image would be refused by the save, so it is not offered.
 */
export async function libraryImages(signal?: AbortSignal): Promise<LibraryImage[]> {
    const result = await request<LibraryImage[]>('/admin/media', {
        query: { type: 'image', status: 'ready' },
        ...(signal ? { signal } : {}),
    });

    return result.data.filter((image) => image.visibility === 'public');
}
