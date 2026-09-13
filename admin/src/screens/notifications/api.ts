import { fetchData } from '@/api/client';
import type {
    AdminNotificationsTemplatesIndexResponses,
    NotificationsPreferencesIndexResponses,
    UpdateNotificationTemplateRequest,
} from '@/api/generated';

/**
 * Notifications, mapped operation for operation onto what the platform has.
 *
 * There is no inbox below, and that absence is the point. The platform writes an
 * in-app record for every notification it raises — the `database` channel exists and
 * cannot be silenced — but it publishes no endpoint that reads those records back. A
 * notification centre listing what an operator has received would therefore be a
 * screen with nothing behind it, so this module is the two surfaces that do exist:
 * a recipient's own preferences, and the wording every recipient gets.
 *
 * The two are also not the same kind of thing, which is why they are not the same
 * screen. Preferences are the caller's own settings about their own messages and are
 * not administrative; template wording is administrative and behind
 * `notifications.view` / `notifications.update`.
 */

export type Preference = NotificationsPreferencesIndexResponses[200]['data'][number];
export type NotificationTemplate = AdminNotificationsTemplatesIndexResponses[200]['data'][number];
export type TemplateChanges = UpdateNotificationTemplateRequest;

/** One row of the update body, from the contract rather than restated. */
export type PreferenceChange = {
    type: Preference['type'];
    channel: Preference['channel'];
    enabled: boolean;
};

/**
 * Every effective decision, defaults included.
 *
 * The endpoint describes the whole matrix rather than only the rows a recipient has
 * overridden, so a client renders what it is given instead of inferring which
 * combinations exist — which it could not do without keeping its own copy of the type
 * and channel registries.
 */
export async function preferences(signal?: AbortSignal): Promise<Preference[]> {
    return fetchData<Preference[]>('/notifications/preferences', {
        ...(signal ? { signal } : {}),
    });
}

/**
 * Change preferences.
 *
 * The endpoint takes a list and answers with the whole matrix as it now stands, so the
 * response replaces what is on screen rather than this client predicting the result.
 * A combination the recipient may not silence is refused with
 * `PREFERENCE_NOT_SILENCEABLE` rather than quietly ignored.
 */
export async function updatePreferences(changes: PreferenceChange[]): Promise<Preference[]> {
    return fetchData<Preference[]>('/notifications/preferences', {
        method: 'PUT',
        body: { preferences: changes },
    });
}

export async function templates(signal?: AbortSignal): Promise<NotificationTemplate[]> {
    return fetchData<NotificationTemplate[]>('/admin/notifications/templates', {
        ...(signal ? { signal } : {}),
    });
}

/**
 * Change a template's wording or activation.
 *
 * Submitted translations are merged server-side rather than replacing the set, so
 * editing the English copy cannot silently delete the Arabic. The type is not sent and
 * cannot be: it identifies which notification a template renders, not how it reads.
 */
export async function updateTemplate(
    id: string,
    changes: TemplateChanges,
): Promise<NotificationTemplate> {
    return fetchData<NotificationTemplate>(`/admin/notifications/templates/${id}`, {
        method: 'PUT',
        body: changes,
    });
}
