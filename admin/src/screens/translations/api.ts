import { fetchData, request } from '@/api/client';
import type {
    AcceptReadyTranslationsRequest,
    AdminTranslationsBatchesAcceptReadyResponses,
    AdminTranslationsBatchesStoreResponses,
    AdminTranslationsIndexData,
    AdminTranslationsIndexResponses,
    AdminTranslationsOverviewResponses,
    TranslateContentRequest,
    WriteTranslationRequest,
} from '@/api/generated';
import type { StateTone } from '@/ui/state';

/**
 * The translation workshop, mapped operation for operation onto what the platform has.
 *
 * The workshop is queried, not downloaded (ADR 0048 §4): one target language, filtered
 * and searched on the server, a page at a time — so the browser never holds the
 * catalogue, however large it grows.
 *
 * The item is the unit throughout (ADR 0056). An item has one status in a language; it is
 * translated with AI as a whole, reviewed as a whole and accepted once. There is no call
 * here that names a field on its own, because the platform has none.
 */

export type Workshop = AdminTranslationsIndexResponses[200]['data'];
export type WorkshopQuery = NonNullable<AdminTranslationsIndexData['query']>;
export type TranslationLocale = Workshop['locales'][number];
export type TranslationSource = Workshop['sources'][number];
export type TranslationEntry = Workshop['entries'][number];
export type TranslationField = TranslationEntry['fields'][number];
export type TranslationBatch = NonNullable<TranslationEntry['batch']>;
export type ItemStatus = TranslationEntry['status'];
export type TranslationWrite = WriteTranslationRequest;
export type WorkshopState = NonNullable<WorkshopQuery['state']>;

export type Overview = AdminTranslationsOverviewResponses[200]['data'];
export type LanguageStanding = Overview['languages'][number];

export type TranslationRequest = TranslateContentRequest;
export type TranslationRequestOutcome = AdminTranslationsBatchesStoreResponses[200]['data'];
export type AcceptReadyOutcome = AdminTranslationsBatchesAcceptReadyResponses[200]['data'];

/** An item's statuses, in the order an operator works through them, and nothing else. */
export const ITEM_STATUSES: ItemStatus[] = [
    'not_translated',
    'incomplete',
    'pending',
    'ready',
    'failed',
    'translated',
];

/** The filters: every status, and all of them. */
export const WORKSHOP_STATES: WorkshopState[] = ['all', ...ITEM_STATUSES];

export const ITEM_STATUS_TONE: Record<ItemStatus, StateTone> = {
    not_translated: 'neutral',
    incomplete: 'warning',
    pending: 'pending',
    ready: 'info',
    translated: 'success',
    failed: 'danger',
};

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
 * translations by the states the platform stores, and whether AI is there at all.
 */
export async function overview(signal?: AbortSignal): Promise<Overview> {
    return fetchData<Overview>('/admin/translations/overview', { ...(signal ? { signal } : {}) });
}

/**
 * Translated items as a whole percentage, rounded down — so a language one item short of
 * finished never reads 100%. Null when there is nothing to count.
 */
export function coveragePercent(counts: { total: number; translated: number }): number | null {
    return counts.total === 0 ? null : Math.floor((counts.translated / counts.total) * 100);
}

/**
 * Write one item's fields in one language, typed by a person.
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

/**
 * Translate with AI: one item, one source, or everything missing in a language.
 *
 * Answers with how many items were started rather than with translations — a generation
 * takes seconds and runs on a queue, so the workshop follows the items' status.
 */
export async function translateWithAi(
    body: TranslationRequest,
): Promise<TranslationRequestOutcome> {
    return fetchData<TranslationRequestOutcome>('/admin/translations/batches', {
        method: 'POST',
        body,
    });
}

/**
 * Accept one item's translation, once. Only the fields the reviewer changed are sent; the
 * rest are written as they were generated, all of them together.
 */
export async function acceptTranslation(
    batchId: string,
    values: Record<string, string | null> = {},
): Promise<void> {
    await request(`/admin/translations/batches/${encodeURIComponent(batchId)}/accept`, {
        method: 'POST',
        body: Object.keys(values).length === 0 ? {} : { values },
    });
}

/** Accept every item ready for review in a language, item by item. */
export async function acceptAllReady(
    body: AcceptReadyTranslationsRequest,
): Promise<AcceptReadyOutcome> {
    return fetchData<AcceptReadyOutcome>('/admin/translations/batches/accept-ready', {
        method: 'POST',
        body,
    });
}

/** Discard an item's translation without writing anything. */
export async function dismissTranslation(batchId: string): Promise<void> {
    await request(`/admin/translations/batches/${encodeURIComponent(batchId)}`, {
        method: 'DELETE',
    });
}
