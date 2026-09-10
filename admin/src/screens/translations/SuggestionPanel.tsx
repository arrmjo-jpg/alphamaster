import { Check, Sparkles, X } from 'lucide-react';
import { useTranslation } from 'react-i18next';

import type { Suggestion } from '@/screens/ai/api';
import { Alert } from '@/ui/Alert';
import { Button } from '@/ui/Button';
import { StatusBadge } from '@/ui/StatusBadge';

export interface SuggestionPanelProps {
    suggestion: Suggestion;
    /** What is currently in the target field, so the panel can say whether it differs. */
    current: string;
    mayWrite: boolean;
    busy: boolean;
    onUse: (text: string) => void;
    onAccept: () => void;
    onDismiss: () => void;
    dir: string;
    lang: string;
}

/**
 * A proposed translation, and the four things it can be.
 *
 * The states are the point (ADR 0044 §5). A field with a suggestion has **not**
 * changed — nothing is written until somebody accepts — so the panel has to make the
 * difference between "the platform proposed this" and "this is the translation"
 * impossible to miss:
 *
 *   * **Waiting** — queued, the vendor has not answered.
 *   * **AI suggested** — text came back and the field still matches it.
 *   * **Edited** — a person changed it before deciding, which is a different fact.
 *   * **Not generated** — nothing came back, with the vendor's reason.
 *
 * "Use it" fills the field and saves nothing, which is what makes editing possible.
 * "Accept" writes what is in the field, not what the model said — because the whole
 * point of a review step is that the two may differ.
 */
export function SuggestionPanel({
    suggestion,
    current,
    mayWrite,
    busy,
    onUse,
    onAccept,
    onDismiss,
    dir,
    lang,
}: SuggestionPanelProps) {
    const { t } = useTranslation();

    if (suggestion.status === 'pending') {
        return (
            <p className="flex items-center gap-1.5 text-(length:--text-xs) text-(--text-muted)">
                <Sparkles aria-hidden className="size-3.5" />
                {t('translations.suggestion.waiting')}
            </p>
        );
    }

    if (suggestion.status === 'failed') {
        return (
            <Alert tone="warning">
                <p>{t('translations.suggestion.failed')}</p>
                {suggestion.error_message !== null ? (
                    <p className="mt-1 text-(length:--text-xs)">{suggestion.error_message}</p>
                ) : null}
            </Alert>
        );
    }

    const proposed = suggestion.suggestion ?? '';
    const empty = current.trim() === '';

    // Three distinguishable things, not two. The field holds the proposal; the field
    // holds something else a person wrote; the field holds nothing yet. Only the middle
    // one is an edit, and calling the last one an edit would credit a translator with
    // work nobody did.
    const matchesProposal = current.trim() === proposed.trim();
    const edited = !empty && !matchesProposal;

    return (
        <div className="flex flex-col gap-2 border-s-(length:--rail-width) border-(--state-info-rail) bg-(--state-info-tint)/30 p-2">
            <div className="flex flex-wrap items-center gap-1.5">
                <StatusBadge icon={<Sparkles className="size-3" />} tone="info">
                    {edited
                        ? t('translations.suggestion.edited')
                        : t('translations.suggestion.ready')}
                </StatusBadge>
                {/* Said rather than implied. A reviewer looking at a filled field needs
                    to know it is a proposal and not the platform's answer. */}
                <span className="text-(length:--text-2xs) text-(--text-muted)">
                    {t('translations.suggestion.notSavedYet')}
                </span>
            </div>

            <p
                className="text-(length:--text-sm) whitespace-pre-wrap text-(--text-primary)"
                dir={dir}
                lang={lang}
            >
                {proposed}
            </p>

            {mayWrite ? (
                <div className="flex flex-wrap items-center gap-2">
                    <Button
                        disabled={busy || matchesProposal}
                        onClick={() => onUse(proposed)}
                        size="sm"
                        variant="ghost"
                    >
                        {t('translations.suggestion.use')}
                    </Button>

                    <Button
                        disabled={busy || empty}
                        loading={busy}
                        onClick={onAccept}
                        size="sm"
                        variant="primary"
                    >
                        <Check aria-hidden className="size-3.5" />
                        {edited
                            ? t('translations.suggestion.acceptEdited')
                            : t('translations.suggestion.accept')}
                    </Button>

                    <Button disabled={busy} onClick={onDismiss} size="sm" variant="ghost">
                        <X aria-hidden className="size-3.5" />
                        {t('translations.suggestion.dismiss')}
                    </Button>
                </div>
            ) : (
                <p className="text-(length:--text-xs) text-(--text-muted)">
                    {t('translations.readOnly')}
                </p>
            )}
        </div>
    );
}
