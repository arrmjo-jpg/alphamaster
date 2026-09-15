import { fetchData } from '@/api/client';
import type {
    AdminAiCheckResponses,
    AdminAiShowResponses,
    AiCheckRequest,
    SaveAiProviderRequest,
} from '@/api/generated';

/**
 * AI, mapped operation for operation onto what the platform has.
 *
 * The control centre reads each provider's state, saves one provider's setup, tests a
 * setup before it is saved, and chooses which provider answers. Translating with AI is
 * the workshop's, in `screens/translations/api`. Nothing here reads a key back: the
 * platform reports only whether one is stored.
 */

export type AiState = AdminAiShowResponses[200]['data'];
export type AiProvider = AiState['providers'][number];
export type AiCheck = AdminAiCheckResponses[200]['data'];
export type AiCheckBody = AiCheckRequest;
export type AiProviderSetup = SaveAiProviderRequest;

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
