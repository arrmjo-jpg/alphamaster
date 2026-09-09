import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { HttpResponse, http } from 'msw';
import { MemoryRouter } from 'react-router';
import { describe, expect, it } from 'vitest';

import { AppProviders } from '@/app/AppProviders';
import { AuthGate } from '@/auth/AuthGate';
import { MediaScreen } from '@/screens/MediaScreen';
import { previewVerdict } from '@/screens/media/preview';
import { observed, server } from '@/test/server';

import '@/i18n';

/**
 * The media library, against the endpoints the platform actually has.
 *
 * Two properties are load-bearing. Filtering and paging are the server's, and the
 * requests must prove it — a filter applied to the twenty-five rows on screen would
 * look identical and be wrong. And the bytes are gated by the media access policy
 * rather than by `media.view`, so an administrator has no reach into private media
 * they did not upload: the library must say so rather than render a broken image.
 */

const VIEWER_EMAIL = 'nadia@example.test';

const LANGUAGES = http.get('*/api/v1/languages', () =>
    HttpResponse.json({
        success: true,
        data: [
            {
                code: 'en',
                name: 'English',
                native_name: 'English',
                direction: 'ltr',
                is_active: true,
                is_default: true,
                sort_order: 0,
                created_at: null,
                updated_at: null,
            },
        ],
    }),
);

const HEALTH = http.get('*/api/v1/health', () =>
    HttpResponse.json({
        success: true,
        data: {
            status: 'healthy',
            timestamp: '2026-09-08T10:00:00+00:00',
            framework: 'Laravel 13',
        },
    }),
);

function file(over: Record<string, unknown> = {}) {
    return {
        id: 'med-1',
        collection: 'default',
        original_filename: 'kingdom.png',
        mime_type: 'image/png',
        type: 'image',
        type_label: 'Image',
        size_bytes: 24_576,
        checksum: 'abc123',
        visibility: 'public',
        visibility_label: 'Public',
        status: 'ready',
        status_label: 'Ready',
        scan_status: 'not_scanned',
        scan_status_label: 'Not scanned',
        failure_reason: null,
        attachable_type: null,
        attachable_id: null,
        uploaded_by: VIEWER_EMAIL,
        created_at: '2026-09-08T09:00:00+00:00',
        ...over,
    };
}

function pagination(over: Record<string, unknown> = {}) {
    return {
        current_page: 1,
        per_page: 25,
        total: 1,
        last_page: 1,
        has_more_pages: false,
        ...over,
    };
}

function renderScreen(
    permissions: string[],
    rows: ReturnType<typeof file>[] = [file()],
    meta = pagination(),
) {
    server.use(
        LANGUAGES,
        HEALTH,
        http.get('*/api/v1/admin/media', () =>
            HttpResponse.json({ success: true, data: rows, meta: { pagination: meta } }),
        ),
        http.get('*/api/v1/admin/media/:id', ({ params }) =>
            HttpResponse.json({
                success: true,
                data: rows.find((row) => row.id === params['id']) ?? rows[0],
            }),
        ),
        http.get('*/api/v1/auth/me', () =>
            HttpResponse.json({
                success: true,
                data: {
                    id: '01hzzadmin',
                    name: 'Nadia Haddad',
                    email: VIEWER_EMAIL,
                    account_type: 'admin',
                    is_active: true,
                    email_verified: true,
                    email_verified_at: '2026-01-01T00:00:00+00:00',
                    abilities: ['admin:access'],
                    roles: ['administrator'],
                    permissions,
                },
            }),
        ),
    );

    return render(
        <MemoryRouter>
            <AppProviders>
                <AuthGate>
                    <MediaScreen />
                </AuthGate>
            </AppProviders>
        </MemoryRouter>,
    );
}

/** Every media list request the suite has seen, newest last. */
async function listRequests(): Promise<URL[]> {
    for (let attempt = 0; attempt < 50; attempt += 1) {
        const urls = observed
            .filter((request) => request.method === 'GET')
            .map((request) => new URL(request.url))
            .filter((url) => url.pathname.endsWith('/admin/media'));

        if (urls.length > 0) {
            return urls;
        }

        await new Promise((resolve) => setTimeout(resolve, 10));
    }

    throw new Error('The media list was never requested.');
}

describe('the media library', () => {
    it('asks the server to filter, rather than sifting the page it already has', async () => {
        renderScreen(
            ['media.view'],
            [file(), file({ id: 'med-2', type: 'document', original_filename: 'charter.pdf' })],
        );

        await screen.findByText('kingdom.png');

        await userEvent.selectOptions(screen.getByLabelText('Type'), 'document');

        const urls = await listRequests();
        const last = urls.at(-1);

        expect(last?.searchParams.get('type')).toBe('document');
        // And back to the first page: page four of a filter nobody had applied is
        // nobody's question.
        expect(last?.searchParams.get('page')).toBe('1');
    });

    it('has no search box, because the endpoint takes no term', async () => {
        renderScreen(['media.view']);

        await screen.findByText('kingdom.png');

        expect(screen.queryByRole('searchbox')).not.toBeInTheDocument();
        expect(screen.queryByPlaceholderText(/search/i)).not.toBeInTheDocument();
    });

    it('pages through the server, and says where it is', async () => {
        renderScreen(
            ['media.view'],
            [file()],
            pagination({ total: 60, last_page: 3, has_more_pages: true }),
        );

        expect(await screen.findByText('Page 1 of 3')).toBeInTheDocument();

        await userEvent.click(screen.getByRole('button', { name: /Next/ }));

        const urls = await listRequests();

        expect(urls.at(-1)?.searchParams.get('page')).toBe('2');
    });

    it('says why a private file cannot be shown instead of rendering a broken image', async () => {
        renderScreen(
            ['media.view'],
            [
                file({
                    id: 'med-private',
                    original_filename: 'contract.png',
                    visibility: 'private',
                    visibility_label: 'Private',
                    uploaded_by: 'someone.else@example.test',
                }),
            ],
        );

        await screen.findByText('contract.png');

        expect(screen.getAllByText('Private to its uploader').length).toBeGreaterThan(0);
        expect(screen.queryByRole('img')).not.toBeInTheDocument();
    });

    it('confirms a removal, and says what it does and does not undo', async () => {
        server.use(
            http.delete('*/api/v1/admin/media/:id', () =>
                HttpResponse.json({ success: true, data: null }),
            ),
        );

        renderScreen(['media.view', 'media.delete']);

        await userEvent.click(await screen.findByText('kingdom.png'));
        await userEvent.click(await screen.findByRole('button', { name: 'Remove this file' }));

        expect(screen.getByText(/purged later by the retention job/)).toBeInTheDocument();

        // The confirmation must not appear where the trigger was. Browser testing of an
        // earlier draft deleted files by a second click landing on a confirm button
        // that had rendered under the pointer, so the trigger stays put and disabled
        // and the reversible control comes first.
        const trigger = screen.getByRole('button', { name: 'Remove this file' });
        const confirm = screen.getByRole('button', { name: 'Remove it' });
        const cancel = screen.getByRole('button', { name: 'Cancel' });

        expect(trigger).toBeDisabled();
        expect(trigger.compareDocumentPosition(cancel) & Node.DOCUMENT_POSITION_FOLLOWING).toBe(
            Node.DOCUMENT_POSITION_FOLLOWING,
        );
        expect(cancel.compareDocumentPosition(confirm) & Node.DOCUMENT_POSITION_FOLLOWING).toBe(
            Node.DOCUMENT_POSITION_FOLLOWING,
        );

        await userEvent.click(confirm);

        const deleted = observed.findLast((request) => request.method === 'DELETE');

        expect(deleted).toBeDefined();
    });

    it('shows a reader the removal as absent rather than as refused', async () => {
        renderScreen(['media.view']);

        await userEvent.click(await screen.findByText('kingdom.png'));

        expect(await screen.findByText(/may read this file but not remove it/)).toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'Remove this file' })).not.toBeInTheDocument();
    });

    it('uploads through the platform endpoint, as multipart, with what the operator chose', async () => {
        server.use(
            http.post('*/api/v1/media', () =>
                HttpResponse.json({ success: true, data: file() }, { status: 201 }),
            ),
        );

        renderScreen(['media.view']);

        await userEvent.click(await screen.findByRole('button', { name: 'Upload a file' }));

        const panel = screen.getByRole('complementary', { name: 'Media detail' });

        await userEvent.upload(
            within(panel).getByLabelText(/File/),
            new File(['bytes'], 'seal.png', { type: 'image/png' }),
        );

        await userEvent.click(within(panel).getByRole('button', { name: 'Upload' }));

        const sent = await waitForRequest('POST');
        // The multipart bytes, rather than a parsed FormData.
        //
        // The filename is not asserted, and the omission is the environment's rather
        // than a gap in the check: jsdom's File does not survive undici's multipart
        // serialisation as a named part, so the name is absent here however it is
        // appended. A browser sends it either way, and `uploadMedia` passes it
        // explicitly so that it does.
        const body = await sent.text();

        expect(sent.url).toContain('/api/v1/media');
        // Administrative endpoints are for moderation; uploading is a platform
        // capability and goes through the same route any account uses.
        expect(sent.url).not.toContain('/admin/');
        expect(body).toContain('name="collection"');
        expect(body).toContain('default');
        expect(body).toContain('name="visibility"');
        expect(body).toContain('public');
    });

    it('says nothing is stored rather than showing an empty grid', async () => {
        renderScreen(['media.view'], [], pagination({ total: 0 }));

        expect(await screen.findByText('Nothing is stored yet.')).toBeInTheDocument();
    });
});

describe('deciding whether the bytes can be shown', () => {
    // Typed against the contract, not inferred: a bare object literal widens `status`
    // and `type` to `string`, which is exactly the looseness the published enums exist
    // to remove.
    const base: Parameters<typeof previewVerdict>[0] = {
        status: 'ready',
        visibility: 'public',
        type: 'image',
        uploaded_by: null,
    };

    it('shows a public, ready image', () => {
        expect(previewVerdict(base, VIEWER_EMAIL)).toEqual({ readable: true });
    });

    it('refuses an unready file before it considers who is asking', () => {
        expect(previewVerdict({ ...base, status: 'scanning' }, VIEWER_EMAIL)).toEqual({
            readable: false,
            reason: 'not-ready',
        });
    });

    it('lets an uploader see their own private file', () => {
        expect(
            previewVerdict(
                { ...base, visibility: 'private', uploaded_by: VIEWER_EMAIL },
                VIEWER_EMAIL,
            ),
        ).toEqual({ readable: true });
    });

    it('refuses a private file uploaded by somebody else', () => {
        expect(
            previewVerdict(
                { ...base, visibility: 'private', uploaded_by: 'other@example.test' },
                VIEWER_EMAIL,
            ),
        ).toEqual({ readable: false, reason: 'private' });
    });

    it('renders no preview for a type this console does not display inline', () => {
        expect(previewVerdict({ ...base, type: 'document' }, VIEWER_EMAIL)).toEqual({
            readable: false,
            reason: 'not-visual',
        });
    });
});

/** The last request of a method the suite has seen, once one has arrived. */
async function waitForRequest(method: string): Promise<Request> {
    for (let attempt = 0; attempt < 50; attempt += 1) {
        const match = observed.findLast((request) => request.method === method);

        if (match !== undefined) {
            return match;
        }

        await new Promise((resolve) => setTimeout(resolve, 10));
    }

    throw new Error(`No ${method} request was sent.`);
}
