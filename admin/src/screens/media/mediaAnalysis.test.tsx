import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { HttpResponse, http } from 'msw';
import { describe, expect, it } from 'vitest';

import { server } from '@/test/server';

import type { MediaAnalysisRow, MediaAnalysisState } from './analysis';
import { MediaAnalysisPanel } from './MediaAnalysisPanel';

import '@/i18n';

/**
 * The media analysis panel. What matters: nothing is analysed without an operator asking,
 * an unavailable capability says why, a type the analyzer did not assess is named rather
 * than shown as zero, and a review is sent as its own record.
 */

function state(overrides: Partial<MediaAnalysisState> = {}): MediaAnalysisState {
    return {
        available: true,
        reason: null,
        supported_types: ['ai_generated', 'deepfake'],
        analyzer: {
            provider: 'fake',
            analyzer: 'fake-detector',
            model_version: 'fake-model-1',
            supported_types: ['ai_generated', 'deepfake'],
            accepted_mime_types: ['video/'],
            max_bytes: null,
            max_duration_seconds: null,
        },
        policy: {
            enabled: true,
            max_bytes: null,
            min_video_duration_seconds: null,
            max_video_duration_seconds: null,
            daily_limit: null,
            timeout_seconds: 300,
            likely_synthetic_threshold: null,
            likely_authentic_threshold: null,
            version: 'thresholds:none',
        },
        queue: { pending: 0, processing: 0, failed_last_day: 0, requested_today: 0 },
        ...overrides,
    };
}

function row(overrides: Partial<MediaAnalysisRow> = {}): MediaAnalysisRow {
    return {
        id: '01analysis',
        media_id: '01media',
        consumer: 'admin.manual',
        status: 'completed',
        status_label: 'Completed',
        types: ['ai_generated'],
        classification: null,
        classification_label: null,
        confidence: 0.8,
        scores: { ai_generated: 0.91 },
        unsupported_types: ['deepfake'],
        signals: [],
        provider: 'fake',
        analyzer: 'fake-detector',
        model_version: 'fake-model-1',
        policy_version: 'thresholds:none',
        input_fingerprint: 'f'.repeat(64),
        attempts: 1,
        error_code: null,
        error_message: null,
        requested_at: '2026-09-14T10:00:00+00:00',
        started_at: '2026-09-14T10:00:01+00:00',
        completed_at: '2026-09-14T10:00:05+00:00',
        superseded_by: null,
        reanalysis_of: null,
        type_labels: { ai_generated: 'AI generation', deepfake: 'Deepfake' },
        reviews: [],
        ...overrides,
    };
}

function show(
    current: MediaAnalysisState,
    rows: MediaAnalysisRow[],
    mayRequest = true,
    mayReview = true,
) {
    const sent: Array<{ path: string; body: unknown }> = [];

    server.use(
        http.get('*/api/v1/admin/media/analysis', () =>
            HttpResponse.json({ success: true, data: current }),
        ),
        http.get('*/api/v1/admin/media/:id/analyses', () =>
            HttpResponse.json({ success: true, data: rows }),
        ),
        http.post('*/api/v1/admin/media/:id/analyses', async ({ request }) => {
            sent.push({ path: new URL(request.url).pathname, body: await request.json() });

            return HttpResponse.json(
                { success: true, data: row({ status: 'pending', status_label: 'Queued' }) },
                { status: 202 },
            );
        }),
        http.post('*/api/v1/admin/media/analyses/:id/reviews', async ({ request }) => {
            sent.push({ path: new URL(request.url).pathname, body: await request.json() });

            return HttpResponse.json({ success: true, data: row() }, { status: 201 });
        }),
    );

    const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });

    render(
        <QueryClientProvider client={client}>
            <MediaAnalysisPanel
                mayRequest={mayRequest}
                mayReview={mayReview}
                mediaId="01media"
                mediaStatus="ready"
            />
        </QueryClientProvider>,
    );

    return sent;
}

async function waitForRequest<T>(find: () => T | undefined): Promise<T> {
    for (let attempt = 0; attempt < 50; attempt++) {
        const found = find();

        if (found !== undefined) {
            return found;
        }

        await new Promise((resolve) => setTimeout(resolve, 20));
    }

    throw new Error('Timed out waiting for a request');
}

describe('media analysis panel', () => {
    it('says why analysis is unavailable and offers no request', async () => {
        show(
            state({ available: false, reason: 'disabled', supported_types: [], analyzer: null }),
            [],
        );

        expect(await screen.findByText(/switched off/i)).toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'Request analysis' })).not.toBeInTheDocument();
    });

    it('asks for the chosen types only when the operator requests it', async () => {
        const sent = show(state(), []);

        await userEvent.click(await screen.findByRole('checkbox', { name: 'Deepfake' }));

        expect(sent).toHaveLength(0);

        await userEvent.click(screen.getByRole('button', { name: 'Request analysis' }));

        const post = await waitForRequest(() =>
            sent.find((entry) => entry.path.endsWith('/admin/media/01media/analyses')),
        );

        expect(post.body).toEqual({ types: ['ai_generated'], reanalyze: false });
    });

    it('shows scores, and names a type that was not assessed instead of scoring it zero', async () => {
        show(state(), [row()]);

        expect(await screen.findByText('91%')).toBeInTheDocument();
        expect(screen.getByText('Not assessed: Deepfake')).toBeInTheDocument();
        expect(screen.queryByText('0%')).not.toBeInTheDocument();
    });

    it('shows a failed analysis as failed, with no reading of the media', async () => {
        show(state(), [
            row({
                status: 'failed',
                status_label: 'Failed',
                scores: {},
                confidence: null,
                unsupported_types: [],
                error_code: 'VENDOR_REFUSED',
            }),
        ]);

        expect(await screen.findByText('Failed')).toBeInTheDocument();
        expect(screen.getByText('VENDOR_REFUSED')).toBeInTheDocument();
        expect(screen.queryByText(/likely authentic/i)).not.toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'Record review' })).not.toBeInTheDocument();
    });

    it('records a review as its own request', async () => {
        const sent = show(state(), [row()]);

        await userEvent.selectOptions(await screen.findByRole('combobox'), 'confirmed_synthetic');
        await userEvent.type(screen.getByRole('textbox'), 'Hands change between frames.');
        await userEvent.click(screen.getByRole('button', { name: 'Record review' }));

        const post = await waitForRequest(() =>
            sent.find((entry) => entry.path.endsWith('/reviews')),
        );

        expect(post.body).toEqual({
            decision: 'confirmed_synthetic',
            note: 'Hands change between frames.',
        });
    });

    it('states the duration limits for analysis as durations', async () => {
        const base = state();

        show(
            state({
                policy: {
                    ...base.policy,
                    min_video_duration_seconds: 180,
                    max_video_duration_seconds: 300,
                },
            }),
            [],
        );

        expect(
            await screen.findByText('Videos from 00:03:00 to 00:05:00 can be analysed.'),
        ).toBeInTheDocument();
    });

    it('shows a duration refusal as the platform worded it, and records nothing', async () => {
        show(state(), []);

        server.use(
            http.post('*/api/v1/admin/media/:id/analyses', () =>
                HttpResponse.json(
                    {
                        success: false,
                        error: {
                            code: 'MEDIA_ANALYSIS_DURATION_TOO_LONG',
                            message:
                                'This media is longer than the longest duration allowed for analysis.',
                            details: { duration_ms: 480000, limit_seconds: 300 },
                        },
                    },
                    { status: 422 },
                ),
            ),
        );

        await userEvent.click(await screen.findByRole('button', { name: 'Request analysis' }));

        expect(
            await screen.findByText(
                'This media is longer than the longest duration allowed for analysis.',
            ),
        ).toBeInTheDocument();
        expect(screen.getByText('This file has not been analysed.')).toBeInTheDocument();
    });
});
