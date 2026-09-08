import { cn } from '@/lib/cn';

import { TONE_CLASSES, type StateTone } from './state';

export interface StatusBadgeProps {
    tone: StateTone;
    /** Rendered text. Colour is never the only carrier of the meaning. */
    children: React.ReactNode;
    icon?: React.ReactNode;
    className?: string;
}

export function StatusBadge({ tone, children, icon, className }: StatusBadgeProps) {
    const classes = TONE_CLASSES[tone];

    return (
        <span
            className={cn(
                'inline-flex items-center gap-1 rounded-sm px-1.5 py-0.5',
                'text-(length:--text-xs) font-medium',
                classes.tint,
                classes.text,
                className,
            )}
        >
            {icon !== undefined ? (
                <span aria-hidden className="inline-flex">
                    {icon}
                </span>
            ) : null}
            {children}
        </span>
    );
}
