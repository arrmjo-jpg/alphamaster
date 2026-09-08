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
    return (
        <div
            aria-label={label}
            className={cn(
                'inline-flex items-center gap-0.5 bg-(--action-secondary) p-0.5',
                className,
            )}
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
                                ? 'bg-(--surface-default) text-(--text-primary)'
                                : 'text-(--text-secondary) hover:text-(--text-primary)',
                        )}
                        key={option.value}
                        onClick={() => onChange(option.value)}
                        role="radio"
                        // Only the selected option is in the tab order; arrow keys are
                        // not reimplemented here because the group is never more than
                        // three items and each remains directly reachable.
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
