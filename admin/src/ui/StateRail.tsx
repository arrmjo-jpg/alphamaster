import { cn } from '@/lib/cn';

import { TONE_CLASSES, type StateTone } from './state';

export interface StateRailProps {
    tone: StateTone;
    children: React.ReactNode;
    className?: string;
}

/**
 * A coloured rail on the inline-start edge of a block.
 *
 * This is the product's visual signature, not mandatory decoration: it belongs on
 * things that genuinely have a state worth scanning for down a column. A rail on
 * every element makes the rail mean nothing.
 *
 * It uses logical inset, so it moves to the right edge under `dir="rtl"` without a
 * second rule.
 */
export function StateRail({ tone, children, className }: StateRailProps) {
    return (
        <div className={cn('relative ps-3', className)}>
            <span
                aria-hidden
                className={cn(
                    'absolute inset-y-0 start-0 w-(--rail-width) ',
                    TONE_CLASSES[tone].rail,
                )}
            />
            {children}
        </div>
    );
}
