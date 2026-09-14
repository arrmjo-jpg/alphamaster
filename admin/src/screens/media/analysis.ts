import { fetchData } from '@/api/client';
import type {
    AdminMediaAnalysesIndexResponses,
    AdminMediaAnalysisStatusResponses,
} from '@/api/generated';

/**
 * Media analysis — AI video detection — in the Admin (ADR 0054).
 *
 * The Admin is one consumer of the capability. Nothing here starts an analysis on its own:
 * an operator asks for one, for one file, and chooses what to look for. The same capability
 * is called by any module that decides it needs it, through the platform's contract rather
 * than these endpoints.
 */

export type MediaAnalysisState = AdminMediaAnalysisStatusResponses[200]['data'];
export type MediaAnalysisRow = AdminMediaAnalysesIndexResponses[200]['data'][number];
export type ReviewDecision = 'confirmed_synthetic' | 'confirmed_authentic' | 'undetermined';

export async function analysisState(signal?: AbortSignal): Promise<MediaAnalysisState> {
    return fetchData<MediaAnalysisState>('/admin/media/analysis', {
        ...(signal ? { signal } : {}),
    });
}

export async function mediaAnalyses(
    mediaId: string,
    signal?: AbortSignal,
): Promise<MediaAnalysisRow[]> {
    return fetchData<MediaAnalysisRow[]>(`/admin/media/${mediaId}/analyses`, {
        ...(signal ? { signal } : {}),
    });
}

/** Queued, never run on the spot; an equivalent existing analysis is returned instead. */
export async function requestAnalysis(
    mediaId: string,
    types: string[],
    reanalyze: boolean,
): Promise<MediaAnalysisRow> {
    return fetchData<MediaAnalysisRow>(`/admin/media/${mediaId}/analyses`, {
        method: 'POST',
        body: { types, reanalyze },
    });
}

export async function cancelAnalysis(id: string): Promise<MediaAnalysisRow> {
    return fetchData<MediaAnalysisRow>(`/admin/media/analyses/${id}/cancel`, { method: 'POST' });
}

/** Recorded beside the analysis; the analysis itself is never changed. */
export async function reviewAnalysis(
    id: string,
    decision: ReviewDecision,
    note: string,
): Promise<MediaAnalysisRow> {
    return fetchData<MediaAnalysisRow>(`/admin/media/analyses/${id}/reviews`, {
        method: 'POST',
        body: { decision, ...(note.trim() === '' ? {} : { note: note.trim() }) },
    });
}
