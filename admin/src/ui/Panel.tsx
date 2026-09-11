import { AlertTriangle } from 'lucide-react';
import { useTranslation } from 'react-i18next';

import { ApiError } from '@/api/errors';
import { cn } from '@/lib/cn';
import { Button } from '@/ui/Button';

export interface PanelProps {
    title: string;
    /** Rendered beside the title — a count, a timestamp, a status. */
    aside?: React.ReactNode;
    loading?: boolean;
    error?: unknown;
    onRetry?: () => void;
    /** Shown instead of children when there is nothing to show. */
    empty?: boolean;
    emptyMessage?: string;
    /**
     * The panel's title level. `2` when a panel is a region of the page in its own
     * right, `3` when the page groups panels under headings of its own — the outline
     * has to match what a reader is actually looking at.
     */
    headingLevel?: 2 | 3;
    className?: string;
    children: React.ReactNode;
}

/**
 * A card: a bounded region with its own four states.
 *
 * Loading, failed, empty and loaded belong to the card rather than to the screen,
 * because one endpoint being down must not take the dashboard with it — an operator
 * checking on a failing integration cannot be shown a blank page because an unrelated
 * query timed out. Each card reports its own condition and offers its own retry.
 *
 * Visually it is an object now, where it used to be a region. Square as ever, but it
 * declares itself three ways at once — the white surface against the cool ground, a
 * soft edge, and a lift of a pixel or two — so no single one of them has to be heavy.
 * Its title is a real heading at card weight rather than the small capitalised
 * eyebrow it used to wear: the eyebrow still names a *region* of a page, and a card
 * inside that region is the thing a reader scans for, so it gets the stronger voice.
 */
export function Panel({
    title,
    aside,
    loading = false,
    error,
    onRetry,
    empty = false,
    emptyMessage,
    headingLevel = 2,
    className,
    children,
}: PanelProps) {
    const { t } = useTranslation();
    const Heading = headingLevel === 3 ? 'h3' : 'h2';

    return (
        <section
            className={cn(
                'flex min-w-0 flex-col border border-(--border-default) bg-(--surface-default) shadow-(--shadow-card)',
                className,
            )}
        >
            <header className="flex min-h-12 items-center justify-between gap-3 border-b border-(--border-subtle) px-(--card-padding) py-3">
                <Heading className="min-w-0 truncate text-(length:--text-md) font-bold tracking-normal text-(--text-primary)">
                    {title}
                </Heading>
                {aside !== undefined ? (
                    <div className="shrink-0 text-(length:--text-sm) text-(--text-muted)">
                        {aside}
                    </div>
                ) : null}
            </header>

            <div className="p-(--card-padding)">
                {error !== undefined && error !== null ? (
                    <PanelError error={error} {...(onRetry ? { onRetry } : {})} />
                ) : loading ? (
                    <Skeleton />
                ) : empty ? (
                    <p className="text-(--text-muted)">{emptyMessage ?? t('state.empty')}</p>
                ) : (
                    children
                )}
            </div>
        </section>
    );
}

function PanelError({ error, onRetry }: { error: unknown; onRetry?: () => void }) {
    const { t } = useTranslation();
    const message = error instanceof ApiError ? error.message : t('state.error');

    return (
        <div className="flex items-start gap-2">
            <AlertTriangle
                aria-hidden
                className="mt-0.5 size-4 shrink-0 text-(--state-danger-rail)"
            />
            <div className="flex min-w-0 flex-col items-start gap-2">
                <p className="text-(--text-secondary)">{message}</p>
                {onRetry !== undefined ? (
                    <Button onClick={onRetry} size="sm" variant="secondary">
                        {t('state.retry')}
                    </Button>
                ) : null}
            </div>
        </div>
    );
}

/**
 * Three bars of roughly the right shape.
 *
 * A skeleton rather than a spinner because these panels have a known layout, so the
 * space they will occupy can be held — which is what stops the rest of the dashboard
 * jumping when the slowest one arrives.
 */
function Skeleton() {
    const { t } = useTranslation();

    return (
        <div aria-label={t('state.loading')} className="flex flex-col gap-2.5" role="status">
            <span className="h-3.5 w-2/3 animate-pulse bg-(--action-secondary)" />
            <span className="h-3.5 w-1/2 animate-pulse bg-(--action-secondary)" />
            <span className="h-3.5 w-3/5 animate-pulse bg-(--action-secondary)" />
        </div>
    );
}
