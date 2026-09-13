import { CornerDownLeft, Search } from 'lucide-react';
import { useCallback, useEffect, useId, useMemo, useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { useNavigate } from 'react-router';

import { useCurrentUser } from '@/auth/AuthProvider';
import { cn } from '@/lib/cn';
import { MODULE_GROUPS, visibleModules, type ModuleManifest } from '@/modules/registry';

/**
 * "Go to…" — the top bar's search, and exactly as wide as it claims.
 *
 * It searches the console's own destinations: the modules the signed-in account may
 * open, drawn from the same permission-filtered registry the navigation renders. It
 * does not search accounts, settings values or audit records, and its label says
 * "go to" rather than "search" so it cannot be mistaken for something that does —
 * the platform publishes no cross-entity search endpoint, and a search box that
 * quietly only matched page names would be a promise broken on the first query.
 *
 * What it buys is the thing a long navigation costs: an operator who knows where they
 * are going types three letters instead of opening a section. Ctrl+K (⌘K) opens it
 * from anywhere, which is the convention every console of this kind has taught.
 *
 * A dialog, not a menu: it takes focus, holds it, and gives it back on close. The
 * options are a listbox driven from the input with `aria-activedescendant`, so the
 * caret never leaves the field while the arrow keys move the selection.
 */
export function CommandPalette() {
    const { t } = useTranslation();
    const user = useCurrentUser();
    const navigate = useNavigate();

    const [open, setOpen] = useState(false);
    const [query, setQuery] = useState('');
    const [active, setActive] = useState(0);

    const trigger = useRef<HTMLButtonElement | null>(null);
    const input = useRef<HTMLInputElement | null>(null);
    const options = useRef<Map<number, HTMLLIElement>>(new Map());
    const listId = useId();
    const optionId = (index: number) => `${listId}-option-${index}`;

    // Section names beside each destination, so "Users" reads "Users & permissions"
    // and two modules that share a word are still told apart.
    const sections = useMemo(() => new Map(MODULE_GROUPS.map((group) => [group.id, group])), []);

    const destinations = useMemo(
        () =>
            visibleModules(user.permissions).map((module) => {
                const group = module.group === undefined ? undefined : sections.get(module.group);

                return {
                    module,
                    label: t(module.label),
                    section: group === undefined ? null : t(group.label),
                };
            }),
        [sections, t, user.permissions],
    );

    const matches = useMemo(() => {
        const needle = query.trim().toLocaleLowerCase();

        if (needle === '') {
            return destinations;
        }

        return destinations.filter((destination) =>
            [destination.label, destination.section, destination.module.path]
                .filter((part): part is string => part !== null)
                .some((part) => part.toLocaleLowerCase().includes(needle)),
        );
    }, [destinations, query]);

    const close = useCallback(() => {
        setOpen(false);
        trigger.current?.focus();
    }, []);

    const go = useCallback(
        (module: ModuleManifest) => {
            setOpen(false);
            void navigate(module.path);
        },
        [navigate],
    );

    // Ctrl+K on Windows and Linux, ⌘K on a Mac, from anywhere in the console.
    useEffect(() => {
        function onKeyDown(event: KeyboardEvent) {
            if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'k') {
                event.preventDefault();
                setOpen((current) => !current);
            }
        }

        document.addEventListener('keydown', onKeyDown);

        return () => document.removeEventListener('keydown', onKeyDown);
    }, []);

    // A fresh query every time it opens: yesterday's half-typed filter hiding the
    // destination somebody wants now is a small, very confusing failure.
    useEffect(() => {
        if (open) {
            setQuery('');
            setActive(0);
            input.current?.focus();
        }
    }, [open]);

    // The highlighted row stays in view as the arrows move it. Guarded, because not
    // every environment implements scrolling an element into view.
    useEffect(() => {
        options.current.get(active)?.scrollIntoView?.({ block: 'nearest' });
    }, [active]);

    function onInputKeyDown(event: React.KeyboardEvent<HTMLInputElement>) {
        if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
            event.preventDefault();

            if (matches.length === 0) {
                return;
            }

            const step = event.key === 'ArrowDown' ? 1 : -1;
            setActive((current) => (current + step + matches.length) % matches.length);

            return;
        }

        if (event.key === 'Enter') {
            event.preventDefault();
            const chosen = matches[active];

            if (chosen !== undefined) {
                go(chosen.module);
            }

            return;
        }

        if (event.key === 'Escape') {
            event.preventDefault();
            close();

            return;
        }

        // The input is the only focusable thing in the dialog, so Tab would carry
        // focus out of a modal that is still open. Keep it where it is.
        if (event.key === 'Tab') {
            event.preventDefault();
        }
    }

    return (
        <>
            <button
                aria-haspopup="dialog"
                aria-label={t('command.open')}
                className={cn(
                    'flex h-9 min-w-0 items-center gap-2 border border-(--border-default) bg-(--surface-subtle) px-2.5',
                    'text-(length:--text-sm) text-(--text-muted)',
                    'transition-colors duration-100 ease-out',
                    'hover:border-(--border-control) hover:text-(--text-secondary)',
                    'sm:w-64 lg:w-80',
                )}
                onClick={() => setOpen(true)}
                ref={trigger}
                type="button"
            >
                <Search aria-hidden className="size-4 shrink-0" />
                <span className="hidden truncate sm:inline">{t('command.placeholder')}</span>
                <kbd
                    className="ms-auto hidden shrink-0 border border-(--border-default) bg-(--surface-default) px-1.5 py-0.5 font-sans text-(length:--text-2xs) text-(--text-muted) md:inline"
                    data-technical
                >
                    {t('command.shortcut')}
                </kbd>
            </button>

            {open ? (
                <div className="fixed inset-0 z-(--z-toast) flex items-start justify-center px-4 pt-[12vh]">
                    <button
                        aria-label={t('command.close')}
                        className="absolute inset-0 bg-(--slate-950)/45"
                        onClick={close}
                        tabIndex={-1}
                        type="button"
                    />

                    <div
                        aria-label={t('command.label')}
                        aria-modal="true"
                        className="relative flex w-full max-w-xl flex-col border border-(--border-default) bg-(--surface-overlay) shadow-(--shadow-overlay)"
                        role="dialog"
                    >
                        <div className="flex items-center gap-2.5 border-b border-(--border-default) px-4">
                            <Search aria-hidden className="size-4 shrink-0 text-(--text-muted)" />
                            <input
                                aria-activedescendant={
                                    matches.length > 0 ? optionId(active) : undefined
                                }
                                aria-autocomplete="list"
                                aria-controls={listId}
                                aria-expanded="true"
                                aria-label={t('command.label')}
                                className="h-12 min-w-0 flex-1 bg-transparent text-(length:--text-md) text-(--text-primary) outline-none placeholder:text-(--text-muted)"
                                onChange={(event) => {
                                    setQuery(event.target.value);
                                    setActive(0);
                                }}
                                onKeyDown={onInputKeyDown}
                                placeholder={t('command.placeholder')}
                                ref={input}
                                role="combobox"
                                type="text"
                                value={query}
                            />
                        </div>

                        <ul
                            aria-label={t('command.label')}
                            className="max-h-[min(50dvh,22rem)] overflow-y-auto py-1.5"
                            id={listId}
                            role="listbox"
                        >
                            {matches.length === 0 ? (
                                <li className="px-4 py-6 text-center text-(--text-muted)">
                                    {t('command.empty')}
                                </li>
                            ) : (
                                matches.map((destination, index) => {
                                    const Icon = destination.module.icon;
                                    const selected = index === active;

                                    return (
                                        <li
                                            aria-selected={selected}
                                            className={cn(
                                                'relative mx-1.5 flex cursor-pointer items-center gap-3 px-3 py-2',
                                                selected
                                                    ? 'bg-(--action-primary-subtle) text-(--text-brand)'
                                                    : 'text-(--text-primary)',
                                            )}
                                            id={optionId(index)}
                                            key={destination.module.id}
                                            onClick={() => go(destination.module)}
                                            onMouseEnter={() => setActive(index)}
                                            ref={(element) => {
                                                if (element === null) {
                                                    options.current.delete(index);
                                                } else {
                                                    options.current.set(index, element);
                                                }
                                            }}
                                            role="option"
                                        >
                                            {selected ? (
                                                <span
                                                    aria-hidden
                                                    className="absolute inset-y-1 start-0 w-(--rail-width) bg-(--action-primary)"
                                                />
                                            ) : null}
                                            <Icon aria-hidden className="size-4 shrink-0" />
                                            <span className="min-w-0 truncate font-medium">
                                                {destination.label}
                                            </span>
                                            {destination.section !== null ? (
                                                <span className="ms-auto shrink-0 truncate text-(length:--text-xs) text-(--text-muted)">
                                                    {destination.section}
                                                </span>
                                            ) : null}
                                        </li>
                                    );
                                })
                            )}
                        </ul>

                        <div className="flex items-center gap-2 border-t border-(--border-default) bg-(--surface-subtle) px-4 py-2 text-(length:--text-xs) text-(--text-muted)">
                            <CornerDownLeft aria-hidden className="size-3.5" />
                            <span>{t('command.hint')}</span>
                        </div>
                    </div>
                </div>
            ) : null}
        </>
    );
}
