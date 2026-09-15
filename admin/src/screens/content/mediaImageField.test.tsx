import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { HttpResponse, http } from 'msw';
import { useState } from 'react';
import { MemoryRouter } from 'react-router';
import { describe, expect, it, vi } from 'vitest';

import { AppProviders } from '@/app/AppProviders';
import { AuthGate } from '@/auth/AuthGate';
import { MediaImageField } from '@/screens/content/MediaImageField';
import { observed, server } from '@/test/server';

import '@/i18n';

/**
 * The one field content uses to refer to an image (ADR 0057 §4).
 *
 * It uploads through the platform's media capability as a public image, waits until the image is
 * ready — the rule the save enforces — and only then hands an id to its editor. It offers the
 * library only to an account that may read it, and only the images content may use.
 */

function record(overrides: Record<string, unknown> = {}) {
    return {
        id: '01hzzimage',
        collection: 'team',
        original_filename: 'nadia.png',
        mime_type: 'image/png',
        type: 'image',
        type_label: 'Image',
        size_bytes: 68,
        checksum: 'a'.repeat(64),
        visibility: 'public',
        visibility_label: 'Public',
        status: 'processing',
        status_label: 'Processing',
        scan_status: 'not_scanned',
        scan_status_label: 'Not scanned',
        width: null,
        height: null,
        duration_seconds: null,
        url: null,
        created_at: null,
        ...overrides,
    };
}

function Harness({ onChange }: { onChange: (id: string | null) => void }) {
    const [id, setId] = useState<string | null>(null);

    return (
        <MediaImageField
            collection="team"
            label="Picture"
            mediaId={id}
            onChange={(next) => {
                onChange(next);
                setId(next);
            }}
            pollIntervalMs={5}
            previewUrl={null}
        />
    );
}

function renderField(permissions: string[], onChange: (id: string | null) => void) {
    server.use(
        http.get('*/api/v1/languages', () =>
            HttpResponse.json({
                success: true,
                data: [
                    { code: 'en', name: 'English', native_name: 'English', direction: 'ltr', is_default: true },
                ],
            }),
        ),
        http.get('*/api/v1/health', () =>
            HttpResponse.json({
                success: true,
                data: { status: 'healthy', timestamp: '2026-09-15T10:00:00+00:00', framework: 'Laravel 13' },
            }),
        ),
        http.get('*/api/v1/auth/me', () =>
            HttpResponse.json({
                success: true,
                data: {
                    id: '01hzzviewer',
                    name: 'Nadia Haddad',
                    email: 'nadia@example.test',
                    account_type: 'admin',
                    is_active: true,
                    email_verified: true,
                    email_verified_at: '2026-01-01T00:00:00+00:00',
                    phone: null,
                    phone_verified: false,
                    phone_verified_at: null,
                    avatar_url: null,
                    abilities: ['admin:access'],
                    roles: [],
                    permissions,
                },
            }),
        ),
    );

    render(
        <MemoryRouter>
            <AppProviders>
                <AuthGate>
                    <Harness onChange={onChange} />
                </AuthGate>
            </AppProviders>
        </MemoryRouter>,
    );
}

function png(): File {
    return new File([new Uint8Array([137, 80, 78, 71])], 'nadia.png', { type: 'image/png' });
}

describe('the image field', () => {
    it('uploads a public image and hands over its id only once it is ready', async () => {
        const onChange = vi.fn();
        let sent = '';
        let asked = 0;

        server.use(
            http.post('*/api/v1/media', async ({ request }) => {
                // Read raw: Node's multipart parser does not accept the test DOM's File, and the
                // fields this asserts on are plain text parts of the body.
                sent = await request.text();

                return HttpResponse.json({ success: true, message: 'accepted', data: record() }, { status: 201 });
            }),
            http.get('*/api/v1/media/01hzzimage', () => {
                asked += 1;

                return HttpResponse.json({
                    success: true,
                    data: record({ status: 'ready', url: '/storage/nadia.png' }),
                });
            }),
        );

        renderField(['team.update'], onChange);

        await userEvent.upload(await screen.findByLabelText('Upload an image'), png());

        await vi.waitFor(() => expect(onChange).toHaveBeenCalledWith('01hzzimage'));

        expect(sent).toMatch(/name="visibility"\r?\n\r?\npublic\r?\n/);
        expect(sent).toMatch(/name="collection"\r?\n\r?\nteam\r?\n/);
        expect(asked).toBeGreaterThan(0);
        // No upload path of its own: the platform's media endpoint, and nothing else wrote.
        expect(
            observed.filter((request) => request.method !== 'GET').map((request) => new URL(request.url).pathname),
        ).toContain('/api/v1/media');
    });

    it('reports an image the platform could not accept, and hands over nothing', async () => {
        const onChange = vi.fn();

        server.use(
            http.post('*/api/v1/media', () =>
                HttpResponse.json(
                    { success: true, message: 'accepted', data: record({ status: 'processing_failed' }) },
                    { status: 201 },
                ),
            ),
        );

        renderField(['team.update'], onChange);

        await userEvent.upload(await screen.findByLabelText('Upload an image'), png());

        expect(await screen.findByText('The platform could not accept this image.')).toBeInTheDocument();
        expect(onChange).not.toHaveBeenCalled();
    });

    it('offers only public images from the library, and picking one hands over its id', async () => {
        const onChange = vi.fn();

        server.use(
            http.get('*/api/v1/admin/media', ({ request }) => {
                const query = new URL(request.url).searchParams;

                expect(query.get('type')).toBe('image');
                expect(query.get('status')).toBe('ready');

                return HttpResponse.json({
                    success: true,
                    data: [
                        { id: '01hzzpublic', url: '/storage/public.png', original_filename: 'public.png', visibility: 'public' },
                        { id: '01hzzprivate', url: null, original_filename: 'private.png', visibility: 'private' },
                    ],
                    meta: { pagination: { current_page: 1, per_page: 25, total: 2, last_page: 1, has_more_pages: false } },
                });
            }),
        );

        renderField(['team.update', 'media.view'], onChange);

        await userEvent.click(await screen.findByRole('button', { name: 'Choose from the library' }));

        const offered = await screen.findByRole('button', { name: 'public.png' });

        expect(screen.queryByRole('button', { name: 'private.png' })).not.toBeInTheDocument();

        await userEvent.click(offered);

        expect(onChange).toHaveBeenCalledWith('01hzzpublic');

        await userEvent.click(await screen.findByRole('button', { name: 'Remove image' }));

        expect(onChange).toHaveBeenLastCalledWith(null);
    });

    it('does not offer the library to an account that may not read it', async () => {
        renderField(['team.update'], vi.fn());

        expect(await screen.findByRole('button', { name: 'Upload an image' })).toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'Choose from the library' })).not.toBeInTheDocument();
    });
});
