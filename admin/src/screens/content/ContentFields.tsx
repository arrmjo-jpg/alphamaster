import type { TextareaHTMLAttributes } from 'react';

import { cn } from '@/lib/cn';
import { Field } from '@/ui/Field';

/**
 * A multi-line text field in a content editor, labelled and described like every other
 * field (ADR 0055 §8).
 *
 * `dir` and `lang` are the content language's, never the console's: an Arabic biography is
 * written right to left in an English console.
 */
export function ContentTextArea({
    label,
    hint,
    value,
    onChange,
    rows = 4,
    className,
    ...rest
}: {
    label: string;
    hint?: string;
    value: string;
    onChange: (value: string) => void;
    rows?: number;
    className?: string;
} & Omit<TextareaHTMLAttributes<HTMLTextAreaElement>, 'onChange' | 'value' | 'className'>) {
    return (
        <Field label={label} {...(hint === undefined ? {} : { hint })}>
            {({ id, 'aria-describedby': describedBy }) => (
                <textarea
                    {...rest}
                    aria-describedby={describedBy}
                    className={cn(
                        'w-full border border-(--border-strong) bg-(--surface-default) p-2 text-(length:--text-sm) text-(--text-primary) focus-visible:outline-2 focus-visible:outline-offset-0 focus-visible:outline-(--focus-ring) disabled:cursor-not-allowed disabled:opacity-50',
                        className,
                    )}
                    id={id}
                    onChange={(event) => onChange(event.target.value)}
                    rows={rows}
                    value={value}
                />
            )}
        </Field>
    );
}
