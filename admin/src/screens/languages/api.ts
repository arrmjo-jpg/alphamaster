import { fetchData } from '@/api/client';
import type {
    AdminLanguagesIndexResponses,
    LanguageDirection,
    StoreLanguageRequest,
    UpdateLanguageRequest,
} from '@/api/generated';

/**
 * The languages the platform knows about, mapped operation for operation.
 *
 * Six endpoints and no more. There is deliberately no delete below because the API has
 * none: a language that has been used has translations and settings pointing at it, and
 * the platform's answer to "stop using this one" is to deactivate it. An interface
 * offering to remove one would be offering something the server would refuse to do.
 *
 * These routes sit behind the administrative perimeter and behind no permission of
 * their own — the catalogue has no `languages.*` entry — so any administrator who can
 * reach the Admin can reach these. That is the platform's decision, not this screen's,
 * and the screen states it rather than inventing a gate the API does not enforce.
 */

export type AdminLanguage = AdminLanguagesIndexResponses[200]['data'][number];
export type { LanguageDirection };

/** The bodies the endpoints take. Never restated — these are the contract's own. */
export type NewLanguage = StoreLanguageRequest;
export type LanguageChanges = UpdateLanguageRequest;

export async function languages(signal?: AbortSignal): Promise<AdminLanguage[]> {
    return fetchData<AdminLanguage[]>('/admin/languages', { ...(signal ? { signal } : {}) });
}

export async function createLanguage(body: NewLanguage): Promise<AdminLanguage> {
    return fetchData<AdminLanguage>('/admin/languages', { method: 'POST', body });
}

/**
 * Change a language.
 *
 * Only the keys present are sent: every field is `sometimes`, so an absent one leaves
 * the stored value alone.
 */
export async function updateLanguage(id: string, changes: LanguageChanges): Promise<AdminLanguage> {
    return fetchData<AdminLanguage>(`/admin/languages/${id}`, { method: 'PUT', body: changes });
}

/**
 * Flip a language between active and inactive.
 *
 * A toggle rather than a value, because that is the endpoint: it takes no body and
 * returns the language in whatever state it now has. Deactivating the default is
 * refused with `CANNOT_DEACTIVATE_DEFAULT_LANGUAGE`.
 */
export async function toggleLanguage(id: string): Promise<AdminLanguage> {
    return fetchData<AdminLanguage>(`/admin/languages/${id}/status`, { method: 'PATCH' });
}

/**
 * Make this language the platform's default.
 *
 * Activating it is part of the same operation server-side: the platform will not leave
 * a default that is switched off, so this can be called on an inactive language and the
 * language comes back active.
 */
export async function makeDefaultLanguage(id: string): Promise<AdminLanguage> {
    return fetchData<AdminLanguage>(`/admin/languages/${id}/default`, { method: 'PATCH' });
}
