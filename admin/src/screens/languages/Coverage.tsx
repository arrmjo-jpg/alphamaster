import { useTranslation } from 'react-i18next';

import { cn } from '@/lib/cn';
import { coveragePercent } from '@/screens/translations/api';

export interface CoverageMeterProps {
    counts: { total: number; translated: number };
    /** The percentage and the bar only, for a table cell. */
    compact?: boolean;
    className?: string;
}

/**
 * How far a language has got, as the platform counts it (ADR 0048 §3).
 *
 * The number is the server's — saved fields out of all fields the viewer may read —
 * and this only draws it. Rounded down, so a language one field short of finished
 * never reads 100%, and finished turns the bar to the success colour rather than
 * leaving the operator to compare two numbers.
 */
export function CoverageMeter({ counts, compact = false, className }: CoverageMeterProps) {
    const { t } = useTranslation();
    const percent = coveragePercent(counts);

    if (percent === null) {
        return (
            <span className="text-(length:--text-xs) text-(--text-muted)">
                {t('languages.coverageNothing')}
            </span>
        );
    }

    return (
        <div className={cn('flex min-w-0 flex-col gap-1', className)}>
            <div className="flex items-baseline justify-between gap-2">
                <span
                    className="text-(length:--text-sm) font-medium text-(--text-primary)"
                    data-technical
                >
                    {t('languages.coveragePercent', { percent })}
                </span>
                {compact ? null : (
                    <span className="text-(length:--text-xs) text-(--text-muted)">
                        {t('languages.coverageFields', {
                            translated: counts.translated,
                            total: counts.total,
                        })}
                    </span>
                )}
            </div>
            <div
                aria-label={t('languages.coverage')}
                aria-valuemax={100}
                aria-valuemin={0}
                aria-valuenow={percent}
                className="h-1.5 w-full bg-(--surface-subtle)"
                role="progressbar"
            >
                <div
                    className={cn(
                        'h-full',
                        percent === 100 ? 'bg-(--state-success-rail)' : 'bg-(--brand)',
                    )}
                    style={{ width: `${percent}%` }}
                />
            </div>
        </div>
    );
}
