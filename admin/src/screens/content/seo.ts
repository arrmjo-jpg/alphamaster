import { textOrNull } from '@/screens/content/text';

/**
 * One language's search and sharing metadata, as every content editor handles it (ADR 0032).
 *
 * The platform stores SEO in one table for any owner and validates it with one set of rules, so
 * the console has one draft, one comparison and one write body for it too. A screen that edits
 * content with SEO uses these and `SeoFieldsEditor` rather than keeping a copy of its own.
 */

/** The robots values the platform accepts, from `SeoFields::ROBOTS`. */
export const ROBOTS = [
    'index,follow',
    'noindex,follow',
    'index,nofollow',
    'noindex,nofollow',
] as const;

export type RobotsValue = (typeof ROBOTS)[number];

/** The platform's own bounds, from `SeoFields`. */
export const SEO_LIMITS = { title: 255, description: 1000, canonical: 2048 } as const;

/** What the platform returns for one language. */
export interface StoredSeo {
    title: string | null;
    description: string | null;
    robots: string | null;
    canonical_url: string | null;
    og_title: string | null;
    og_description: string | null;
    og_media_id: string | null;
}

/** What the editor holds while someone types. */
export interface SeoDraft {
    title: string;
    description: string;
    /** Empty means the site's policy. */
    robots: string;
    canonical_url: string;
    og_title: string;
    og_description: string;
    og_media_id: string | null;
}

export interface SeoWrite {
    title: string | null;
    description: string | null;
    robots: RobotsValue | null;
    canonical_url: string | null;
    og_title: string | null;
    og_description: string | null;
    og_media_id: string | null;
}

/** The draft for what is stored — and empty fields, never another language's, where nothing is. */
export function seoDraftFor(stored: StoredSeo | undefined): SeoDraft {
    return {
        title: stored?.title ?? '',
        description: stored?.description ?? '',
        robots: stored?.robots ?? '',
        canonical_url: stored?.canonical_url ?? '',
        og_title: stored?.og_title ?? '',
        og_description: stored?.og_description ?? '',
        og_media_id: stored?.og_media_id ?? null,
    };
}

export function seoChanged(draft: SeoDraft, base: SeoDraft): boolean {
    return (Object.keys(draft) as (keyof SeoDraft)[]).some((key) => draft[key] !== base[key]);
}

function isRobots(value: string): value is RobotsValue {
    return (ROBOTS as readonly string[]).includes(value);
}

/**
 * The body the platform expects. A language's SEO is replaced as a whole, so every field is
 * sent, and an empty one clears what was stored.
 */
export function seoWrite(draft: SeoDraft): SeoWrite {
    return {
        title: textOrNull(draft.title),
        description: textOrNull(draft.description),
        robots: isRobots(draft.robots) ? draft.robots : null,
        canonical_url: textOrNull(draft.canonical_url),
        og_title: textOrNull(draft.og_title),
        og_description: textOrNull(draft.og_description),
        og_media_id: draft.og_media_id,
    };
}
