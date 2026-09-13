import { Check, ChevronDown } from 'lucide-react';
import { useCallback, useEffect, useId, useMemo, useRef, useState } from 'react';

import { cn } from '@/lib/cn';

/**
 * A trigger and the panel it opens.
 *
 * This exists because the console's chrome had run out of room. A set of mutually
 * exclusive choices that is small and stable belongs in a `SegmentedControl`, where
 * every option is visible and costs one click — which is why that component is still
 * what the screens use. The top bar is the case it does not fit: the choices there are
 * preferences rather than work, several sets of them sit side by side, and one of the
 * sets is the language list, which is data and can be any length. Laid out flat they
 * became a row of small adjacent buttons that read as a control panel, and the row
 * grew with the platform's language table.
 *
 * So the rule the two divide on is not size, it is whose screen it is: options that
 * belong to the task stay visible, options that belong to the viewer fold away.
 *
 * Positioning is logical rather than left/right, so the panel hangs from the same edge
 * as its trigger in both directions and needs no RTL special case. It is clamped to
 * the viewport on both axes: a menu that opens past the edge of a 375px screen is the
 * defect this would otherwise introduce on the smallest layout, where there is the
 * least room and the most reason to open one.
 */

export interface MenuProps {
    /** The trigger's accessible name. Never a literal — an i18n key's result. */
    label: string;
    /** What the trigger shows. Usually an icon and, above `sm`, a word. */
    trigger: React.ReactNode;
    children: React.ReactNode;
    /** Which edge of the trigger the panel hangs from. Logical, not physical. */
    align?: 'start' | 'end';
    /**
     * Which ground the trigger sits on.
     *
     * `chrome` is the near-black frame — the top bar. `surface` is an ordinary panel,
     * which is where the sign-in cover puts its language switcher. The panel itself is
     * the same in both cases; only the trigger has to match what is behind it, and
     * getting this wrong is the kind of error that is invisible in one theme.
     */
    tone?: 'chrome' | 'surface';
    className?: string;
    panelClassName?: string;
}

const TRIGGER_TONES: Record<'chrome' | 'surface', string> = {
    chrome: cn('text-(--shell-text-muted)', 'hover:bg-(--shell-hover) hover:text-(--shell-text)'),
    surface: cn(
        'border border-(--border-control) text-(--text-secondary)',
        'hover:bg-(--action-secondary) hover:text-(--text-primary)',
    ),
};

const TRIGGER_OPEN_TONES: Record<'chrome' | 'surface', string> = {
    chrome: 'bg-(--shell-hover) text-(--shell-text)',
    surface: 'bg-(--action-secondary) text-(--text-primary)',
};

/** Everything inside a panel that behaves as one of its options. */
const ITEM_SELECTOR = '[role="menuitem"], [role="menuitemradio"]';

export function Menu({
    label,
    trigger,
    children,
    align = 'end',
    tone = 'chrome',
    className,
    panelClassName,
}: MenuProps) {
    const [open, setOpen] = useState(false);
    const wrapper = useRef<HTMLDivElement | null>(null);
    const triggerRef = useRef<HTMLButtonElement | null>(null);
    const panel = useRef<HTMLDivElement | null>(null);
    const panelId = useId();

    const close = useCallback((returnFocus = true) => {
        setOpen(false);

        // Focus goes back where it came from, or it lands on `body` and the next Tab
        // starts from the top of the document.
        if (returnFocus) {
            triggerRef.current?.focus();
        }
    }, []);

    // A press anywhere else dismisses it. `pointerdown` rather than `click` so the
    // menu is gone before the thing underneath reacts, and capture so it still fires
    // for a target that stops propagation on its own.
    useEffect(() => {
        if (!open) {
            return;
        }

        function onPointerDown(event: PointerEvent) {
            if (!wrapper.current?.contains(event.target as Node)) {
                setOpen(false);
            }
        }

        document.addEventListener('pointerdown', onPointerDown, true);

        return () => document.removeEventListener('pointerdown', onPointerDown, true);
    }, [open]);

    // Opening moves focus inside — to the filter when there is one, so typing narrows
    // a long list immediately, and otherwise to the first option.
    useEffect(() => {
        if (!open) {
            return;
        }

        const first =
            panel.current?.querySelector<HTMLElement>('input') ??
            panel.current?.querySelector<HTMLElement>(ITEM_SELECTOR);

        first?.focus();
    }, [open]);

    /** Up and down walk the options; Home and End jump. */
    function onPanelKeyDown(event: React.KeyboardEvent<HTMLDivElement>) {
        if (!['ArrowDown', 'ArrowUp', 'Home', 'End'].includes(event.key)) {
            return;
        }

        const items = Array.from(
            panel.current?.querySelectorAll<HTMLElement>(ITEM_SELECTOR) ?? [],
        ).filter((item) => item.offsetParent !== null);

        if (items.length === 0) {
            return;
        }

        event.preventDefault();

        const current = items.indexOf(document.activeElement as HTMLElement);
        const next =
            event.key === 'Home'
                ? 0
                : event.key === 'End'
                  ? items.length - 1
                  : event.key === 'ArrowDown'
                    ? (current + 1 + items.length) % items.length
                    : (current - 1 + items.length) % items.length;

        items[next]?.focus();
    }

    return (
        <div
            className={cn('relative', className)}
            onKeyDown={(event) => {
                if (event.key === 'Escape' && open) {
                    event.stopPropagation();
                    close();
                }
            }}
            ref={wrapper}
        >
            <button
                aria-controls={open ? panelId : undefined}
                aria-expanded={open}
                aria-haspopup="menu"
                aria-label={label}
                className={cn(
                    'flex h-9 items-center gap-2 px-2',
                    'text-(length:--text-sm) font-medium',
                    'transition-colors duration-100 ease-out',
                    'focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-(--focus-ring)',
                    TRIGGER_TONES[tone],
                    open ? TRIGGER_OPEN_TONES[tone] : '',
                )}
                onClick={() => (open ? close() : setOpen(true))}
                ref={triggerRef}
                type="button"
            >
                {trigger}
                <ChevronDown
                    aria-hidden
                    className={cn(
                        'size-3.5 shrink-0 transition-transform duration-100 ease-out',
                        open ? 'rotate-180' : '',
                    )}
                />
            </button>

            {open ? (
                <div
                    aria-label={label}
                    className={cn(
                        'absolute top-[calc(100%+1px)] z-(--z-overlay)',
                        align === 'end' ? 'end-0' : 'start-0',
                        'min-w-52 max-w-[calc(100vw-1rem)]',
                        'border border-(--border-default) bg-(--surface-overlay)',
                        'text-(--text-primary) shadow-(--shadow-overlay)',
                        panelClassName,
                    )}
                    id={panelId}
                    onClick={(event) => {
                        // A choice closes the menu; a click on the filter or on the
                        // panel's own padding does not.
                        if ((event.target as HTMLElement).closest(ITEM_SELECTOR) !== null) {
                            close();
                        }
                    }}
                    onKeyDown={onPanelKeyDown}
                    ref={panel}
                    role="menu"
                >
                    {children}
                </div>
            ) : null}
        </div>
    );
}

/** A heading over a run of options. Not focusable: it is a label, not a choice. */
export function MenuSectionLabel({ children }: { children: React.ReactNode }) {
    return (
        <p
            className="px-3 pt-2.5 pb-1 text-(length:--text-2xs) font-bold tracking-(--tracking-wide) text-(--text-muted) uppercase"
            data-eyebrow
        >
            {children}
        </p>
    );
}

export function MenuSeparator() {
    return <hr aria-hidden className="my-1 border-0 border-t border-(--border-default)" />;
}

const ITEM_CLASSES = cn(
    'flex w-full items-center gap-2 px-3 py-1.5 text-start',
    'text-(length:--text-base) text-(--text-primary)',
    'transition-colors duration-100 ease-out',
    'hover:bg-(--action-secondary) focus-visible:bg-(--action-secondary)',
    'focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-(--focus-ring)',
);

export interface MenuItemProps {
    onSelect: () => void;
    icon?: React.ReactNode;
    children: React.ReactNode;
    /** A destructive or terminal action, tinted as one. */
    tone?: 'default' | 'danger';
}

export function MenuItem({ onSelect, icon, children, tone = 'default' }: MenuItemProps) {
    return (
        <button
            className={cn(ITEM_CLASSES, tone === 'danger' ? 'text-(--state-danger-text)' : '')}
            onClick={onSelect}
            role="menuitem"
            type="button"
        >
            {icon !== undefined ? (
                <span aria-hidden className="inline-flex shrink-0">
                    {icon}
                </span>
            ) : null}
            <span className="truncate">{children}</span>
        </button>
    );
}

export interface MenuRadioOption<T extends string> {
    value: T;
    label: string;
    /** A short technical identifier shown beside the label, such as a language code. */
    hint?: string;
    icon?: React.ReactNode;
    /** What the filter matches on, beyond the label and the hint. */
    keywords?: string;
}

export interface MenuRadioListProps<T extends string> {
    /** Names the run of options for assistive technology. */
    label: string;
    value: T;
    options: readonly MenuRadioOption<T>[];
    onSelect: (value: T) => void;
    /** Draw the heading. Off when the panel holds a single run and is titled already. */
    showLabel?: boolean;
    /**
     * Offer a filter. Defaults to on once the list is longer than a person can scan,
     * so a set that grows from two rows to eighty gains one without an edit here.
     */
    searchable?: boolean;
    searchLabel?: string;
    searchPlaceholder?: string;
    emptyLabel?: string;
}

/** Past this many rows the list is scrolled and filtered rather than simply listed. */
export const MENU_SEARCH_THRESHOLD = 8;

/**
 * One run of mutually exclusive options inside a panel.
 *
 * The same component serves a set of three that will never grow and a set that comes
 * from a database table, which is the point: the filter and the scroll appear from the
 * length of what it was handed rather than from a decision taken per call site, so no
 * caller has to predict how large its own list will get.
 *
 * `menuitemradio` rather than `menuitem`, because these report a current state as well
 * as offering a change, and the check mark that shows which is current is announced by
 * `aria-checked` rather than being left to a sighted reader alone.
 */
export function MenuRadioList<T extends string>({
    label,
    value,
    options,
    onSelect,
    showLabel = true,
    searchable,
    searchLabel,
    searchPlaceholder,
    emptyLabel,
}: MenuRadioListProps<T>) {
    const [query, setQuery] = useState('');
    const filtering = searchable ?? options.length > MENU_SEARCH_THRESHOLD;

    const matches = useMemo(() => {
        const needle = query.trim().toLocaleLowerCase();

        if (!filtering || needle === '') {
            return options;
        }

        return options.filter((option) =>
            [option.label, option.hint, option.keywords, option.value]
                .filter((part): part is string => part !== undefined)
                .some((part) => part.toLocaleLowerCase().includes(needle)),
        );
    }, [filtering, options, query]);

    return (
        <div aria-label={label} role="group">
            {showLabel ? <MenuSectionLabel>{label}</MenuSectionLabel> : null}

            {filtering ? (
                <div className="px-2 pt-1 pb-2">
                    <input
                        aria-label={searchLabel ?? label}
                        className={cn(
                            'h-(--field-height) w-full border border-(--border-control) px-2.5',
                            'bg-(--surface-input) text-(length:--text-base) text-(--text-primary)',
                            'focus-visible:outline-2 focus-visible:outline-offset-0 focus-visible:outline-(--focus-ring)',
                        )}
                        onChange={(event) => setQuery(event.target.value)}
                        placeholder={searchPlaceholder}
                        type="search"
                        value={query}
                    />
                </div>
            ) : null}

            {/* Bounded and scrolled, so eighty languages is a scroll rather than a
                panel taller than the window. */}
            <div className={cn(filtering ? 'max-h-[min(50dvh,18rem)] overflow-y-auto' : '')}>
                {matches.length === 0 ? (
                    <p className="px-3 py-2 text-(length:--text-sm) text-(--text-muted)">
                        {emptyLabel}
                    </p>
                ) : (
                    matches.map((option) => {
                        const selected = option.value === value;

                        return (
                            <button
                                aria-checked={selected}
                                className={cn(ITEM_CLASSES, selected ? 'font-bold' : '')}
                                key={option.value}
                                onClick={() => onSelect(option.value)}
                                role="menuitemradio"
                                type="button"
                            >
                                <Check
                                    aria-hidden
                                    className={cn(
                                        'size-3.5 shrink-0',
                                        selected ? 'text-(--action-primary)' : 'invisible',
                                    )}
                                />
                                {option.icon !== undefined ? (
                                    <span aria-hidden className="inline-flex shrink-0">
                                        {option.icon}
                                    </span>
                                ) : null}
                                <span className="truncate">{option.label}</span>
                                {option.hint !== undefined ? (
                                    <span
                                        className="ms-auto shrink-0 text-(length:--text-2xs) text-(--text-muted)"
                                        data-technical
                                    >
                                        {option.hint}
                                    </span>
                                ) : null}
                            </button>
                        );
                    })
                )}
            </div>
        </div>
    );
}
