import { useTranslation } from 'react-i18next';
import { NavLink } from 'react-router';

import { cn } from '@/lib/cn';
import { visibleModules, type ModuleManifest } from '@/modules/registry';

export interface NavigationProps {
    /** The signed-in account's grants, from `/auth/me`. */
    permissions: readonly string[];
    /** Called after a navigation, so a narrow layout can close its drawer. */
    onNavigate?: () => void;
    modules?: readonly ModuleManifest[];
}

/**
 * The navigation, built from the registry rather than written out.
 *
 * A module appears here because it declared a manifest, and disappears because the
 * signed-in account lacks the permission it named. Nothing in this file knows what
 * any module is.
 *
 * Visually it is part of the console rather than a panel beside it: the same
 * near-black surface the sign-in cover uses, in both themes, so the frame stays put
 * while the work inside it changes. The active item is marked by a filled rail on the
 * inline-start edge — the same device the rest of the product uses for state — plus
 * weight and a lighter ground. Three signals, none of them colour alone, and none of
 * them a rounded pill.
 */
export function Navigation({ permissions, onNavigate, modules }: NavigationProps) {
    const { t } = useTranslation();
    const items = visibleModules(permissions, modules);

    return (
        <nav aria-label={t('shell.navigation')} className="flex flex-col py-3">
            <p className="px-4 pb-2 text-(--text-on-chrome-muted)" data-eyebrow>
                {t('shell.navigation')}
            </p>

            {items.map((module) => {
                const Icon = module.icon;

                return (
                    <NavLink
                        className={({ isActive }) =>
                            cn(
                                'group relative flex items-center gap-2.5 py-2 ps-4 pe-3',
                                'text-(length:--text-base) transition-colors duration-100 ease-out',
                                isActive
                                    ? 'bg-(--surface-chrome-hover) font-bold text-(--text-on-chrome)'
                                    : 'font-normal text-(--text-on-chrome-muted) hover:bg-(--surface-chrome-hover) hover:text-(--text-on-chrome)',
                            )
                        }
                        key={module.id}
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
                                            ? 'bg-(--action-primary)'
                                            : 'bg-transparent group-hover:bg-(--border-chrome)',
                                    )}
                                />
                                <Icon aria-hidden className="size-4 shrink-0" />
                                <span className="truncate">{t(module.label)}</span>
                            </>
                        )}
                    </NavLink>
                );
            })}
        </nav>
    );
}
