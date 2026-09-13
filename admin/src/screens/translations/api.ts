import { fetchData, request } from '@/api/client';
import type { AdminTranslationsIndexResponses, WriteTranslationRequest } from '@/api/generated';

/**
 * The translation workshop, mapped operation for operation onto what the platform has.
 *
 * Two endpoints, and the shape of the second is the interesting one: a write names an
 * item and a language and carries only the fields that changed. One item per request
 * is the API's decision rather than this client's — a translator works down a list,
 * and a failure should cost the entry they are on rather than a batch they did not
 * know they were sending.
 */

export type Workshop = AdminTranslationsIndexResponses[200]['data'];
export type TranslationLocale = Workshop['locales'][number];
export type TranslationSource = Workshop['sources'][number];
export type TranslationEntry = TranslationSource['entries'][number];
export type TranslationField = TranslationEntry['fields'][number];
export type TranslationWrite = WriteTranslationRequest;

export async function workshop(signal?: AbortSignal): Promise<Workshop> {
    return fetchData<Workshop>('/admin/translations', { ...(signal ? { signal } : {}) });
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
