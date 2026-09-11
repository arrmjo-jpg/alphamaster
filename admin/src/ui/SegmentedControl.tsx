import { cn } from '@/lib/cn';

export interface SegmentedOption<T extends string> {
    value: T;
    label: string;
    icon?: React.ReactNode;
}

export interface SegmentedControlProps<T extends string> {
    /** Names the group for assistive technology; the visible label may be elsewhere. */
    label: string;
    value: T;
    options: SegmentedOption<T>[];
    onChange: (value: T) => void;
    className?: string;
}

/**
 * A small set of mutually exclusive choices, all of them visible.
 *
 * Radio semantics rather than buttons, because that is what this is: choosing one
 * changes a setting, it does not perform an action.
 */
export function SegmentedControl<T extends string>({
    label,
    value,
    options,
    onChange,
    className,
}: SegmentedControlProps<T>) {
    // A radio group moves with the arrow keys (WAI-ARIA radio group pattern): only the
    // selected option is in the tab order, so without this every other option would be
    // unreachable from the keyboard. Left and right follow the reading direction.
    const onKeyDown = (event: React.KeyboardEvent<HTMLDivElement>) => {
        const rtl = document.documentElement.dir === 'rtl';
        const forward = ['ArrowDown', rtl ? 'ArrowLeft' : 'ArrowRight'];
        const backward = ['ArrowUp', rtl ? 'ArrowRight' : 'ArrowLeft'];

        if (!forward.includes(event.key) && !backward.includes(event.key)) {
            return;
        }

        event.preventDefault();

        const current = options.findIndex((option) => option.value === value);
        const step = forward.includes(event.key) ? 1 : -1;
        const nextIndex = (current + step + options.length) % options.length;
        const next = options[nextIndex];

        if (next === undefined) {
            return;
        }

        onChange(next.value);

        const buttons = event.currentTarget.querySelectorAll<HTMLElement>('[role="radio"]');
        buttons[nextIndex]?.focus();
    };

    return (
        <div
            aria-label={label}
            className={cn(
                'inline-flex flex-wrap items-center gap-0.5 bg-(--action-secondary) p-0.5',
                className,
            )}
            onKeyDown={onKeyDown}
            role="radiogroup"
        >
            {options.map((option) => {
                const selected = option.value === value;

                return (
                    <button
                        aria-checked={selected}
                        className={cn(
                            'inline-flex items-center gap-1.5 px-2 py-1',
                            'text-(length:--text-sm) font-medium',
                            'transition-colors duration-100 ease-out',
                            'focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-(--focus-ring)',
                            selected
                                ? 'bg-(--surface-default) text-(--text-primary) shadow-(--shadow-card)'
                                : 'text-(--text-secondary) hover:text-(--text-primary)',
                        )}
                        key={option.value}
                        onClick={() => onChange(option.value)}
                        role="radio"
                        // Only the selected option is in the tab order; the arrow keys
                        // move between the others (see onKeyDown above).
                        tabIndex={selected ? 0 : -1}
                        type="button"
                    >
                        {option.icon !== undefined ? (
                            <span aria-hidden className="inline-flex">
                                {option.icon}
                            </span>
                        ) : null}
                        {option.label}
                    </button>
                );
            })}
        </div>
    );
}
