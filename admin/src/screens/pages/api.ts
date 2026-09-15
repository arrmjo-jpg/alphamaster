import { fetchData, request } from '@/api/client';
import type {
    AdminPagesIndexResponses,
    StorePageRequest,
    WritePageTranslationRequest,
} from '@/api/generated';

/**
 * Static pages, mapped operation for operation onto what the platform has (ADR 0055).
 *
 * A page is one record. Its text is written one language at a time, and the language is
 * always in the path of the write — never the console's own language.
 */

export type AdminPage = AdminPagesIndexResponses[200]['data'][number];
export type PageTranslationWrite = WritePageTranslationRequest;
export type PageSeoWrite = NonNullable<WritePageTranslationRequest['seo']>;

export async function pages(signal?: AbortSignal): Promise<AdminPage[]> {
    return fetchData<AdminPage[]>('/admin/pages', { ...(signal ? { signal } : {}) });
}

export async function createPage(body: StorePageRequest): Promise<AdminPage> {
    return fetchData<AdminPage>('/admin/pages', { method: 'POST', body });
}

/** Write one language's text, address and SEO. Only the fields sent change. */
export async function writePageTranslation(
    id: string,
    locale: string,
    body: PageTranslationWrite,
): Promise<AdminPage> {
    return fetchData<AdminPage>(
        `/admin/pages/${encodeURIComponent(id)}/translations/${encodeURIComponent(locale)}`,
        { method: 'PUT', body },
    );
}

export async function publishPage(id: string): Promise<AdminPage> {
    return fetchData<AdminPage>(`/admin/pages/${encodeURIComponent(id)}/publish`, {
        method: 'POST',
        body: {},
    });
}

export async function unpublishPage(id: string): Promise<AdminPage> {
    return fetchData<AdminPage>(`/admin/pages/${encodeURIComponent(id)}/unpublish`, {
        method: 'POST',
    });
}

export async function archivePage(id: string): Promise<AdminPage> {
    return fetchData<AdminPage>(`/admin/pages/${encodeURIComponent(id)}/archive`, {
        method: 'POST',
    });
}

export async function deletePage(id: string): Promise<void> {
    await request(`/admin/pages/${encodeURIComponent(id)}`, { method: 'DELETE' });
}
