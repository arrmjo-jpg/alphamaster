import { fetchData, request } from '@/api/client';
import type {
    AdminNotificationsAnnouncementsStoreResponses,
    AdminNotificationsTemplatesIndexResponses,
    NotificationsIndexResponses,
    NotificationsPreferencesIndexResponses,
    SendAnnouncementRequest,
    UpdateNotificationTemplateRequest,
} from '@/api/generated';

/**
 * Notifications, mapped operation for operation onto what the platform has.
 *
 * Four surfaces, and they are four different kinds of thing.
 *
 * A recipient's own preferences and their own in-app records are the caller's, about
 * the caller, behind no permission: both endpoints act on whoever is asking and there
 * is no way to reach anybody else's. Template wording is what every recipient reads,
 * so it is administrative. Sending an announcement is administrative and its own
 * permission again — correcting a typo in a template and writing to every account are
 * different powers.
 *
 * The inbox used to be absent from this file with a note explaining why: the platform
 * wrote an in-app record for every notification and published nothing that could read
 * one back. It publishes one now, so the note is gone and the reader is here.
 */

export type Preference = NotificationsPreferencesIndexResponses[200]['data'][number];
export type NotificationRecord = NotificationsIndexResponses[200]['data'][number];
export type Announcement = SendAnnouncementRequest;
export type AnnouncementOutcome = AdminNotificationsAnnouncementsStoreResponses[200]['data'];
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

/** The envelope's pagination block, from the contract rather than restated. */
type Pagination = NotificationsIndexResponses[200]['meta']['pagination'];

export interface InboxPage {
    rows: NotificationRecord[];
    pagination: Pagination | null;
    /**
     * How many are unread across the whole inbox, not across this page.
     *
     * Counted server-side for that reason: a badge answering "how many have I not
     * read" cannot be derived from twenty-five rows.
     */
    unread: number;
}

/**
 * The signed-in account's own records, newest first.
 *
 * Scoped server-side to whoever is asking. There is no recipient parameter here
 * because there is none there: an inbox is one account's, and no endpoint reads
 * another's.
 */
export async function inbox(
    query: { page?: number; unread?: boolean } = {},
    signal?: AbortSignal,
): Promise<InboxPage> {
    const result = await request<NotificationRecord[]>('/notifications', {
        query: {
            ...(query.page === undefined ? {} : { page: query.page }),
            ...(query.unread === true ? { unread: 1 } : {}),
        },
        ...(signal ? { signal } : {}),
    });

    const meta = result.meta as { pagination?: Pagination; unread?: number } | undefined;

    return {
        rows: result.data,
        pagination: meta?.pagination ?? null,
        unread: meta?.unread ?? 0,
    };
}

/**
 * Mark one record read.
 *
 * Idempotent at the endpoint: a record already read keeps the moment it was first
 * read rather than having it moved.
 */
export async function markRead(id: string): Promise<NotificationRecord> {
    return fetchData<NotificationRecord>(`/notifications/${id}/read`, { method: 'POST' });
}

/** Mark everything unread as read, and answer with how many that was. */
export async function markAllRead(): Promise<{ marked: number }> {
    return fetchData<{ marked: number }>('/notifications/read-all', { method: 'POST' });
}

/**
 * Raise an announcement.
 *
 * The audience is a closed set the platform defines, not a query composed here. The
 * count that comes back is how many recipients it was queued for — delivery is queued
 * per recipient and each one's own preferences decide how it arrives.
 */
export async function announce(body: Announcement): Promise<AnnouncementOutcome> {
    return fetchData<AnnouncementOutcome>('/admin/notifications/announcements', {
        method: 'POST',
        body,
    });
}
