import { fetchData } from '@/api/client';
import type {
    AdminIntegrationsProvidersIndexResponses,
    AdminIntegrationsUsageResponses,
    UpdateIntegrationProviderRequest,
} from '@/api/generated';

/**
 * Vendor configuration, mapped operation for operation onto what the platform has.
 *
 * Four endpoints and no more. There is no create and no delete because there is
 * neither: a provider row is shipped by the seeder for a capability the platform can
 * actually consume, and an interface offering to add a fifth would be offering to
 * write a row no driver exists for.
 *
 * Every type below is taken from the generated contract rather than restated, so a
 * renamed field breaks the typecheck here rather than rendering as `undefined` in a
 * panel nobody is watching closely.
 */

export type IntegrationProvider = AdminIntegrationsProvidersIndexResponses[200]['data'][number];
export type IntegrationUsage = AdminIntegrationsUsageResponses[200]['data'][number];

/** The body the update endpoint takes. Never restated — this is the contract's own. */
export type ProviderChanges = UpdateIntegrationProviderRequest;

export async function integrationProviders(signal?: AbortSignal): Promise<IntegrationProvider[]> {
    return fetchData<IntegrationProvider[]>('/admin/integrations/providers', {
        ...(signal ? { signal } : {}),
    });
}

/**
 * The most recent attempts against every provider.
 *
 * The endpoint takes no parameters and answers with the last hundred rows across all
 * capabilities. That is the whole window this console has, so anything narrower —
 * per provider, per status, per period — is filtered here and said to be.
 */
export async function integrationUsage(signal?: AbortSignal): Promise<IntegrationUsage[]> {
    return fetchData<IntegrationUsage[]>('/admin/integrations/usage', {
        ...(signal ? { signal } : {}),
    });
}

/**
 * Change a provider.
 *
 * Only the keys present are sent, and that is the contract rather than an
 * optimisation: every field is `sometimes`, so an absent key leaves the stored value
 * alone. It matters most for `credentials` — omitting it keeps the stored secret,
 * sending a map replaces it wholesale, and sending null clears it. A form that always
 * submitted every field would overwrite a credential on an edit to a label.
 */
export async function updateProvider(
    id: string,
    changes: ProviderChanges,
): Promise<IntegrationProvider> {
    return fetchData<IntegrationProvider>(`/admin/integrations/providers/${id}`, {
        method: 'PUT',
        body: changes,
    });
}

/**
 * Make this provider the one its capability uses.
 *
 * Refused with `PROVIDER_INACTIVE` for a provider that is switched off, because the
 * platform will not point a capability at something it has been told not to call.
 */
export async function makeDefault(id: string): Promise<IntegrationProvider> {
    return fetchData<IntegrationProvider>(`/admin/integrations/providers/${id}/default`, {
        method: 'POST',
    });
}

/**
 * The attempts belonging to one provider.
 *
 * Matched on capability and driver, because that is all a usage row carries: it
 * denormalises both so a line stays readable after the provider row it referred to is
 * gone, and it does not record the provider's id. Two rows for the same capability
 * and driver would therefore be indistinguishable here — the platform does not allow
 * a second, and this is where that would show if it ever did.
 */
export function usageFor(
    provider: IntegrationProvider,
    usage: readonly IntegrationUsage[],
): IntegrationUsage[] {
    return usage.filter(
        (entry) => entry.capability === provider.capability && entry.driver === provider.driver,
    );
}
