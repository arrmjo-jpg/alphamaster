import { Check, Minus } from 'lucide-react';

import { cn } from '@/lib/cn';

export interface CheckboxProps extends Omit<React.InputHTMLAttributes<HTMLInputElement>, 'type'> {
    /** Marks a value the operator has changed but not yet written. */
    pending?: boolean;
    /** Shown instead of a tick where the state is fixed rather than chosen. */
    locked?: boolean;
}

/**
 * A square box that is either ticked or not.
 *
 * A box rather than a sliding switch, and the reason is the same one the tokens give
 * for having no corner radius: this product's controls are rectangles, and a pill that
 * slides would be the only rounded, animated thing on the screen. The native input is
 * kept and hidden rather than replaced by a button, so the checked state, the label
 * association and the space key are the browser's rather than reimplemented.
 *
 * `pending` carries AlphaMaster's sixth state — changed, not yet written — so a matrix
 * of thirty boxes can show which three an operator has touched.
 */
export function Checkbox({ className, pending = false, locked = false, ...props }: CheckboxProps) {
    return (
        <span className={cn('relative inline-flex size-4 shrink-0', className)}>
            <input
                className="peer absolute inset-0 z-10 m-0 size-full cursor-pointer opacity-0 disabled:cursor-not-allowed"
                type="checkbox"
                {...props}
            />
            <span
                aria-hidden
                className={cn(
                    'pointer-events-none inline-flex size-4 items-center justify-center border',
                    'transition-colors duration-100 ease-out',
                    'peer-focus-visible:outline-2 peer-focus-visible:outline-offset-1 peer-focus-visible:outline-(--focus-ring)',
                    'peer-disabled:opacity-50',
                    props.checked === true
                        ? pending
                            ? 'border-(--state-pending-rail) bg-(--state-pending-rail) text-white'
                            : 'border-(--action-primary) bg-(--action-primary) text-(--action-primary-text)'
                        : cn(
                              'bg-(--surface-default) text-transparent',
                              pending
                                  ? 'border-(--state-pending-rail)'
                                  : 'border-(--border-control) peer-hover:border-(--border-focus)',
                          ),
                )}
            >
                {locked ? <Minus className="size-3" /> : <Check className="size-3" />}
            </span>
        </span>
    );
}
