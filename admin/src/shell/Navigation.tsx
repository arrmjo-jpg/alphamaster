import { ChevronDown } from 'lucide-react';
import { useEffect, useId, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { NavLink, useLocation } from 'react-router';

import { cn } from '@/lib/cn';
import { navigationTree, type ModuleGroup, type ModuleManifest } from '@/modules/registry';

export interface NavigationProps {
    /** The signed-in account's grants, from `/auth/me`. */
    permissions: readonly string[];
    /** Called after a navigation, so a narrow layout can close its drawer. */
    onNavigate?: () => void;
    modules?: readonly ModuleManifest[];
    groups?: readonly ModuleGroup[];
}

/** Whether a path is the one being viewed, or owns the subtree it is in. */
function claims(path: string, pathname: string): boolean {
    return pathname === path || pathname.startsWith(`${path}/`);
}

/**
 * The navigation, built from the registry rather than written out.
 *
 * A module appears here because it declared a manifest, and disappears because the
 * signed-in account lacks the permission it named. Nothing in this file knows what
 * any module is.
 *
 * Some of them sit under a section. A section is a heading with children and no
 * screen of its own — there is nothing at it to navigate to — so it renders as a
 * disclosure rather than a link, and it disappears entirely when the viewer may see
 * none of its children. Grouping is presentation and only presentation: each child
 * keeps its own address, so a bookmark still opens the same screen.
 *
 * Items are blocks inset from the sidebar's edges rather than full-bleed rows, so the
 * active destination reads as a shape and not only as a colour change. Three states,
 * each more than a colour:
 *
 *   resting   secondary text and a muted icon
 *   hover     a faint brand wash — the item is answering the pointer
 *   active    filled with the brand, in white and bold, with a darker brand edge
 *             on the inline-start side — the one solid block in the sidebar, so it
 *             is the first thing the eye lands on, and it reads by shape and weight
 *             as well as colour
 *
 * A section whose child is active carries full-strength text of its own, so a
 * collapsed drawer still says where you are. Children hang from a guide line, which is
 * what makes a section read as a structure rather than as indented text.
 */
export function Navigation({ permissions, onNavigate, modules, groups }: NavigationProps) {
    const { t } = useTranslation();
    const { pathname } = useLocation();
    const entries = navigationTree(permissions, modules, groups);

    /** The section the current address belongs to, if any. */
    const activeGroup =
        entries.find(
            (entry) =>
                entry.kind === 'group' &&
                entry.children.some((child) => claims(child.path, pathname)),
        ) ?? null;

    const activeGroupId = activeGroup?.kind === 'group' ? activeGroup.group.id : null;

    // Open because you are inside it, or because you opened it. Arriving at a child
    // by a direct URL opens its section, which is the only way the active item can be
    // visible for someone who typed the address rather than clicked to it.
    const [open, setOpen] = useState<Record<string, boolean>>(() =>
        activeGroupId === null ? {} : { [activeGroupId]: true },
    );

    useEffect(() => {
        if (activeGroupId !== null) {
            setOpen((current) => ({ ...current, [activeGroupId]: true }));
        }
    }, [activeGroupId]);

    return (
        <nav aria-label={t('shell.navigation')} className="flex flex-col gap-0.5 px-3 py-5">
            <p className="px-3 pb-2" data-eyebrow>
                {t('shell.navigation')}
            </p>

            {entries.map((entry) =>
                entry.kind === 'module' ? (
                    <ModuleLink
                        key={entry.module.id}
                        module={entry.module}
                        {...(onNavigate === undefined ? {} : { onNavigate })}
                    />
                ) : (
                    <Group
                        expanded={open[entry.group.id] === true}
                        group={entry.group}
                        holdsActive={activeGroupId === entry.group.id}
                        key={entry.group.id}
                        modules={entry.children}
                        onToggle={() =>
                            setOpen((current) => ({
                                ...current,
                                [entry.group.id]: current[entry.group.id] !== true,
                            }))
                        }
                        {...(onNavigate === undefined ? {} : { onNavigate })}
                    />
                ),
            )}
        </nav>
    );
}

const ITEM = cn(
    'group relative flex w-full items-center gap-3 text-start',
    'text-(length:--text-base) transition-colors duration-100 ease-out',
);

const RESTING =
    'font-medium text-(--shell-text-muted) hover:bg-(--shell-hover) hover:text-(--shell-text)';

function ModuleLink({
    module,
    onNavigate,
    nested = false,
}: {
    module: ModuleManifest;
    onNavigate?: () => void;
    /** A child of a section: indented past the guide line, in either direction. */
    nested?: boolean;
}) {
    const { t } = useTranslation();
    const Icon = module.icon;

    return (
        <NavLink
            className={({ isActive }) =>
                cn(
                    ITEM,
                    nested ? 'h-9 ps-10 pe-3' : 'h-10 px-3',
                    isActive ? 'bg-(--nav-active-bg) font-bold text-(--nav-active-text)' : RESTING,
                )
            }
            onClick={onNavigate}
            to={module.path}
        >
            {({ isActive }) => (
                <>
                    <span
                        aria-hidden
                        className={cn(
                            'absolute inset-y-0 start-0 w-(--rail-width)',
                            isActive ? 'bg-(--nav-active-rail)' : 'bg-transparent',
                        )}
                    />
                    <Icon
                        aria-hidden
                        className={cn(
                            'size-4 shrink-0',
                            isActive
                                ? 'text-(--nav-active-text)'
                                : 'text-(--text-muted) group-hover:text-(--shell-text)',
                        )}
                    />
                    <span className="truncate">{t(module.label)}</span>
                </>
            )}
        </NavLink>
    );
}

function Group({
    group,
    modules,
    expanded,
    holdsActive,
    onToggle,
    onNavigate,
}: {
    group: ModuleGroup;
    modules: readonly ModuleManifest[];
    expanded: boolean;
    /** Whether the address being viewed is one of this section's children. */
    holdsActive: boolean;
    onToggle: () => void;
    onNavigate?: () => void;
}) {
    const { t } = useTranslation();
    const Icon = group.icon;
    const panelId = useId();
    const label = t(group.label);

    return (
        <>
            <button
                aria-controls={panelId}
                aria-expanded={expanded}
                aria-label={t(expanded ? 'shell.collapseGroup' : 'shell.expandGroup', {
                    group: label,
                })}
                className={cn(
                    ITEM,
                    'h-10 px-3',
                    // A section is never itself the destination, so it takes no wash
                    // even when a child is active: the filled child below is the one
                    // that says which screen is open. Full-strength text is enough to
                    // say the section holds it.
                    holdsActive
                        ? 'font-bold text-(--shell-text) hover:bg-(--shell-hover)'
                        : RESTING,
                )}
                onClick={onToggle}
                type="button"
            >
                <Icon
                    aria-hidden
                    className={cn(
                        'size-4 shrink-0',
                        holdsActive
                            ? 'text-(--text-brand)'
                            : 'text-(--text-muted) group-hover:text-(--shell-text)',
                    )}
                />
                <span className="truncate">{label}</span>
                <ChevronDown
                    aria-hidden
                    className={cn(
                        'ms-auto size-4 shrink-0 text-(--text-muted) transition-transform duration-150 ease-out',
                        expanded ? 'rotate-180' : '',
                    )}
                />
            </button>

            <div
                className="relative flex flex-col gap-0.5 pb-1 before:absolute before:inset-y-1 before:start-5 before:w-px before:bg-(--shell-border)"
                hidden={!expanded}
                id={panelId}
            >
                {modules.map((module) => (
                    <ModuleLink
                        key={module.id}
                        module={module}
                        nested
                        {...(onNavigate === undefined ? {} : { onNavigate })}
                    />
                ))}
            </div>
        </>
    );
}
