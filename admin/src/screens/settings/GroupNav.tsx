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
    const groups = Object.keys(catalogue).toSorted();

    return (
        <nav aria-label={t('settings.groups')} className="flex flex-col">
            {groups.map((name) => {
                const count = catalogue[name]?.length ?? 0;
                const pending = pendingByGroup[name] ?? 0;

                return (
                    <NavLink
                        className={({ isActive }) =>
                            cn(
                                'group relative flex items-baseline justify-between gap-2 border-b border-(--border-default) py-2.5 ps-4 pe-3 last:border-b-0',
                                'transition-colors duration-100 ease-out',
                                isActive
                                    ? 'bg-(--action-secondary) font-bold text-(--text-primary)'
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
                                              ? 'bg-(--action-primary)'
                                              : 'bg-transparent group-hover:bg-(--border-strong)',
                                    )}
                                />

                                <span className="min-w-0 truncate">
                                    {t(`settings.group.${name}`, { defaultValue: name })}
                                </span>

                                <span className="shrink-0 text-(length:--text-xs) text-(--text-muted)">
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
