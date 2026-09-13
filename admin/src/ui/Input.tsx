import { cn } from '@/lib/cn';

export interface InputProps extends React.InputHTMLAttributes<HTMLInputElement> {
    /** Marks the field as failed and is wired to the message by the caller. */
    invalid?: boolean;
}

/**
 * A field never carries its own label. `Field` composes the two, and this exists so
 * that a label-less input is a visible omission rather than an easy default.
 */
export function Input({ className, invalid = false, ...props }: InputProps) {
    return (
        <input
            className={cn(
                'h-(--field-height) w-full border bg-(--surface-input) px-2.5',
                'text-(length:--text-base) text-(--text-primary) placeholder:text-(--text-muted)',
                'transition-colors duration-100 ease-out',
                'focus-visible:border-(--border-focus) focus-visible:outline-2 focus-visible:outline-offset-0 focus-visible:outline-(--focus-ring)',
                'disabled:cursor-not-allowed disabled:opacity-50',
                // The control edge, not a structural rule. With no radius and no
                // fill the boundary is the entire affordance, so it is held to the
                // 3:1 WCAG asks of a control's edge on every surface a field can
                // sit on — a hairline would all but disappear against dark.
                invalid
                    ? 'border-(--state-danger-rail)'
                    : 'border-(--border-control) hover:border-(--border-focus)',
                className,
            )}
            {...(invalid ? { 'aria-invalid': true } : {})}
            {...props}
        />
    );
}
