import { useQuery } from '@tanstack/react-query';
import { Plus } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';

import { ApiError } from '@/api/errors';
import { useCurrentUser } from '@/auth/AuthProvider';
import { cn } from '@/lib/cn';
import { permissions as fetchPermissions, roles as fetchRoles } from '@/screens/access/api';
import { RoleEditor } from '@/screens/access/RoleEditor';
import { Button } from '@/ui/Button';

/** Nothing chosen, an existing role, or the one being created. */
type Selection = { kind: 'role'; name: string } | { kind: 'new' };

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
 *
 * Creating, changing and deleting a role are all here now, because all three endpoints
 * exist and none of them had a control. They are gated on `roles.update` — the same
 * permission the API enforces on each — and an account without it sees the same
 * catalogue, read-only, rather than a screen of refusals.
 */
export function RolesScreen() {
    const { t } = useTranslation();
    const viewer = useCurrentUser();
    const [selection, setSelection] = useState<Selection | null>(null);

    const roles = useQuery({
        queryKey: ['admin-roles'],
        queryFn: ({ signal }) => fetchRoles(signal),
    });

    const mayReadPermissions = viewer.permissions.includes('permissions.view');
    const mayUpdate = viewer.permissions.includes('roles.update');

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
    const creating = selection?.kind === 'new';
    const active =
        selection?.kind === 'role'
            ? (list.find((role) => role.name === selection.name) ?? null)
            : creating
              ? null
              : (list[0] ?? null);

    return (
        <div className="flex flex-col gap-(--section-gap)">
            <header className="flex flex-wrap items-end justify-between gap-3">
                <div>
                    <p data-eyebrow>{t('access.eyebrow')}</p>
                    <h1 className="text-(length:--text-2xl) text-(--text-primary)">
                        {t('modules.roles')}
                    </h1>
                </div>

                {mayUpdate ? (
                    <Button onClick={() => setSelection({ kind: 'new' })} variant="secondary">
                        <Plus aria-hidden className="size-3.5" />
                        {t('access.roles.add')}
                    </Button>
                ) : null}
            </header>

            <div className="grid min-h-0 grid-cols-1 border border-(--border-default) bg-(--surface-default) lg:grid-cols-[240px_1fr]">
                <nav
                    aria-label={t('modules.roles')}
                    className="border-b border-(--border-default) lg:border-b-0 lg:border-e"
                >
                    {list.map((role) => {
                        const isActive = !creating && active?.name === role.name;

                        return (
                            <button
                                className={cn(
                                    'relative flex w-full items-baseline justify-between gap-2 border-b border-(--border-default) py-2.5 ps-4 pe-3 text-start last:border-b-0',
                                    isActive
                                        ? 'bg-(--action-secondary) font-bold text-(--text-primary)'
                                        : 'text-(--text-secondary) hover:bg-(--action-ghost-hover)',
                                )}
                                key={role.id}
                                onClick={() => setSelection({ kind: 'role', name: role.name })}
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

                    {creating ? (
                        <span className="relative flex w-full items-baseline gap-2 border-b border-(--border-default) bg-(--action-secondary) py-2.5 ps-4 pe-3 font-bold text-(--text-primary) last:border-b-0">
                            <span
                                aria-hidden
                                className="absolute inset-y-0 start-0 w-(--rail-width) bg-(--state-pending-rail)"
                            />
                            {t('access.roles.newRole')}
                        </span>
                    ) : null}
                </nav>

                {active === null && !creating ? (
                    <p className="p-4 text-(--text-muted)">{t('access.noRoles')}</p>
                ) : (
                    <RoleEditor
                        catalogue={catalogue.data ?? null}
                        // Keyed by what it is editing, so a draft never survives a change
                        // of selection and a refetch never resets one.
                        key={creating ? 'new' : (active?.name ?? 'none')}
                        mayReadPermissions={mayReadPermissions}
                        mayUpdate={mayUpdate}
                        onDeleted={() => setSelection(null)}
                        onSaved={(role) =>
                            setSelection(role === null ? null : { kind: 'role', name: role.name })
                        }
                        role={creating ? null : active}
                    />
                )}
            </div>
        </div>
    );
}
