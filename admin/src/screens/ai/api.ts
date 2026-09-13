import { fetchData, request } from '@/api/client';
import type {
    AdminAiCheckResponses,
    AdminAiShowResponses,
    AdminTranslationsSuggestionsIndexResponses,
    RequestSuggestionsRequest,
} from '@/api/generated';

/**
 * AI, mapped operation for operation onto what the platform has.
 *
 * Four endpoints across two screens, and the split matters. The control centre reads
 * state and runs a check; the workshop asks for suggestions and decides about them.
 * Nothing here applies a suggestion — accepting does, and accepting is a write against
 * the content's own permission, which is why it goes through the same call the
 * workshop's save does.
 */

export type AiState = AdminAiShowResponses[200]['data'];
export type AiCheck = AdminAiCheckResponses[200]['data'];
export type Suggestion = AdminTranslationsSuggestionsIndexResponses[200]['data'][number];
export type SuggestionRequest = RequestSuggestionsRequest;

export async function aiState(signal?: AbortSignal): Promise<AiState> {
    return fetchData<AiState>('/admin/ai', { ...(signal ? { signal } : {}) });
}

/**
 * Ask the vendor a trivial question and report what happened.
 *
 * A real call with a real cost. It answers 200 whether or not the vendor did, because
 * the check ran either way — what it found is the payload.
 */
export async function checkAi(): Promise<AiCheck> {
    return fetchData<AiCheck>('/admin/ai/check', { method: 'POST' });
}

/**
 * Ask for translations of what is missing in a language.
 *
 * Answers with what was queued rather than with translations: a generation takes
 * seconds and runs on a queue, so the client polls rather than waits.
 */
export async function requestSuggestions(
    body: SuggestionRequest,
): Promise<{ queued: number; skipped: number }> {
    return fetchData<{ queued: number; skipped: number }>('/admin/translations/suggestions', {
        method: 'POST',
        body,
    });
}

export async function suggestions(locale: string, signal?: AbortSignal): Promise<Suggestion[]> {
    return fetchData<Suggestion[]>(
        `/admin/translations/suggestions?locale=${encodeURIComponent(locale)}`,
        { ...(signal ? { signal } : {}) },
    );
}

/**
 * Write a suggestion through, as the person accepting it wants it.
 *
 * The text is sent rather than referenced, because the whole point is that a person
 * may have edited it before deciding.
 */
export async function acceptSuggestion(id: string, text: string): Promise<void> {
    await request(`/admin/translations/suggestions/${encodeURIComponent(id)}/accept`, {
        method: 'POST',
        body: { text },
    });
}

export async function dismissSuggestion(id: string): Promise<void> {
    await request(`/admin/translations/suggestions/${encodeURIComponent(id)}`, {
        method: 'DELETE',
    });
}
