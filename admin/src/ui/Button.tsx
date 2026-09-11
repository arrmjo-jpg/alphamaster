import { Slot } from '@radix-ui/react-slot';
import { cva, type VariantProps } from 'class-variance-authority';
import { Loader2 } from 'lucide-react';

import { cn } from '@/lib/cn';

/**
 * One primary action per screen; everything else is subordinate.
 *
 * `danger` is deliberately a variant rather than a colour a caller applies, so that
 * destructive intent is declared in the markup and can be found by reading it.
 */
const button = cva(
    'inline-flex items-center justify-center gap-2 whitespace-nowrap font-medium ' +
        'transition-colors duration-100 ease-out ' +
        'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-(--focus-ring) ' +
        'disabled:pointer-events-none disabled:opacity-50',
    {
        variants: {
            variant: {
                primary:
                    'bg-(--action-primary) text-(--action-primary-text) hover:bg-(--action-primary-hover)',
                // A neutral surface with a structural edge, so it reads as a button
                // beside a primary one rather than as a grey patch of the page.
                secondary:
                    'border border-(--border-strong) bg-(--surface-default) text-(--text-primary) hover:border-(--border-control) hover:bg-(--surface-hover)',
                ghost: 'bg-transparent text-(--text-secondary) hover:bg-(--action-ghost-hover) hover:text-(--text-primary)',
                danger: 'bg-(--state-danger-rail) text-white hover:bg-(--red-600)',
                link: 'bg-transparent text-(--action-primary) underline-offset-4 hover:underline',
            },
            size: {
                // Heights follow density, so a compact table and a comfortable form
                // stay internally consistent without either hard-coding a number.
                sm: 'h-(--field-height) px-2 text-(length:--text-sm)',
                md: 'h-(--field-height) px-3 text-(length:--text-base)',
                lg: 'h-10 px-4 text-(length:--text-md)',
                icon: 'size-(--field-height) p-0',
            },
        },
        defaultVariants: { variant: 'secondary', size: 'md' },
    },
);

export interface ButtonProps
    extends React.ButtonHTMLAttributes<HTMLButtonElement>, VariantProps<typeof button> {
    asChild?: boolean;
    /** Shows a spinner and blocks the click without losing the button's width. */
    loading?: boolean;
}

export function Button({
    className,
    variant,
    size,
    asChild = false,
    loading = false,
    disabled,
    children,
    ...props
}: ButtonProps) {
    // `asChild` hands the styling to whatever is inside — a router link, usually —
    // and takes a separate path rather than a different tag name. Slot accepts
    // exactly one child, so the spinner cannot be prepended; and `disabled` is not
    // an attribute an anchor has, so passing it through would put an invalid
    // attribute on the element and change nothing about whether it can be clicked.
    // Neither belongs on a link, which is why they are dropped rather than adapted.
    if (asChild) {
        return (
            <Slot className={cn(button({ variant, size }), className)} {...props}>
                {children}
            </Slot>
        );
    }

    return (
        <button
            className={cn(button({ variant, size }), className)}
            disabled={disabled === true || loading}
            {...(loading ? { 'aria-busy': true } : {})}
            {...props}
        >
            {loading ? <Loader2 aria-hidden className="size-4 animate-spin" /> : null}
            {children}
        </button>
    );
}
