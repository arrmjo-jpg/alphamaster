import { useId } from 'react';

import { cn } from '@/lib/cn';

export interface FieldProps {
    label: string;
    /** Persistent guidance. A placeholder is not a substitute and never will be. */
    hint?: string;
    /** The server's message, rendered next to the field it belongs to. */
    error?: string;
    required?: boolean;
    className?: string;
    children: (props: {
        id: string;
        'aria-describedby': string | undefined;
        invalid: boolean;
    }) => React.ReactNode;
}

/**
 * Label, control, hint and error as one unit.
 *
 * The control is a render prop so the association is not optional: whatever goes
 * inside receives the id and the `aria-describedby` that points at whichever of the
 * hint and the error is present.
 */
export function Field({ label, hint, error, required = false, className, children }: FieldProps) {
    const id = useId();
    const hintId = `${id}-hint`;
    const errorId = `${id}-error`;

    const describedBy = [hint !== undefined ? hintId : null, error !== undefined ? errorId : null]
        .filter((value): value is string => value !== null)
        .join(' ');

    return (
        <div className={cn('flex flex-col gap-1', className)}>
            <label
                className="text-(length:--text-sm) font-medium text-(--text-secondary)"
                htmlFor={id}
            >
                {label}
                {required ? (
                    <span aria-hidden className="ms-1 text-(--state-danger-rail)">
                        *
                    </span>
                ) : null}
            </label>

            {children({
                id,
                'aria-describedby': describedBy === '' ? undefined : describedBy,
                invalid: error !== undefined,
            })}

            {hint !== undefined ? (
                <p className="text-(length:--text-sm) text-(--text-muted)" id={hintId}>
                    {hint}
                </p>
            ) : null}

            {error !== undefined ? (
                <p
                    className="text-(length:--text-sm) text-(--text-danger)"
                    id={errorId}
                    role="alert"
                >
                    {error}
                </p>
            ) : null}
        </div>
    );
}
