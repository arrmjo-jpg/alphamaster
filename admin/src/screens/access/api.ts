import { fetchData, request } from '@/api/client';
import type {
    AdminPermissionsIndexResponses,
    AdminRolesIndexResponses,
    AdminUsersIndexResponses,
    StoreUserRequest,
    UpdateUserRequest,
} from '@/api/generated';

/**
 * Access management, mapped operation for operation onto what the platform has.
 *
 * There is no search parameter, no filter and no page here because there is none
 * there: `/admin/users` returns every account ordered by email. Filtering happens in
 * the browser and the interface says so, rather than presenting a search box that
 * looks like it asked the server a question.
 *
 * Standing, identity, activation and roles are four operations rather than one
 * update, because that is how the platform models them and each carries different
 * consequences: promotion revokes tokens and demands a second factor, deactivation
 * revokes tokens and refuses sign-in, a role change alters what an account may do,
 * and an identity edit alters none of those. A single `PATCH /users/{id}` taking all
 * of them would let one permission do the work of three.
 */

export type AdminUser = AdminUsersIndexResponses[200]['data'][number];
export type Role = AdminRolesIndexResponses[200]['data'][number];
export type PermissionCatalogue = AdminPermissionsIndexResponses[200]['data'];

export async function users(signal?: AbortSignal): Promise<AdminUser[]> {
    return fetchData<AdminUser[]>('/admin/users', { ...(signal ? { signal } : {}) });
}

export async function user(id: string, signal?: AbortSignal): Promise<AdminUser> {
    return fetchData<AdminUser>(`/admin/users/${id}`, { ...(signal ? { signal } : {}) });
}

/** The bodies the endpoints take. Never restated — these are the contract's own. */
export type NewUser = StoreUserRequest;
export type UserChanges = UpdateUserRequest;

/**
 * Create an account.
 *
 * A regular account, always: `account_type` is not a field this endpoint takes, and
 * promotion remains the only route across the administrative boundary. The address is
 * not verified, because nothing here can confirm somebody else's address.
 */
export async function createUser(body: NewUser): Promise<AdminUser> {
    return fetchData<AdminUser>('/admin/users', { method: 'POST', body });
}

/**
 * Change who an account is.
 *
 * Only the keys present are sent: every field is `sometimes`, so an absent one leaves
 * the stored value alone. Changing the address clears its verification server-side,
 * which is why the caller is told about it rather than left to notice.
 */
export async function updateUser(id: string, changes: UserChanges): Promise<AdminUser> {
    return fetchData<AdminUser>(`/admin/users/${id}`, { method: 'PUT', body: changes });
}

/**
 * Let an account sign in again, or stop it.
 *
 * Two operations rather than a toggle, because that is what the platform exposes: a
 * toggle decides from state the caller read a moment ago, and the moment worth being
 * sure about is the one where an account stops being able to sign in. Deactivating
 * revokes the account's tokens, and the platform refuses an administrator who tries it
 * on themselves.
 */
export async function setUserActive(id: string, active: boolean): Promise<AdminUser> {
    return fetchData<AdminUser>(`/admin/users/${id}/${active ? 'activate' : 'deactivate'}`, {
        method: 'POST',
    });
}

/**
 * Cross an account into administrative standing, or back out of it.
 *
 * The only sanctioned route across that boundary, and itself gated on
 * `users.update` — an administrator without it cannot create peers (ADR 0028).
 */
export async function promote(id: string): Promise<void> {
    await request(`/admin/users/${id}/promote`, { method: 'POST' });
}

export async function demote(id: string): Promise<void> {
    await request(`/admin/users/${id}/demote`, { method: 'POST' });
}

/**
 * Replace the roles an account holds.
 *
 * A full set rather than an add or a remove, because that is what the endpoint
 * takes: `roles` is `present` and the list it receives becomes the list the account
 * has. Sending a delta would mean this client deciding what the result should be.
 */
export async function syncRoles(id: string, roles: string[]): Promise<void> {
    await request(`/admin/users/${id}/roles`, { method: 'PUT', body: { roles } });
}

export async function roles(signal?: AbortSignal): Promise<Role[]> {
    return fetchData<Role[]>('/admin/roles', { ...(signal ? { signal } : {}) });
}

/**
 * Create or replace a role.
 *
 * `label` is what an administrator types; the machine identifier is derived from it
 * server-side and is immutable afterwards. The response calls that identifier `name`,
 * which is why this request deliberately does not.
 */
export async function saveRole(
    payload: { label: string; permissions: string[] },
    id?: number,
): Promise<Role> {
    // The saved role comes back, which is the only way a caller learns the identifier
    // derived from a new label — it is generated server-side and is not predictable
    // from the label, so a client that guessed it would select the wrong role.
    return fetchData<Role>(id === undefined ? '/admin/roles' : `/admin/roles/${id}`, {
        method: id === undefined ? 'POST' : 'PUT',
        body: payload,
    });
}

export async function deleteRole(id: number): Promise<void> {
    await request(`/admin/roles/${id}`, { method: 'DELETE' });
}

/**
 * Every permission the platform enforces, grouped by module as the API groups them.
 *
 * The grouping is the platform's, not this client's. Inventing a different one would
 * be a second taxonomy for the same set, and the two would disagree the first time a
 * permission moved.
 */
export async function permissions(signal?: AbortSignal): Promise<PermissionCatalogue> {
    return fetchData<PermissionCatalogue>('/admin/permissions', { ...(signal ? { signal } : {}) });
}
