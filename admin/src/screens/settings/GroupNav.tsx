import { useTranslation } from 'react-i18next';
import { NavLink } from 'react-router';

import { cn } from '@/lib/cn';

import type { SettingDefinition } from './api';

export interface GroupNavProps {
    catalogue: Record<string, SettingDefinition[]>;
    /** Bare keys with unsaved edits, by group, so a group can say it has work in it. */
    pendingByGroup: Record<string, number>;
    onNavigate?: () => void;
}

/**
 * The order an operator reads them in, rather than the alphabet's: what the platform
 * is, then how it looks, then who may enter, then the services behind it, and the
 * operational limits last. A group the platform adds later still appears — after
 * these, in its own alphabetical place — because a list that silently drops a group
 * is worse than one in an unexpected order.
 */
const GROUP_ORDER = [
    'general',
    'branding',
    'auth',
    'ai',
    'mail',
    'localization',
    'security',
    'rate_limit',
    'operations',
    'cdn',
];

/**
 * The groups, and what is in each of them.
 *
 * A list of eight bare names tells an operator nothing they did not already know. The
 * count says how much is inside; the pending marker says where their unsaved work is,
 * which is the one thing that is easy to lose by navigating away and the reason this
 * carries a rail rather than a dot.
 *
 * The active group is unmistakable: filled ground, bold weight and a brand rail — the
 * same three signals the console's own navigation uses, so the pattern is learned
 * once.
 */
export function GroupNav({ catalogue, pendingByGroup, onNavigate }: GroupNavProps) {
    const { t } = useTranslation();
    const groups = Object.keys(catalogue).toSorted((first, second) => {
        const left = GROUP_ORDER.indexOf(first);
        const right = GROUP_ORDER.indexOf(second);

        if (left === -1 && right === -1) {
            return first.localeCompare(second);
        }

        if (left === -1) {
            return 1;
        }

        if (right === -1) {
            return -1;
        }

        return left - right;
    });

    return (
        <nav aria-label={t('settings.groups')} className="flex flex-col">
            {groups.map((name) => {
                const count = catalogue[name]?.length ?? 0;
                const pending = pendingByGroup[name] ?? 0;

                return (
                    <NavLink
                        className={({ isActive }) =>
                            cn(
                                'group relative flex items-baseline justify-between gap-2 border-b border-(--border-subtle) py-2.5 ps-4 pe-3 last:border-b-0',
                                'transition-colors duration-100 ease-out',
                                isActive
                                    ? 'bg-(--nav-active-bg) font-bold text-(--nav-active-text)'
                                    : 'text-(--text-secondary) hover:bg-(--action-ghost-hover) hover:text-(--text-primary)',
                            )
                        }
                        key={name}
                        onClick={onNavigate}
                        to={`/settings/${name}`}
                    >
                        {({ isActive }) => (
                            <>
                                <span
                                    aria-hidden
                                    className={cn(
                                        'absolute inset-y-0 start-0 w-(--rail-width)',
                                        pending > 0
                                            ? 'bg-(--state-pending-rail)'
                                            : isActive
                                              ? 'bg-(--nav-active-rail)'
                                              : 'bg-transparent group-hover:bg-(--border-strong)',
                                    )}
                                />

                                <span className="min-w-0 truncate">
                                    {t(`settings.group.${name}`, { defaultValue: name })}
                                </span>

                                <span
                                    className={cn(
                                        'shrink-0 text-(length:--text-xs)',
                                        // Muted grey on the brand fill would not be
                                        // read; the count takes the label's colour.
                                        isActive
                                            ? 'text-(--nav-active-text)'
                                            : 'text-(--text-muted)',
                                    )}
                                >
                                    {pending > 0
                                        ? t('settings.pendingCount', { count: pending })
                                        : count}
                                </span>
                            </>
                        )}
                    </NavLink>
                );
            })}
        </nav>
    );
}
