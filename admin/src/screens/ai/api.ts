import { fetchData, request } from '@/api/client';
import type {
    AdminAiCheckResponses,
    AdminAiShowResponses,
    AdminTranslationsSuggestionsIndexResponses,
    AiCheckRequest,
    RequestSuggestionsRequest,
    SaveAiProviderRequest,
} from '@/api/generated';

/**
 * AI, mapped operation for operation onto what the platform has.
 *
 * The control centre reads each provider's state, saves one provider's setup, tests a
 * setup before it is saved, and chooses which provider answers. The workshop asks for
 * suggestions and decides about them. Nothing here reads a key back: the platform
 * reports only whether one is stored.
 */

export type AiState = AdminAiShowResponses[200]['data'];
export type AiProvider = AiState['providers'][number];
export type AiCheck = AdminAiCheckResponses[200]['data'];
export type AiCheckBody = AiCheckRequest;
export type AiProviderSetup = SaveAiProviderRequest;
export type Suggestion = AdminTranslationsSuggestionsIndexResponses[200]['data'][number];
export type SuggestionRequest = RequestSuggestionsRequest;

export async function aiState(signal?: AbortSignal): Promise<AiState> {
    return fetchData<AiState>('/admin/ai', { ...(signal ? { signal } : {}) });
}

/**
 * Ask a provider a trivial question and report what happened.
 *
 * With no body, the default provider as saved. With a provider, key and model, the
 * setup form as it stands — the key is used for this one call and never stored. A real
 * call with a real cost; it answers 200 either way, because what it found is the
 * payload.
 */
export async function checkAi(body?: AiCheckBody): Promise<AiCheck> {
    return fetchData<AiCheck>('/admin/ai/check', {
        method: 'POST',
        ...(body === undefined ? {} : { body }),
    });
}

/** Save one provider's setup. Leaving `api_key` out keeps the stored key. */
export async function saveAiProvider(driver: string, body: AiProviderSetup): Promise<AiProvider> {
    return fetchData<AiProvider>(`/admin/ai/providers/${encodeURIComponent(driver)}`, {
        method: 'PUT',
        body,
    });
}

/** Remove a provider's key; the provider is disabled with it. */
export async function removeAiKey(driver: string): Promise<AiProvider> {
    return fetchData<AiProvider>(`/admin/ai/providers/${encodeURIComponent(driver)}/key`, {
        method: 'DELETE',
    });
}

/** Make a provider the one the platform's AI tasks use. */
export async function makeAiDefault(driver: string): Promise<AiProvider> {
    return fetchData<AiProvider>(`/admin/ai/providers/${encodeURIComponent(driver)}/default`, {
        method: 'POST',
    });
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
