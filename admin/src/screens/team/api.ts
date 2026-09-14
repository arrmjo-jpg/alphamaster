import { fetchData, request } from '@/api/client';
import type {
    AdminTeamIndexResponses,
    StoreTeamMemberRequest,
    WriteTeamMemberTranslationRequest,
} from '@/api/generated';

/**
 * The team directory, mapped operation for operation onto what the platform has (ADR 0055).
 *
 * A member is one record with a profile per language; the language of a profile write is
 * always in its path.
 */

export type AdminTeamMember = AdminTeamIndexResponses[200]['data'][number];
export type TeamMemberChanges = StoreTeamMemberRequest;
export type ProfileWrite = WriteTeamMemberTranslationRequest;
export type ProfileSeoWrite = NonNullable<WriteTeamMemberTranslationRequest['seo']>;
export type SocialLinks = NonNullable<StoreTeamMemberRequest['social_links']>;
export type SocialNetwork = keyof SocialLinks;

/** The networks the platform accepts, in the order the API documents them. */
export const SOCIAL_NETWORKS: SocialNetwork[] = [
    'website',
    'x',
    'facebook',
    'instagram',
    'linkedin',
    'youtube',
    'tiktok',
    'github',
    'telegram',
];

export async function members(signal?: AbortSignal): Promise<AdminTeamMember[]> {
    return fetchData<AdminTeamMember[]>('/admin/team', { ...(signal ? { signal } : {}) });
}

export async function createMember(body: TeamMemberChanges): Promise<AdminTeamMember> {
    return fetchData<AdminTeamMember>('/admin/team', { method: 'POST', body });
}

/** Change what is the same in every language. Only the keys sent change. */
export async function updateMember(id: string, body: TeamMemberChanges): Promise<AdminTeamMember> {
    return fetchData<AdminTeamMember>(`/admin/team/${encodeURIComponent(id)}`, {
        method: 'PATCH',
        body,
    });
}

/** Write one language's profile and SEO. Only the fields sent change. */
export async function writeProfile(
    id: string,
    locale: string,
    body: ProfileWrite,
): Promise<AdminTeamMember> {
    return fetchData<AdminTeamMember>(
        `/admin/team/${encodeURIComponent(id)}/translations/${encodeURIComponent(locale)}`,
        { method: 'PUT', body },
    );
}

export async function deleteMember(id: string): Promise<void> {
    await request(`/admin/team/${encodeURIComponent(id)}`, { method: 'DELETE' });
}
