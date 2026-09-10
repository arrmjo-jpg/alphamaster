import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Pencil, ScrollText, X } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Link } from 'react-router';

import { ApiError } from '@/api/errors';
import { absoluteTime } from '@/lib/time';
import { useDirection } from '@/shell/DirectionProvider';
import { Alert } from '@/ui/Alert';
import { Button } from '@/ui/Button';
import { StatusBadge } from '@/ui/StatusBadge';

import { demote, promote, roles as fetchRoles, setUserActive, syncRoles, user } from './api';

export interface UserDetailProps {
    id: string;
    viewerPermissions: readonly string[];
    onClose: () => void;
    /** Opens the identity form on this account. Absent where the viewer may not edit. */
    onEdit?: (() => void) | undefined;
    /** The signed-in account, so the panel can refuse to switch itself off. */
    viewerId: string;
}

/**
 * One account, organised by the question being asked about it.
 *
 * Identity, access, security. They are separate sections because they are separate
 * concerns with separate remedies: a wrong address is a support problem, a missing
 * role is an authorization one, and an administrator without a second factor is a
 * security one.
 *
 * Only operations the platform actually has appear. There is no "reset password", no
 * "resend verification" and no "disable MFA" here, because there is no endpoint for
 * any of them on this surface — offering a control that cannot work is worse than
 * not offering it.
 *
 * Every gate here is presentation. The API refuses the same operations for the same
 * reasons whether or not this hides the button.
 */
export function UserDetail({ id, viewerPermissions, onClose, onEdit, viewerId }: UserDetailProps) {
    const { t } = useTranslation();
    const { locale } = useDirection();
    const queryClient = useQueryClient();

    const account = useQuery({
        queryKey: ['admin-user', id],
        queryFn: ({ signal }) => user(id, signal),
    });
    const catalogue = useQuery({
        queryKey: ['admin-roles'],
        queryFn: ({ signal }) => fetchRoles(signal),
    });

    const mayUpdateAccount = viewerPermissions.includes('users.update');
    const mayChangeRoles = viewerPermissions.includes('roles.update');

    const refresh = async () => {
        await queryClient.invalidateQueries({ queryKey: ['admin-user', id] });
        await queryClient.invalidateQueries({ queryKey: ['admin-users'] });
    };

    const changeType = useMutation({
        mutationFn: (next: 'promote' | 'demote') => (next === 'promote' ? promote(id) : demote(id)),
        onSuccess: refresh,
    });

    const changeActive = useMutation({
        mutationFn: (active: boolean) => setUserActive(id, active),
        onSuccess: async () => {
            setDeactivating(false);
            await refresh();
        },
    });

    const [draftRoles, setDraftRoles] = useState<string[] | null>(null);
    const [deactivating, setDeactivating] = useState(false);

    const saveRoles = useMutation({
        mutationFn: () => syncRoles(id, draftRoles ?? []),
        onSuccess: async () => {
            setDraftRoles(null);
            await refresh();
        },
    });

    if (account.isPending) {
        return (
            <Panel onClose={onClose} title={t('access.account')}>
                {t('state.loading')}
            </Panel>
        );
    }

    if (account.error !== null) {
        return (
            <Panel onClose={onClose} title={t('access.account')}>
                <Alert tone="danger">
                    {account.error instanceof ApiError ? account.error.message : t('state.error')}
                </Alert>
            </Panel>
        );
    }

    const data = account.data;
    const isAdmin = data.account_type === 'admin';
    const held = draftRoles ?? data.roles;

    return (
        <Panel onClose={onClose} title={t('access.account')}>
            <div className="flex flex-col gap-4">
                <section className="flex flex-col gap-1">
                    <p data-eyebrow>{t('access.identity')}</p>
                    <p className="text-(length:--text-md) font-medium text-(--text-primary)">
                        {data.name}
                    </p>
                    <p className="text-(length:--text-sm) text-(--text-secondary)" data-technical>
                        {data.email}
                    </p>
                    {data.phone !== null ? (
                        <p
                            className="text-(length:--text-sm) text-(--text-secondary)"
                            data-technical
                        >
                            {data.phone}
                        </p>
                    ) : null}
                    <p className="text-(length:--text-xs) text-(--text-muted)" data-technical>
                        {data.id}
                    </p>

                    {onEdit === undefined ? null : (
                        <div className="mt-1">
                            <Button onClick={onEdit} size="sm" variant="secondary">
                                <Pencil aria-hidden className="size-3.5" />
                                {t('access.users.edit')}
                            </Button>
                        </div>
                    )}
                </section>

                <section className="flex flex-col gap-2 border-t border-(--border-default) pt-3">
                    <p data-eyebrow>{t('access.security')}</p>
                    <div className="flex flex-wrap gap-1.5">
                        <StatusBadge tone={data.is_active ? 'success' : 'danger'}>
                            {data.is_active ? t('access.active') : t('access.suspended')}
                        </StatusBadge>
                        <StatusBadge tone={data.email_verified ? 'success' : 'warning'}>
                            {data.email_verified ? t('access.verified') : t('access.unverified')}
                        </StatusBadge>
                        <StatusBadge tone={data.mfa_enrolled ? 'success' : 'warning'}>
                            {data.mfa_enrolled ? t('access.mfaOn') : t('access.mfaOff')}
                        </StatusBadge>
                        {/* Only shown where there is a number: "not confirmed" beside
                            an account that has no phone at all describes an absence
                            rather than a problem. */}
                        {data.phone !== null ? (
                            <StatusBadge tone={data.phone_verified ? 'success' : 'warning'}>
                                {data.phone_verified
                                    ? t('access.phoneConfirmed')
                                    : t('access.phoneUnconfirmed')}
                            </StatusBadge>
                        ) : null}
                    </div>
                    {data.email_verified_at !== null ? (
                        <p className="text-(length:--text-xs) text-(--text-muted)">
                            {t('access.verifiedAt', {
                                at: absoluteTime(data.email_verified_at, locale) ?? '',
                            })}
                        </p>
                    ) : null}
                    {/* Said rather than implied: this is the one fact about the second
                        factor the platform publishes, and an operator should not read
                        more into it than is there. */}
                    <p className="text-(length:--text-xs) text-(--text-muted)">
                        {t('access.mfaNote')}
                    </p>
                    {/* Said rather than left to be discovered: an operator looking at
                        an unconfirmed number needs to know why there is no button. */}
                    {data.phone !== null && !data.phone_verified ? (
                        <p className="text-(length:--text-xs) text-(--text-muted)">
                            {t('access.phoneConfirmNote')}
                        </p>
                    ) : null}

                    {mayUpdateAccount ? (
                        data.is_active ? (
                            <div className="flex flex-col gap-2">
                                {/* The trigger stays where it is and stays disabled
                                    while the confirmation is open, so the confirmation
                                    appears below it rather than under the pointer that
                                    opened it. */}
                                <div>
                                    <Button
                                        disabled={deactivating || viewerId === data.id}
                                        onClick={() => setDeactivating(true)}
                                        size="sm"
                                        variant="secondary"
                                    >
                                        {t('access.users.deactivate')}
                                    </Button>
                                </div>

                                {viewerId === data.id ? (
                                    <p className="text-(length:--text-xs) text-(--text-muted)">
                                        {t('access.users.cannotDeactivateSelf')}
                                    </p>
                                ) : null}

                                {deactivating ? (
                                    <div className="flex flex-col gap-2 border-s-(length:--rail-width) border-(--state-danger-rail) ps-2">
                                        <p className="text-(length:--text-sm) text-(--state-danger-text)">
                                            {t('access.users.deactivateWarning')}
                                        </p>
                                        {/* Cancel first: the cheapest mistake lands on
                                            the reversible action. */}
                                        <div className="flex flex-wrap gap-2">
                                            <Button
                                                onClick={() => setDeactivating(false)}
                                                size="sm"
                                                variant="secondary"
                                            >
                                                {t('access.users.cancel')}
                                            </Button>
                                            <Button
                                                loading={changeActive.isPending}
                                                onClick={() => changeActive.mutate(false)}
                                                size="sm"
                                                variant="danger"
                                            >
                                                {t('access.users.deactivateConfirm')}
                                            </Button>
                                        </div>
                                    </div>
                                ) : null}
                            </div>
                        ) : (
                            <div>
                                <Button
                                    loading={changeActive.isPending}
                                    onClick={() => changeActive.mutate(true)}
                                    size="sm"
                                    variant="primary"
                                >
                                    {t('access.users.activate')}
                                </Button>
                            </div>
                        )
                    ) : null}

                    {changeActive.error instanceof ApiError ? (
                        <Alert tone="danger">{changeActive.error.message}</Alert>
                    ) : null}
                </section>

                <section className="flex flex-col gap-2 border-t border-(--border-default) pt-3">
                    <p data-eyebrow>{t('access.standing')}</p>
                    <div className="flex flex-wrap items-center gap-2">
                        <StatusBadge tone={isAdmin ? 'info' : 'neutral'}>
                            {data.account_type_label}
                        </StatusBadge>

                        {mayUpdateAccount ? (
                            <Button
                                loading={changeType.isPending}
                                onClick={() => changeType.mutate(isAdmin ? 'demote' : 'promote')}
                                size="sm"
                                variant={isAdmin ? 'ghost' : 'secondary'}
                            >
                                {isAdmin ? t('access.demote') : t('access.promote')}
                            </Button>
                        ) : null}
                    </div>

                    {changeType.error instanceof ApiError ? (
                        <Alert tone="danger">{changeType.error.message}</Alert>
                    ) : null}
                </section>

                <section className="flex flex-col gap-2 border-t border-(--border-default) pt-3">
                    {/* `access.roles` is the roles *screen's* namespace, not a
                        string: this heading has its own key so it cannot resolve to
                        an object again. */}
                    <p data-eyebrow>{t('access.rolesTitle')}</p>

                    {catalogue.data === undefined ? (
                        <p className="text-(--text-muted)">{t('state.loading')}</p>
                    ) : mayChangeRoles ? (
                        <>
                            <ul className="flex flex-col gap-1">
                                {catalogue.data.map((role) => (
                                    <li key={role.id}>
                                        <label className="flex items-start gap-2 text-(length:--text-sm)">
                                            <input
                                                checked={held.includes(role.name)}
                                                className="mt-1"
                                                onChange={(event) =>
                                                    setDraftRoles(
                                                        event.target.checked
                                                            ? [...held, role.name]
                                                            : held.filter((n) => n !== role.name),
                                                    )
                                                }
                                                type="checkbox"
                                            />
                                            <span>
                                                <span className="text-(--text-primary)">
                                                    {role.name_label}
                                                </span>{' '}
                                                <span
                                                    className="text-(length:--text-xs) text-(--text-muted)"
                                                    data-technical
                                                >
                                                    {role.name}
                                                </span>
                                            </span>
                                        </label>
                                    </li>
                                ))}
                            </ul>

                            {draftRoles !== null ? (
                                <div className="flex flex-wrap gap-2">
                                    <Button
                                        loading={saveRoles.isPending}
                                        onClick={() => saveRoles.mutate()}
                                        size="sm"
                                        variant="primary"
                                    >
                                        {t('access.saveRoles')}
                                    </Button>
                                    <Button
                                        onClick={() => setDraftRoles(null)}
                                        size="sm"
                                        variant="ghost"
                                    >
                                        {t('settings.history.cancel')}
                                    </Button>
                                </div>
                            ) : null}

                            {saveRoles.error instanceof ApiError ? (
                                <Alert tone="danger">{saveRoles.error.message}</Alert>
                            ) : null}
                        </>
                    ) : (
                        <>
                            <p className="text-(length:--text-sm) text-(--text-primary)">
                                {data.roles.length === 0
                                    ? t('session.none')
                                    : data.roles.join(t('list.separator'))}
                            </p>
                            <p className="text-(length:--text-xs) text-(--text-muted)">
                                {t('access.rolesReadOnly')}
                            </p>
                        </>
                    )}
                </section>

                <section className="flex flex-col gap-2 border-t border-(--border-default) pt-3">
                    <p data-eyebrow>{t('access.effective')}</p>
                    {/* Effective, and labelled as such. The platform reports what this
                        account holds directly or through a role and does not say
                        which — so neither does this. */}
                    <p className="text-(length:--text-xs) text-(--text-muted)">
                        {t('access.effectiveNote')}
                    </p>
                    {data.permissions.length === 0 ? (
                        <p className="text-(--text-muted)">{t('session.none')}</p>
                    ) : (
                        <ul className="flex flex-wrap gap-1">
                            {data.permissions.map((permission) => (
                                <li key={permission}>
                                    <StatusBadge tone="neutral">{permission}</StatusBadge>
                                </li>
                            ))}
                        </ul>
                    )}
                </section>

                {/* What this account has actually done, rather than what it may do.
                    The trail records an actor by id, which is exactly the identifier
                    above, so the link is a real query rather than a search by name. */}
                {viewerPermissions.includes('audit.view') ? (
                    <section className="flex flex-col gap-2 border-t border-(--border-default) pt-3">
                        <p data-eyebrow>{t('access.activity')}</p>
                        <div>
                            <Button asChild size="sm" variant="secondary">
                                <Link to={`/operations?actor_id=${encodeURIComponent(data.id)}`}>
                                    <ScrollText aria-hidden className="size-3.5" />
                                    {t('access.viewActivity')}
                                </Link>
                            </Button>
                        </div>
                    </section>
                ) : null}

                {/* Said where an operator would look for the controls, rather than left
                    as an unexplained absence. */}
                <p className="border-t border-(--border-default) pt-3 text-(length:--text-xs) text-(--text-muted)">
                    {t('access.unsupported')}
                </p>
            </div>
        </Panel>
    );
}

function Panel({
    title,
    onClose,
    children,
}: {
    title: string;
    onClose: () => void;
    children: React.ReactNode;
}) {
    return (
        <section className="border border-(--border-default) bg-(--surface-default)">
            <header className="flex items-center justify-between gap-2 border-b border-(--border-default) px-3 py-2.5">
                <h2 className="text-(--text-secondary)" data-eyebrow>
                    {title}
                </h2>
                <button
                    aria-label={title}
                    className="p-1 text-(--text-muted) hover:text-(--text-primary)"
                    onClick={onClose}
                    type="button"
                >
                    <X aria-hidden className="size-4" />
                </button>
            </header>
            <div className="p-3">{children}</div>
        </section>
    );
}
