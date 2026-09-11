import { fetchData, request } from '@/api/client';
import type {
    AdminTranslationsIndexData,
    AdminTranslationsIndexResponses,
    AdminTranslationsOverviewResponses,
    WriteTranslationRequest,
} from '@/api/generated';

/**
 * The translation workshop, mapped operation for operation onto what the platform has.
 *
 * The workshop is queried, not downloaded (ADR 0048 §4): one target language, filtered
 * and searched on the server, a page at a time — so the browser never holds the
 * catalogue, however large it grows.
 *
 * A write names an item and a language and carries only the fields that changed. One
 * item per request is the API's decision rather than this client's — a translator
 * works down a list, and a failure should cost the entry they are on rather than a
 * batch they did not know they were sending.
 */

export type Workshop = AdminTranslationsIndexResponses[200]['data'];
export type WorkshopQuery = NonNullable<AdminTranslationsIndexData['query']>;
export type TranslationLocale = Workshop['locales'][number];
export type TranslationSource = Workshop['sources'][number];
export type TranslationEntry = Workshop['entries'][number];
export type TranslationField = TranslationEntry['fields'][number];
export type TranslationWrite = WriteTranslationRequest;
export type WorkshopState = NonNullable<WorkshopQuery['state']>;

export type Overview = AdminTranslationsOverviewResponses[200]['data'];
export type LanguageStanding = Overview['languages'][number];

/** The states the platform stores and can filter on — nothing a filter would invent. */
export const WORKSHOP_STATES: WorkshopState[] = [
    'all',
    'missing',
    'needs_review',
    'translated',
    'failed',
];

export async function workshop(query: WorkshopQuery, signal?: AbortSignal): Promise<Workshop> {
    const params = new URLSearchParams();

    for (const [key, value] of Object.entries(query)) {
        if (value !== undefined && value !== null && value !== '') {
            params.set(key, String(value));
        }
    }

    const suffix = params.toString();

    return fetchData<Workshop>(`/admin/translations${suffix === '' ? '' : `?${suffix}`}`, {
        ...(signal ? { signal } : {}),
    });
}

/**
 * Where every language stands: coverage from the workshop's own calculation, AI
 * suggestions by the states the platform stores, and whether AI is there at all.
 */
export async function overview(signal?: AbortSignal): Promise<Overview> {
    return fetchData<Overview>('/admin/translations/overview', { ...(signal ? { signal } : {}) });
}

/**
 * Written fields as a whole percentage, rounded down — so a language one field short
 * of finished never reads 100%. Null when there is nothing to count.
 */
export function coveragePercent(counts: { total: number; translated: number }): number | null {
    return counts.total === 0 ? null : Math.floor((counts.translated / counts.total) * 100);
}

/**
 * Write one item's fields in one language.
 *
 * The source and the id are the platform's own, passed back unchanged: this client
 * never parses either, so a source is free to key by ulid, integer or `group.key`
 * without anything here having to know which.
 */
export async function writeTranslation(
    source: string,
    id: string,
    body: TranslationWrite,
): Promise<void> {
    await request(`/admin/translations/${encodeURIComponent(source)}/${encodeURIComponent(id)}`, {
        method: 'PUT',
        body,
    });
}
