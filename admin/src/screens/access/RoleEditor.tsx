import { useMutation, useQueryClient } from '@tanstack/react-query';
import { Trash2 } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';

import { ApiError } from '@/api/errors';
import { cn } from '@/lib/cn';
import { Alert } from '@/ui/Alert';
import { Button } from '@/ui/Button';
import { Checkbox } from '@/ui/Checkbox';
import { Field } from '@/ui/Field';
import { Input } from '@/ui/Input';

import { deleteRole, saveRole, type PermissionCatalogue, type Role } from './api';

export interface RoleEditorProps {
    /** Null while a new role is being composed. */
    role: Role | null;
    catalogue: PermissionCatalogue | null;
    mayUpdate: boolean;
    /** Whether this viewer may read the permission catalogue at all. */
    mayReadPermissions: boolean;
    /** Receives the saved role, or null when a creation was abandoned. */
    onSaved: (role: Role | null) => void;
    onDeleted: () => void;
}

/**
 * One role, and what it carries.
 *
 * The grouping is the platform's: `/admin/permissions` returns the catalogue grouped by
 * the module that owns each entry, and inventing a different arrangement here would be
 * a second taxonomy for the same set — the two would disagree the first time a
 * permission moved between modules.
 *
 * A role is created from a label. The machine identifier is derived from it server-side
 * once and is immutable afterwards, because permissions and assignments reference a role
 * by that name and renaming one would silently detach them. The form says so rather than
 * offering a field that looks editable and is not.
 *
 * Reading and changing are the same layout deliberately. An operator checking what a
 * role carries and one adjusting it are looking at the same thing, and a separate
 * read-only rendering would be a second place for the two to drift apart.
 */
export function RoleEditor({
    role,
    catalogue,
    mayUpdate,
    mayReadPermissions,
    onSaved,
    onDeleted,
}: RoleEditorProps) {
    const { t } = useTranslation();
    const queryClient = useQueryClient();

    const creating = role === null;

    const [label, setLabel] = useState(role?.name_label ?? '');
    const [granted, setGranted] = useState<string[]>(role?.permissions ?? []);
    const [confirming, setConfirming] = useState(false);

    const refresh = async () => {
        await queryClient.invalidateQueries({ queryKey: ['admin-roles'] });
        // An account's effective permissions come from its roles, so a role that just
        // changed makes every cached account stale.
        await queryClient.invalidateQueries({ queryKey: ['admin-users'] });
        await queryClient.invalidateQueries({ queryKey: ['admin-user'] });
    };

    const save = useMutation({
        mutationFn: () =>
            saveRole({ label: label.trim(), permissions: granted }, creating ? undefined : role.id),
        onSuccess: async (saved) => {
            await refresh();
            // The saved role, not the one this panel opened on: a creation has no
            // identifier until the platform derives one from the label.
            onSaved(saved);
        },
    });

    const remove = useMutation({
        mutationFn: () => deleteRole(role?.id ?? 0),
        onSuccess: async () => {
            await refresh();
            onDeleted();
        },
    });

    const toggle = (key: string) =>
        setGranted((current) =>
            current.includes(key) ? current.filter((held) => held !== key) : [...current, key],
        );

    const dirty =
        creating ||
        label.trim() !== role.name_label ||
        granted.length !== role.permissions.length ||
        granted.some((key) => !role.permissions.includes(key));

    const labelError =
        save.error instanceof ApiError ? save.error.validationDetails?.['label']?.[0] : undefined;

    return (
        <div className="flex min-w-0 flex-col gap-4 p-4">
            <div>
                <p data-eyebrow>{creating ? t('access.roles.newRole') : t('access.role')}</p>
                {mayUpdate ? (
                    <Field
                        hint={creating ? t('access.roles.labelHint') : t('access.roles.renameHint')}
                        label={t('access.roles.label')}
                        required
                        {...(labelError === undefined ? {} : { error: labelError })}
                    >
                        {({ id, 'aria-describedby': describedBy, invalid }) => (
                            <Input
                                aria-describedby={describedBy}
                                id={id}
                                invalid={invalid}
                                onChange={(event) => setLabel(event.target.value)}
                                value={label}
                            />
                        )}
                    </Field>
                ) : (
                    <h2 className="text-(length:--text-xl) text-(--text-primary)">
                        {role?.name_label}
                    </h2>
                )}
                {creating ? null : (
                    <p className="mt-1 text-(length:--text-xs) text-(--text-muted)" data-technical>
                        {role.name}
                    </p>
                )}
            </div>

            {!mayReadPermissions ? (
                <p className="text-(--text-muted)">{t('access.permissionsHidden')}</p>
            ) : catalogue === null ? (
                <p className="text-(--text-muted)">{t('state.loading')}</p>
            ) : (
                <div className="flex flex-col gap-4">
                    {Object.entries(catalogue).map(([module, entries]) => (
                        <section key={module}>
                            <h3 className="border-b border-(--border-default) pb-1" data-eyebrow>
                                {module}
                            </h3>
                            <ul className="mt-1.5 flex flex-col gap-0.5">
                                {entries.map((permission) => {
                                    const held = granted.includes(permission.key);
                                    const changed =
                                        !creating &&
                                        held !== role.permissions.includes(permission.key);

                                    return (
                                        <li key={permission.key}>
                                            {mayUpdate ? (
                                                <label className="flex items-baseline gap-2">
                                                    <Checkbox
                                                        checked={held}
                                                        className="translate-y-0.5"
                                                        onChange={() => toggle(permission.key)}
                                                        pending={changed}
                                                    />
                                                    <span className="flex min-w-0 flex-col">
                                                        <span
                                                            className={cn(
                                                                'text-(length:--text-sm)',
                                                                held
                                                                    ? 'font-medium text-(--text-primary)'
                                                                    : 'text-(--text-secondary)',
                                                            )}
                                                        >
                                                            {permission.label}
                                                        </span>
                                                        <span
                                                            className="text-(length:--text-2xs) text-(--text-muted)"
                                                            data-technical
                                                        >
                                                            {permission.key}
                                                        </span>
                                                    </span>
                                                </label>
                                            ) : (
                                                <span className="flex items-baseline gap-2">
                                                    {/* A held permission is set in the
                                                        foreground; the rest stay visible
                                                        so the gaps are readable too. */}
                                                    <span
                                                        aria-hidden
                                                        className={cn(
                                                            'mt-1.5 size-1.5 shrink-0',
                                                            held
                                                                ? 'bg-(--action-primary)'
                                                                : 'bg-(--border-strong)',
                                                        )}
                                                    />
                                                    <span
                                                        className={cn(
                                                            'text-(length:--text-sm)',
                                                            held
                                                                ? 'font-medium text-(--text-primary)'
                                                                : 'text-(--text-muted)',
                                                        )}
                                                    >
                                                        {permission.label}
                                                    </span>
                                                </span>
                                            )}
                                        </li>
                                    );
                                })}
                            </ul>
                        </section>
                    ))}
                </div>
            )}

            {save.error instanceof ApiError && save.error.validationDetails === null ? (
                <Alert tone="danger">{save.error.message}</Alert>
            ) : null}

            {remove.error instanceof ApiError ? (
                <Alert tone="danger">{remove.error.message}</Alert>
            ) : null}

            {save.isSuccess && !dirty ? (
                <Alert tone="success">{t('access.roles.saved')}</Alert>
            ) : null}

            {mayUpdate ? (
                <div className="flex flex-col gap-3 border-t border-(--border-default) pt-3">
                    <div className="flex flex-wrap gap-2">
                        <Button
                            disabled={!dirty || label.trim() === ''}
                            loading={save.isPending}
                            onClick={() => save.mutate()}
                            variant="primary"
                        >
                            {creating ? t('access.roles.create') : t('access.roles.save')}
                        </Button>
                        {creating ? (
                            <Button onClick={() => onSaved(null)} variant="ghost">
                                {t('access.roles.cancel')}
                            </Button>
                        ) : (
                            <Button
                                disabled={!dirty}
                                onClick={() => {
                                    setLabel(role.name_label);
                                    setGranted(role.permissions);
                                }}
                                variant="ghost"
                            >
                                {t('access.roles.discard')}
                            </Button>
                        )}
                    </div>

                    {creating ? null : (
                        <div className="flex flex-col gap-2">
                            {/* The trigger stays where it is and stays disabled while the
                                confirmation is open, so the confirmation appears below it
                                rather than under the pointer that opened it. */}
                            <div>
                                <Button
                                    disabled={confirming}
                                    onClick={() => setConfirming(true)}
                                    size="sm"
                                    variant="secondary"
                                >
                                    <Trash2 aria-hidden className="size-3.5" />
                                    {t('access.roles.delete')}
                                </Button>
                            </div>

                            {confirming ? (
                                <div className="flex flex-col gap-2 border-s-(length:--rail-width) border-(--state-danger-rail) ps-2">
                                    <p className="text-(length:--text-sm) text-(--state-danger-text)">
                                        {t('access.roles.deleteWarning')}
                                    </p>
                                    {/* Cancel first: the cheapest mistake lands on the
                                        reversible action. */}
                                    <div className="flex flex-wrap gap-2">
                                        <Button
                                            onClick={() => setConfirming(false)}
                                            size="sm"
                                            variant="secondary"
                                        >
                                            {t('access.roles.cancel')}
                                        </Button>
                                        <Button
                                            loading={remove.isPending}
                                            onClick={() => remove.mutate()}
                                            size="sm"
                                            variant="danger"
                                        >
                                            {t('access.roles.deleteConfirm')}
                                        </Button>
                                    </div>
                                </div>
                            ) : null}
                        </div>
                    )}
                </div>
            ) : (
                <p className="border-t border-(--border-default) pt-3 text-(length:--text-sm) text-(--text-muted)">
                    {t('access.roles.readOnly')}
                </p>
            )}
        </div>
    );
}
