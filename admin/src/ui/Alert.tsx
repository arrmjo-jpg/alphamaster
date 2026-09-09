import { AlertTriangle, CheckCircle2, Clock, Info, XCircle } from 'lucide-react';

import { cn } from '@/lib/cn';

import { TONE_CLASSES, type StateTone } from './state';

const ICONS: Record<StateTone, React.ComponentType<{ className?: string }>> = {
    success: CheckCircle2,
    warning: AlertTriangle,
    danger: XCircle,
    info: Info,
    pending: Clock,
    neutral: Info,
};

export interface AlertProps {
    tone: StateTone;
    title?: string;
    children: React.ReactNode;
    className?: string;
}

/**
 * A message about the whole operation rather than one field.
 *
 * `danger` announces itself: a sign-in refusal or a failed write has to reach a
 * screen reader without the user going looking for it.
 */
export function Alert({ tone, title, children, className }: AlertProps) {
    const Icon = ICONS[tone];
    const classes = TONE_CLASSES[tone];

    return (
        <div
            className={cn(
                'flex items-start gap-2 p-3 text-(length:--text-base)',
                classes.tint,
                classes.text,
                className,
            )}
            role={tone === 'danger' ? 'alert' : 'status'}
        >
            <Icon aria-hidden className="mt-0.5 size-4 shrink-0" />
            <div className="min-w-0">
                {title !== undefined ? <p className="font-medium">{title}</p> : null}
                <div className="text-(--text-secondary)">{children}</div>
            </div>
        </div>
    );
}
