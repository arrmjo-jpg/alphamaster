import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { HttpResponse, http } from 'msw';
import { useState } from 'react';
import { MemoryRouter } from 'react-router';
import { describe, expect, it } from 'vitest';

import { AppProviders } from '@/app/AppProviders';
import { AuthGate } from '@/auth/AuthGate';
import { seoChanged, seoDraftFor, seoWrite, type SeoDraft } from '@/screens/content/seo';
import { SeoFieldsEditor } from '@/screens/content/SeoFieldsEditor';
import { server } from '@/test/server';

import '@/i18n';

/**
 * The one SEO editor every content screen uses (ADR 0032): every field the platform stores, in
 * the content language, with one draft and one write body.
 */

describe('the SEO draft', () => {
    it('reads stored SEO, and empty fields where nothing is stored', () => {
        expect(seoDraftFor(undefined)).toEqual({
            title: '',
            description: '',
            robots: '',
            canonical_url: '',
            og_title: '',
            og_description: '',
            og_media_id: null,
        });

        expect(
            seoDraftFor({
                title: 'About',
                description: null,
                robots: 'noindex,follow',
                canonical_url: 'https://example.test/about',
                og_title: null,
                og_description: null,
                og_media_id: '01hzzimage',
            }),
        ).toMatchObject({ title: 'About', robots: 'noindex,follow', og_media_id: '01hzzimage' });
    });

    it('sends every field, clears what is empty, and never sends a robots value the platform refuses', () => {
        // Text is sent as typed and a whitespace-only field clears, as every content field in the
        // console does; the platform trims what it stores (SeoFields::fromArray).
        const draft: SeoDraft = {
            ...seoDraftFor(undefined),
            title: 'About',
            description: '   ',
            robots: '',
            canonical_url: 'https://example.test/about',
        };

        expect(seoWrite(draft)).toEqual({
            title: 'About',
            description: null,
            robots: null,
            canonical_url: 'https://example.test/about',
            og_title: null,
            og_description: null,
            og_media_id: null,
        });
        expect(seoWrite({ ...draft, robots: 'all' }).robots).toBeNull();
        expect(seoChanged(draft, seoDraftFor(undefined))).toBe(true);
        expect(seoChanged(seoDraftFor(undefined), seoDraftFor(undefined))).toBe(false);
    });
});

function Harness({ onChange }: { onChange: (draft: SeoDraft) => void }) {
    const [value, setValue] = useState<SeoDraft>(seoDraftFor(undefined));

    return (
        <SeoFieldsEditor
            collection="pages"
            direction="rtl"
            disabled={false}
            locale="ar"
            onChange={(next) => {
                onChange(next);
                setValue(next);
            }}
            value={value}
        />
    );
}

function renderEditor(onChange: (draft: SeoDraft) => void) {
    server.use(
        http.get('*/api/v1/languages', () =>
            HttpResponse.json({
                success: true,
                data: [
                    {
                        code: 'en',
                        name: 'English',
                        native_name: 'English',
                        direction: 'ltr',
                        is_default: true,
                    },
                ],
            }),
        ),
        http.get('*/api/v1/health', () =>
            HttpResponse.json({
                success: true,
                data: {
                    status: 'healthy',
                    timestamp: '2026-09-15T10:00:00+00:00',
                    framework: 'Laravel 13',
                },
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
                    permissions: ['pages.update'],
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

describe('the SEO editor', () => {
    it('offers every field the platform stores, typed in the content language', async () => {
        renderEditor(() => undefined);

        const title = await screen.findByLabelText('Search title');

        expect(title).toHaveAttribute('dir', 'rtl');
        expect(title).toHaveAttribute('lang', 'ar');
        expect(screen.getByLabelText('Search description')).toBeInTheDocument();
        expect(screen.getByLabelText('Search engines')).toBeInTheDocument();
        expect(screen.getByLabelText('Canonical address')).toHaveAttribute('dir', 'ltr');
        expect(screen.getByLabelText('Sharing title')).toBeInTheDocument();
        expect(screen.getByLabelText('Sharing description')).toBeInTheDocument();
        expect(screen.getByText('Sharing image')).toBeInTheDocument();
    });

    it('edits robots and the canonical address, and counts characters against the platform limit', async () => {
        let latest: SeoDraft = seoDraftFor(undefined);

        renderEditor((draft) => {
            latest = draft;
        });

        await userEvent.selectOptions(
            await screen.findByLabelText('Search engines'),
            'noindex,follow',
        );
        await userEvent.type(screen.getByLabelText('Canonical address'), 'https://example.test/x');
        await userEvent.type(screen.getByLabelText('Search title'), 'عن');

        expect(latest.robots).toBe('noindex,follow');
        expect(latest.canonical_url).toBe('https://example.test/x');
        expect(latest.title).toBe('عن');
        expect(screen.getByText(/2 of 255 characters/)).toBeInTheDocument();
    });
});
