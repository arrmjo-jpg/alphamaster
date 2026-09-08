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
    className?: string;
    children: React.ReactNode;
}

/**
 * A bounded region with its own four states.
 *
 * Loading, failed, empty and loaded belong to the panel rather than to the screen,
 * because one endpoint being down must not take the dashboard with it — an operator
 * checking on a failing integration cannot be shown a blank page because an unrelated
 * query timed out. Each panel reports its own condition and offers its own retry.
 */
export function Panel({
    title,
    aside,
    loading = false,
    error,
    onRetry,
    empty = false,
    emptyMessage,
    className,
    children,
}: PanelProps) {
    const { t } = useTranslation();

    return (
        <section
            className={cn(
                'flex flex-col rounded-lg border border-(--border-default) bg-(--surface-default)',
                className,
            )}
        >
            <header className="flex items-center justify-between gap-2 border-b border-(--border-default) px-3 py-2">
                <h2 className="text-(length:--text-md) font-semibold text-(--text-primary)">
                    {title}
                </h2>
                {aside !== undefined ? (
                    <div className="text-(length:--text-sm) text-(--text-muted)">{aside}</div>
                ) : null}
            </header>

            <div className="p-3">
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
        <div aria-label={t('state.loading')} className="flex flex-col gap-2" role="status">
            <span className="h-4 w-2/3 animate-pulse rounded-sm bg-(--action-secondary)" />
            <span className="h-4 w-1/2 animate-pulse rounded-sm bg-(--action-secondary)" />
            <span className="h-4 w-3/5 animate-pulse rounded-sm bg-(--action-secondary)" />
        </div>
    );
}
