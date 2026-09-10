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
 * Some of them sit under a section now. A section is a heading with children and no
 * screen of its own — there is nothing at it to navigate to — so it renders as a
 * disclosure rather than a link, and it disappears entirely when the viewer may see
 * none of its children. Grouping is presentation and only presentation: each child
 * keeps its own address, so a bookmark saved before the section existed still opens
 * the same screen, and the router still mounts it whether or not this file ever drew
 * the parent.
 *
 * Visually it is part of the console rather than a panel beside it: the same
 * near-black surface the sign-in cover uses, in both themes, so the frame stays put
 * while the work inside it changes. The active item is marked by a filled rail on the
 * inline-start edge — the same device the rest of the product uses for state — plus
 * weight and a lighter ground. Three signals, none of them colour alone, and none of
 * them a rounded pill. A section whose child is active carries a receded rail of its
 * own, so a collapsed drawer still says where you are.
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
        <nav aria-label={t('shell.navigation')} className="flex flex-col py-3">
            <p className="px-4 pb-2 text-(--text-on-chrome-muted)" data-eyebrow>
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

function ModuleLink({
    module,
    onNavigate,
    nested = false,
}: {
    module: ModuleManifest;
    onNavigate?: () => void;
    /** A child of a section: indented to sit under its heading, in either direction. */
    nested?: boolean;
}) {
    const { t } = useTranslation();
    const Icon = module.icon;

    return (
        <NavLink
            className={({ isActive }) =>
                cn(
                    'group relative flex items-center gap-2.5 py-2 pe-3',
                    nested ? 'ps-9' : 'ps-4',
                    'text-(length:--text-base) transition-colors duration-100 ease-out',
                    isActive
                        ? 'bg-(--surface-chrome-hover) font-bold text-(--text-on-chrome)'
                        : 'font-normal text-(--text-on-chrome-muted) hover:bg-(--surface-chrome-hover) hover:text-(--text-on-chrome)',
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
                            isActive
                                ? 'bg-(--brand-on-chrome)'
                                : 'bg-transparent group-hover:bg-(--border-chrome)',
                        )}
                    />
                    <Icon aria-hidden className="size-4 shrink-0" />
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
                    'group relative flex w-full items-center gap-2.5 py-2 ps-4 pe-3 text-start',
                    'text-(length:--text-base) transition-colors duration-100 ease-out',
                    holdsActive
                        ? 'font-bold text-(--text-on-chrome)'
                        : 'font-normal text-(--text-on-chrome-muted) hover:bg-(--surface-chrome-hover) hover:text-(--text-on-chrome)',
                )}
                onClick={onToggle}
                type="button"
            >
                {/* A section is never itself the destination, so its rail is receded
                    even when a child is active: the filled rail below is the one that
                    says which screen is open. */}
                <span
                    aria-hidden
                    className={cn(
                        'absolute inset-y-0 start-0 w-(--rail-width)',
                        holdsActive
                            ? 'bg-(--border-chrome)'
                            : 'bg-transparent group-hover:bg-(--border-chrome)',
                    )}
                />
                <Icon aria-hidden className="size-4 shrink-0" />
                <span className="truncate">{label}</span>
                <ChevronDown
                    aria-hidden
                    className={cn(
                        'ms-auto size-3.5 shrink-0 transition-transform duration-100 ease-out',
                        expanded ? 'rotate-180' : '',
                    )}
                />
            </button>

            <div hidden={!expanded} id={panelId}>
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
