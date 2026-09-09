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
                'h-(--field-height) w-full border bg-(--surface-default) px-2',
                'text-(length:--text-base) text-(--text-primary) placeholder:text-(--text-muted)',
                'transition-colors duration-100 ease-out',
                'focus-visible:outline-2 focus-visible:outline-offset-0 focus-visible:outline-(--focus-ring)',
                'disabled:cursor-not-allowed disabled:opacity-50',
                // The strong rule, not the default one. With no radius and no
                // shadow the boundary is the entire affordance, and the hairline
                // used between rows all but disappears against a dark surface.
                invalid
                    ? 'border-(--state-danger-rail)'
                    : 'border-(--border-strong) hover:border-(--border-focus)',
                className,
            )}
            {...(invalid ? { 'aria-invalid': true } : {})}
            {...props}
        />
    );
}
