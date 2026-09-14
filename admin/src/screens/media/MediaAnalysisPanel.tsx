import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';

import { ApiError } from '@/api/errors';
import { Alert } from '@/ui/Alert';
import { Button } from '@/ui/Button';
import { Checkbox } from '@/ui/Checkbox';
import type { StateTone } from '@/ui/state';
import { StatusBadge } from '@/ui/StatusBadge';

import {
    analysisState,
    cancelAnalysis,
    mediaAnalyses,
    requestAnalysis,
    reviewAnalysis,
    type MediaAnalysisRow,
    type ReviewDecision,
} from './analysis';

export interface MediaAnalysisPanelProps {
    mediaId: string;
    /** The file's own status; only a ready file can be analysed. */
    mediaStatus: string;
    mayRequest: boolean;
    mayReview: boolean;
}

const DECISIONS: ReviewDecision[] = ['confirmed_synthetic', 'confirmed_authentic', 'undetermined'];

/**
 * AI video detection for one file (ADR 0054).
 *
 * Nothing is analysed unless an operator asks here. What comes back is an assessment: a
 * score per type the analyzer looked at, the types it could not assess named as such rather
 * than shown as zero, and the provider, model and policy that produced it. A failed or
 * inconclusive analysis is shown as exactly that and never as authentic. A review is recorded
 * beside the analysis and does not change it.
 */
export function MediaAnalysisPanel({
    mediaId,
    mediaStatus,
    mayRequest,
    mayReview,
}: MediaAnalysisPanelProps) {
    const { t } = useTranslation();
    const queryClient = useQueryClient();
    const [chosen, setChosen] = useState<string[] | null>(null);
    const [reanalyze, setReanalyze] = useState(false);

    const state = useQuery({
        queryKey: ['media-analysis-state'],
        queryFn: ({ signal }) => analysisState(signal),
    });

    const history = useQuery({
        queryKey: ['media-analyses', mediaId],
        queryFn: ({ signal }) => mediaAnalyses(mediaId, signal),
    });

    const refresh = async () => {
        await queryClient.invalidateQueries({ queryKey: ['media-analyses', mediaId] });
        await queryClient.invalidateQueries({ queryKey: ['media-analysis-state'] });
    };

    const supported = state.data?.supported_types ?? [];
    const types = chosen ?? supported;

    const request = useMutation({
        mutationFn: () => requestAnalysis(mediaId, types, reanalyze),
        onSuccess: refresh,
    });

    const label = (type: string) => t(`media.analysis.type.${type}`, { defaultValue: type });

    return (
        <section className="flex flex-col gap-3 border-t border-(--border-default) pt-3">
            <div>
                <h3 data-eyebrow>{t('media.analysis.title')}</h3>
                <p className="text-(length:--text-sm) text-(--text-muted)">
                    {t('media.analysis.intro')}
                </p>
            </div>

            {state.data !== undefined && !state.data.available ? (
                <Alert tone="info">
                    {t(`media.analysis.unavailable.${state.data.reason ?? 'not_configured'}`)}
                </Alert>
            ) : null}

            {state.data?.available && mayRequest ? (
                mediaStatus !== 'ready' ? (
                    <p className="text-(length:--text-sm) text-(--text-muted)">
                        {t('media.analysis.notReady')}
                    </p>
                ) : (
                    <form
                        className="flex flex-col gap-2"
                        onSubmit={(event) => {
                            event.preventDefault();
                            request.mutate();
                        }}
                    >
                        <fieldset className="flex flex-col gap-1">
                            <legend className="text-(length:--text-sm) text-(--text-secondary)">
                                {t('media.analysis.lookFor')}
                            </legend>
                            {supported.map((type) => (
                                <label
                                    className="flex items-center gap-2 text-(length:--text-sm) text-(--text-primary)"
                                    key={type}
                                >
                                    <Checkbox
                                        checked={types.includes(type)}
                                        onChange={(event) =>
                                            setChosen(
                                                event.target.checked
                                                    ? [...types, type]
                                                    : types.filter(
                                                          (candidate) => candidate !== type,
                                                      ),
                                            )
                                        }
                                    />
                                    {label(type)}
                                </label>
                            ))}
                        </fieldset>

                        <label className="flex items-center gap-2 text-(length:--text-sm) text-(--text-secondary)">
                            <Checkbox
                                checked={reanalyze}
                                onChange={(event) => setReanalyze(event.target.checked)}
                            />
                            {t('media.analysis.reanalyze')}
                        </label>

                        {request.error instanceof ApiError ? (
                            <Alert tone="danger">{request.error.message}</Alert>
                        ) : null}

                        <div>
                            <Button
                                disabled={types.length === 0}
                                loading={request.isPending}
                                size="sm"
                                type="submit"
                                variant="secondary"
                            >
                                {t('media.analysis.request')}
                            </Button>
                        </div>
                    </form>
                )
            ) : null}

            {history.isPending ? (
                <p className="text-(length:--text-sm) text-(--text-muted)">{t('state.loading')}</p>
            ) : history.error !== null ? (
                <Alert tone="danger">
                    {history.error instanceof ApiError ? history.error.message : t('state.error')}
                </Alert>
            ) : history.data.length === 0 ? (
                <p className="text-(length:--text-sm) text-(--text-muted)">
                    {t('media.analysis.empty')}
                </p>
            ) : (
                <ul className="flex flex-col gap-2">
                    {history.data.map((row) => (
                        <AnalysisRow
                            key={row.id}
                            label={label}
                            mayRequest={mayRequest}
                            mayReview={mayReview}
                            onChanged={refresh}
                            row={row}
                        />
                    ))}
                </ul>
            )}
        </section>
    );
}

function AnalysisRow({
    row,
    label,
    mayRequest,
    mayReview,
    onChanged,
}: {
    row: MediaAnalysisRow;
    label: (type: string) => string;
    mayRequest: boolean;
    mayReview: boolean;
    onChanged: () => Promise<void>;
}) {
    const { t } = useTranslation();
    const [decision, setDecision] = useState<ReviewDecision>('undetermined');
    const [note, setNote] = useState('');

    const cancel = useMutation({ mutationFn: () => cancelAnalysis(row.id), onSuccess: onChanged });
    const review = useMutation({
        mutationFn: () => reviewAnalysis(row.id, decision, note),
        onSuccess: async () => {
            setNote('');
            await onChanged();
        },
    });

    const assessed = row.status === 'completed' || row.status === 'inconclusive';
    const scores = Object.entries(row.scores);

    return (
        <li className="flex flex-col gap-1.5 border border-(--border-subtle) p-2">
            <div className="flex flex-wrap items-center gap-1.5">
                <StatusBadge tone={statusTone(row.status)}>{row.status_label}</StatusBadge>
                {row.classification_label !== null ? (
                    <StatusBadge tone="neutral">{row.classification_label}</StatusBadge>
                ) : null}
                {row.superseded_by !== null ? (
                    <span className="text-(length:--text-xs) text-(--text-muted)">
                        {t('media.analysis.superseded')}
                    </span>
                ) : null}
            </div>

            {scores.length > 0 ? (
                <dl className="grid grid-cols-[auto_1fr] gap-x-3 text-(length:--text-sm)">
                    {scores.map(([type, score]) => (
                        <div className="contents" key={type}>
                            <dt className="text-(--text-muted)">
                                {row.type_labels[type] ?? label(type)}
                            </dt>
                            <dd data-technical>{`${Math.round(score * 100)}%`}</dd>
                        </div>
                    ))}
                    {row.confidence !== null ? (
                        <>
                            <dt className="text-(--text-muted)">
                                {t('media.analysis.confidence')}
                            </dt>
                            <dd data-technical>{`${Math.round(row.confidence * 100)}%`}</dd>
                        </>
                    ) : null}
                </dl>
            ) : null}

            {row.unsupported_types.length > 0 ? (
                <p className="text-(length:--text-xs) text-(--text-muted)">
                    {t('media.analysis.notAssessed', {
                        types: row.unsupported_types
                            .map((type) => row.type_labels[type] ?? label(type))
                            .join(', '),
                    })}
                </p>
            ) : null}

            {row.error_code !== null ? (
                <p className="text-(length:--text-xs) text-(--text-danger)" data-technical>
                    {row.error_code}
                </p>
            ) : null}

            <p className="text-(length:--text-xs) text-(--text-muted)" data-technical>
                {[row.provider, row.model_version, row.policy_version].filter(Boolean).join(' · ')}
            </p>

            {mayRequest && row.status === 'pending' ? (
                <div>
                    <Button
                        loading={cancel.isPending}
                        onClick={() => cancel.mutate()}
                        size="sm"
                        variant="ghost"
                    >
                        {t('media.analysis.cancel')}
                    </Button>
                </div>
            ) : null}

            {row.reviews.length > 0 ? (
                <ul className="flex flex-col gap-0.5 text-(length:--text-xs) text-(--text-secondary)">
                    {row.reviews.map((entry) => (
                        <li key={entry.id}>
                            {entry.decision_label}
                            {entry.reviewer !== null ? ` · ${entry.reviewer}` : ''}
                            {entry.note !== null ? ` — ${entry.note}` : ''}
                        </li>
                    ))}
                </ul>
            ) : null}

            {mayReview && assessed ? (
                <form
                    className="flex flex-col gap-1.5"
                    onSubmit={(event) => {
                        event.preventDefault();
                        review.mutate();
                    }}
                >
                    <label className="flex flex-col gap-0.5 text-(length:--text-xs) text-(--text-secondary)">
                        {t('media.analysis.review.decision')}
                        <select
                            className="h-(--field-height) border border-(--border-strong) bg-(--surface-default) px-2 text-(length:--text-sm) text-(--text-primary)"
                            onChange={(event) => setDecision(event.target.value as ReviewDecision)}
                            value={decision}
                        >
                            {DECISIONS.map((value) => (
                                <option key={value} value={value}>
                                    {t(`media.analysis.review.decisions.${value}`)}
                                </option>
                            ))}
                        </select>
                    </label>
                    <label className="flex flex-col gap-0.5 text-(length:--text-xs) text-(--text-secondary)">
                        {t('media.analysis.review.note')}
                        <textarea
                            className="min-h-14 border border-(--border-strong) bg-(--surface-default) px-2 py-1 text-(length:--text-sm) text-(--text-primary)"
                            maxLength={1000}
                            onChange={(event) => setNote(event.target.value)}
                            value={note}
                        />
                    </label>
                    {review.error instanceof ApiError ? (
                        <Alert tone="danger">{review.error.message}</Alert>
                    ) : null}
                    <div>
                        <Button
                            loading={review.isPending}
                            size="sm"
                            type="submit"
                            variant="secondary"
                        >
                            {t('media.analysis.review.submit')}
                        </Button>
                    </div>
                </form>
            ) : null}
        </li>
    );
}

function statusTone(status: string): StateTone {
    switch (status) {
        case 'completed':
            return 'success';
        case 'failed':
            return 'danger';
        case 'inconclusive':
        case 'unsupported':
            return 'warning';
        case 'cancelled':
            return 'neutral';
        default:
            return 'pending';
    }
}
