import type { AdminMediaFile } from './api';

/**
 * Whether this console can show the bytes behind a record, and why not when it cannot.
 *
 * The administrative endpoints publish a record; they do not publish a way to read the
 * file. That comes from `GET /media/{id}/file`, which is gated by the media access
 * resolver rather than by `media.view` — and the resolver grants an administrator no
 * special reach into private media. A public, ready file is readable by anyone signed
 * in; an unattached private one is readable by whoever uploaded it; a private file
 * attached to something is decided by the owning module's policy, which this client
 * cannot evaluate and must not guess at.
 *
 * So the question is answered conservatively and the reason is carried, because a
 * broken image with no explanation is the worst of the available answers.
 */
export type PreviewVerdict =
    { readable: true } | { readable: false; reason: 'not-ready' | 'private' | 'not-visual' };

/** The media types this console renders inline. Everything else gets its record. */
const VISUAL = new Set(['image']);

export function previewVerdict(
    media: Pick<AdminMediaFile, 'status' | 'visibility' | 'type' | 'uploaded_by'>,
    viewerEmail: string | null,
): PreviewVerdict {
    // Order matters. An unready file is unready whatever its visibility, and saying
    // "you may not see this" about a file that is still being scanned would be both
    // wrong and alarming.
    if (media.status !== 'ready') {
        return { readable: false, reason: 'not-ready' };
    }

    if (media.visibility !== 'public') {
        // The one private case this client can settle without asking the platform: an
        // uploader may read their own. Matched on the address, because the address is
        // what the administrative record publishes — it carries `uploaded_by` as an
        // email rather than an id, so there is no identifier here to compare.
        const own =
            viewerEmail !== null && media.uploaded_by !== null && media.uploaded_by === viewerEmail;

        if (!own) {
            return { readable: false, reason: 'private' };
        }
    }

    if (!VISUAL.has(media.type)) {
        return { readable: false, reason: 'not-visual' };
    }

    return { readable: true };
}

/** A size a person reads, from the bytes the platform reports. */
export function formatBytes(bytes: number, locale: string): string {
    const units = ['B', 'KB', 'MB', 'GB'];
    let value = bytes;
    let unit = 0;

    while (value >= 1024 && unit < units.length - 1) {
        value /= 1024;
        unit += 1;
    }

    const formatted = new Intl.NumberFormat(locale, {
        maximumFractionDigits: unit === 0 ? 0 : 1,
    }).format(value);

    return `${formatted} ${units[unit] ?? 'B'}`;
}
