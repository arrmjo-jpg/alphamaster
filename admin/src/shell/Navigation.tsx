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
 */
export function Navigation({ permissions, onNavigate, modules }: NavigationProps) {
    const { t } = useTranslation();
    const items = visibleModules(permissions, modules);

    return (
        <nav aria-label={t('shell.navigation')} className="flex flex-col gap-0.5 p-2">
            {items.map((module) => {
                const Icon = module.icon;

                return (
                    <NavLink
                        className={({ isActive }) =>
                            cn(
                                'flex items-center gap-2 rounded-md px-2 py-1.5',
                                'text-(length:--text-base) transition-colors duration-100 ease-out',
                                isActive
                                    ? 'bg-(--action-secondary) font-medium text-(--text-primary)'
                                    : 'text-(--text-secondary) hover:bg-(--action-ghost-hover) hover:text-(--text-primary)',
                            )
                        }
                        key={module.id}
                        onClick={onNavigate}
                        to={module.path}
                    >
                        <Icon aria-hidden className="size-4 shrink-0" />
                        <span className="truncate">{t(module.label)}</span>
                    </NavLink>
                );
            })}
        </nav>
    );
}
