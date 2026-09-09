import { useQuery } from '@tanstack/react-query';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';

import { ApiError } from '@/api/errors';
import { useCurrentUser } from '@/auth/AuthProvider';
import { cn } from '@/lib/cn';
import { permissions as fetchPermissions, roles as fetchRoles } from '@/screens/access/api';

/**
 * The permission control surface: what each role carries, grouped as the platform
 * groups it.
 *
 * Not a wall of badges. A role with twenty permissions rendered as twenty chips is a
 * shape, not information — so permissions are laid out by the module they belong to,
 * with the ones this role holds set in the foreground and the rest left visible but
 * receded. Reading down a module tells an operator both what was granted and what was
 * withheld, which is the question they actually have.
 *
 * The grouping is the API's. Inventing a different one would be a second taxonomy for
 * the same set, and the two would disagree the first time a permission moved.
 */
export function RolesScreen() {
    const { t } = useTranslation();
    const viewer = useCurrentUser();
    const [selected, setSelected] = useState<string | null>(null);

    const roles = useQuery({
        queryKey: ['admin-roles'],
        queryFn: ({ signal }) => fetchRoles(signal),
    });

    const mayReadPermissions = viewer.permissions.includes('permissions.view');

    const catalogue = useQuery({
        queryKey: ['admin-permissions'],
        queryFn: ({ signal }) => fetchPermissions(signal),
        // A request that would certainly be refused is not sent. The API is still the
        // boundary; this only avoids rendering an error where nothing is wrong.
        enabled: mayReadPermissions,
    });

    if (roles.isPending) {
        return <p className="text-(--text-muted)">{t('state.loading')}</p>;
    }

    if (roles.error !== null) {
        return (
            <p className="text-(--text-danger)">
                {roles.error instanceof ApiError ? roles.error.message : t('state.error')}
            </p>
        );
    }

    const list = roles.data ?? [];
    const active = list.find((role) => role.name === selected) ?? list[0];

    return (
        <div className="flex flex-col gap-(--section-gap)">
            <header>
                <p data-eyebrow>{t('access.eyebrow')}</p>
                <h1 className="text-(length:--text-2xl) text-(--text-primary)">
                    {t('modules.roles')}
                </h1>
            </header>

            <div className="grid min-h-0 grid-cols-1 border border-(--border-default) bg-(--surface-default) lg:grid-cols-[240px_1fr]">
                <nav
                    aria-label={t('modules.roles')}
                    className="border-b border-(--border-default) lg:border-b-0 lg:border-e"
                >
                    {list.map((role) => {
                        const isActive = active?.name === role.name;

                        return (
                            <button
                                className={cn(
                                    'relative flex w-full items-baseline justify-between gap-2 border-b border-(--border-default) py-2.5 ps-4 pe-3 text-start last:border-b-0',
                                    isActive
                                        ? 'bg-(--action-secondary) font-bold text-(--text-primary)'
                                        : 'text-(--text-secondary) hover:bg-(--action-ghost-hover)',
                                )}
                                key={role.id}
                                onClick={() => setSelected(role.name)}
                                type="button"
                            >
                                <span
                                    aria-hidden
                                    className={cn(
                                        'absolute inset-y-0 start-0 w-(--rail-width)',
                                        isActive ? 'bg-(--action-primary)' : 'bg-transparent',
                                    )}
                                />
                                <span className="min-w-0 truncate">{role.name_label}</span>
                                <span className="shrink-0 text-(length:--text-xs) text-(--text-muted)">
                                    {role.permissions.length}
                                </span>
                            </button>
                        );
                    })}
                </nav>

                <div className="min-w-0 p-4">
                    {active === undefined ? (
                        <p className="text-(--text-muted)">{t('access.noRoles')}</p>
                    ) : (
                        <>
                            <div className="mb-4">
                                <p data-eyebrow>{t('access.role')}</p>
                                <h2 className="text-(length:--text-xl) text-(--text-primary)">
                                    {active.name_label}
                                </h2>
                                <p
                                    className="text-(length:--text-xs) text-(--text-muted)"
                                    data-technical
                                >
                                    {active.name}
                                </p>
                            </div>

                            {!mayReadPermissions ? (
                                <p className="text-(--text-muted)">
                                    {t('access.permissionsHidden')}
                                </p>
                            ) : catalogue.isPending ? (
                                <p className="text-(--text-muted)">{t('state.loading')}</p>
                            ) : (
                                <div className="flex flex-col gap-4">
                                    {Object.entries(catalogue.data ?? {}).map(
                                        ([module, entries]) => (
                                            <section key={module}>
                                                <h3
                                                    className="border-b border-(--border-default) pb-1"
                                                    data-eyebrow
                                                >
                                                    {module}
                                                </h3>
                                                <ul className="mt-1.5 flex flex-col gap-0.5">
                                                    {entries.map((permission) => {
                                                        const granted = active.permissions.includes(
                                                            permission.key,
                                                        );

                                                        return (
                                                            <li
                                                                className="flex items-baseline gap-2"
                                                                key={permission.key}
                                                            >
                                                                {/* A held permission is set
                                                                in the foreground; the
                                                                rest stay visible so the
                                                                gaps are readable too. */}
                                                                <span
                                                                    aria-hidden
                                                                    className={cn(
                                                                        'mt-1.5 size-1.5 shrink-0',
                                                                        granted
                                                                            ? 'bg-(--action-primary)'
                                                                            : 'bg-(--border-strong)',
                                                                    )}
                                                                />
                                                                <span
                                                                    className={cn(
                                                                        'text-(length:--text-sm)',
                                                                        granted
                                                                            ? 'font-medium text-(--text-primary)'
                                                                            : 'text-(--text-muted)',
                                                                    )}
                                                                >
                                                                    {permission.label}
                                                                </span>
                                                                <span
                                                                    className="text-(length:--text-xs) text-(--text-muted)"
                                                                    data-technical
                                                                >
                                                                    {permission.key}
                                                                </span>
                                                                <span className="sr-only">
                                                                    {granted
                                                                        ? t('access.granted')
                                                                        : t('access.notGranted')}
                                                                </span>
                                                            </li>
                                                        );
                                                    })}
                                                </ul>
                                            </section>
                                        ),
                                    )}
                                </div>
                            )}
                        </>
                    )}
                </div>
            </div>
        </div>
    );
}
