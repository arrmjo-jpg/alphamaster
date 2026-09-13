import { useQuery } from '@tanstack/react-query';
import { Lock, Search } from 'lucide-react';
import { useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Link } from 'react-router';

import { ApiError } from '@/api/errors';
import { useCurrentUser } from '@/auth/AuthProvider';
import { cn } from '@/lib/cn';
import { permissions as fetchPermissions, roles as fetchRoles } from '@/screens/access/api';
import { Button } from '@/ui/Button';
import { Input } from '@/ui/Input';

/**
 * The permission catalogue: everything the platform enforces, and who carries it.
 *
 * Read-only, and that is the platform's shape rather than an unfinished screen. The
 * catalogue is an enum in the backend, seeded from there into the permissions table;
 * `GET /admin/permissions` is the only endpoint over it — there is no create, no
 * rename and no delete, on this surface or any other. So this screen does not offer
 * a disabled "New permission" button or a form that would fail: it says plainly what
 * the catalogue is, and points at the thing an operator can actually change.
 *
 * What that is, is roles. Every row therefore carries the roles that hold it, counted
 * and named, computed here from `/admin/roles` — the same two responses the roles
 * screen reads, asked the other way round. A permission no role carries is called out
 * rather than left as an empty space, because "enforced by the platform and held by
 * nobody" is the one fact this view exists to make visible.
 *
 * The grouping is the API's: it returns the catalogue keyed by the module that owns
 * each entry. Only the module's *wording* is ours, and an unrecognised key falls back
 * to the identifier rather than disappearing.
 */
export function PermissionsScreen() {
    const { t } = useTranslation();
    const viewer = useCurrentUser();
    const [term, setTerm] = useState('');

    const mayRead = viewer.permissions.includes('permissions.view');
    const mayReadRoles = viewer.permissions.includes('roles.view');
    const mayUpdateRoles = viewer.permissions.includes('roles.update');

    const catalogue = useQuery({
        queryKey: ['admin-permissions'],
        queryFn: ({ signal }) => fetchPermissions(signal),
        // A request that would certainly be refused is not sent. The API is still the
        // boundary; this only avoids rendering an error where nothing is wrong.
        enabled: mayRead,
    });

    const roles = useQuery({
        queryKey: ['admin-roles'],
        queryFn: ({ signal }) => fetchRoles(signal),
        enabled: mayRead && mayReadRoles,
    });

    /** Permission key to the roles that carry it, from the roles the viewer may read. */
    const carriers = useMemo(() => {
        const index = new Map<string, string[]>();

        for (const role of roles.data ?? []) {
            for (const key of role.permissions) {
                index.set(key, [...(index.get(key) ?? []), role.name_label]);
            }
        }

        return index;
    }, [roles.data]);

    const groups = useMemo(() => {
        const needle = term.trim().toLowerCase();

        return Object.entries(catalogue.data ?? {})
            .map(([module, entries]) => {
                const moduleLabel = t(`access.permissionModules.${module}`, {
                    defaultValue: module,
                });

                const matching =
                    needle === ''
                        ? entries
                        : entries.filter((permission) =>
                              [
                                  permission.key,
                                  permission.label,
                                  moduleLabel,
                                  ...(carriers.get(permission.key) ?? []),
                              ]
                                  .join(' ')
                                  .toLowerCase()
                                  .includes(needle),
                          );

                return { module, moduleLabel, entries: matching };
            })
            .filter((group) => group.entries.length > 0);
    }, [carriers, catalogue.data, t, term]);

    const total = Object.values(catalogue.data ?? {}).reduce(
        (sum, entries) => sum + entries.length,
        0,
    );

    if (!mayRead) {
        return <p className="text-(--text-muted)">{t('access.permissions.denied')}</p>;
    }

    if (catalogue.isPending) {
        return <p className="text-(--text-muted)">{t('state.loading')}</p>;
    }

    if (catalogue.error !== null) {
        return (
            <p className="text-(--text-danger)">
                {catalogue.error instanceof ApiError ? catalogue.error.message : t('state.error')}
            </p>
        );
    }

    return (
        <div className="flex flex-col gap-(--section-gap)">
            <header className="flex flex-wrap items-end justify-between gap-3">
                <div>
                    <p data-eyebrow>{t('access.permissions.eyebrow')}</p>
                    <h1 className="text-(length:--text-2xl) text-(--text-primary)">
                        {t('modules.permissions')}
                    </h1>
                </div>

                <div className="flex w-full max-w-xs flex-col gap-1">
                    <label className="sr-only" htmlFor="permission-filter">
                        {t('access.permissions.filter')}
                    </label>
                    <div className="relative">
                        <Search
                            aria-hidden
                            className="pointer-events-none absolute inset-y-0 start-2 my-auto size-3.5 text-(--text-muted)"
                        />
                        <Input
                            className="ps-7"
                            id="permission-filter"
                            onChange={(event) => setTerm(event.target.value)}
                            placeholder={t('access.permissions.filterPlaceholder')}
                            type="search"
                            value={term}
                        />
                    </div>
                    <p className="text-(length:--text-2xs) text-(--text-muted)">
                        {t('access.permissions.count', { count: total })}
                    </p>
                </div>
            </header>

            {/* Said once, at the top, in the same breath as the title: this is a
                catalogue and not a workspace. An operator who came here to add a
                permission learns why they cannot before scrolling for a control. */}
            <section className="flex items-start gap-2.5 border border-(--border-default) bg-(--surface-raised) p-3">
                <Lock aria-hidden className="mt-0.5 size-4 shrink-0 text-(--text-muted)" />
                <div className="flex min-w-0 flex-col gap-1.5">
                    <p className="font-bold text-(--text-primary)">
                        {t('access.permissions.readOnly')}
                    </p>
                    <p className="text-(length:--text-sm) text-(--text-secondary)">
                        {t('access.permissions.catalogueNote')}
                    </p>
                    {mayUpdateRoles ? (
                        <div className="mt-0.5">
                            <Button asChild size="sm" variant="secondary">
                                <Link to="/access/roles">
                                    {t('access.permissions.manageRoles')}
                                </Link>
                            </Button>
                        </div>
                    ) : null}
                </div>
            </section>

            {!mayReadRoles ? (
                <p className="text-(length:--text-sm) text-(--text-muted)">
                    {t('access.permissions.rolesHidden')}
                </p>
            ) : null}

            {groups.length === 0 ? (
                <p className="text-(--text-muted)">{t('access.permissions.noMatches')}</p>
            ) : (
                <div className="flex flex-col gap-(--section-gap)">
                    {groups.map((group) => (
                        <section
                            className="border border-(--border-default) bg-(--surface-default)"
                            key={group.module}
                        >
                            <h2
                                className="border-b border-(--border-default) px-3 py-2"
                                data-eyebrow
                            >
                                {group.moduleLabel}
                            </h2>

                            <ul>
                                {group.entries.map((permission) => {
                                    const held = carriers.get(permission.key) ?? [];
                                    // Only meaningful once the roles are actually in
                                    // hand: a viewer who may not read them is told so
                                    // above rather than shown a count of zero.
                                    const orphan = mayReadRoles && held.length === 0;

                                    return (
                                        <li
                                            className="flex flex-col gap-1 border-b border-(--border-default) px-3 py-2.5 last:border-b-0 sm:flex-row sm:items-baseline sm:justify-between sm:gap-4"
                                            key={permission.key}
                                        >
                                            <span className="flex min-w-0 flex-col">
                                                <span className="text-(--text-primary)">
                                                    {permission.label}
                                                </span>
                                                <span
                                                    className="text-(length:--text-2xs) text-(--text-muted)"
                                                    data-technical
                                                >
                                                    {permission.key}
                                                </span>
                                            </span>

                                            {mayReadRoles ? (
                                                <span className="flex min-w-0 flex-col sm:items-end">
                                                    <span
                                                        className={cn(
                                                            'text-(length:--text-sm)',
                                                            orphan
                                                                ? 'text-(--state-warning-text)'
                                                                : 'text-(--text-secondary)',
                                                        )}
                                                    >
                                                        {t('access.permissions.carriedBy', {
                                                            count: held.length,
                                                        })}
                                                    </span>
                                                    <span className="text-(length:--text-2xs) text-(--text-muted) sm:text-end">
                                                        {orphan
                                                            ? t('access.permissions.orphanNote')
                                                            : held.join(t('list.separator'))}
                                                    </span>
                                                </span>
                                            ) : null}
                                        </li>
                                    );
                                })}
                            </ul>
                        </section>
                    ))}
                </div>
            )}
        </div>
    );
}
