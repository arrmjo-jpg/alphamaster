import { useTranslation } from 'react-i18next';

import { useCurrentUser } from '@/auth/AuthProvider';
import { StateRail } from '@/ui/StateRail';
import { StatusBadge } from '@/ui/StatusBadge';

/**
 * The first thing an operator sees, in its first form.
 *
 * Today it answers one question — who am I signed in as, and what may I do — because
 * that is what the platform has been asked for so far. The operational picture
 * replaces this content; the route and the manifest entry do not change when it does.
 */
export function DashboardScreen() {
    const { t } = useTranslation();
    const user = useCurrentUser();

    return (
        <div className="flex max-w-3xl flex-col gap-(--section-gap)">
            <StateRail tone="success">
                <p className="text-(length:--text-sm) text-(--text-muted)">
                    {t('session.signedInAs')}
                </p>
                <p className="font-medium text-(--text-primary)">{user.name}</p>
                <p className="text-(--text-secondary)" data-technical>
                    {user.email}
                </p>
            </StateRail>

            <section className="flex flex-col gap-3 rounded-lg border border-(--border-default) bg-(--surface-default) p-4">
                <Grants label={t('session.roles')} values={user.roles} />
                <Grants label={t('session.permissions')} values={user.permissions} />
            </section>
        </div>
    );
}

function Grants({ label, values }: { label: string; values: string[] }) {
    const { t } = useTranslation();

    return (
        <div className="flex flex-col gap-2">
            <span className="text-(length:--text-sm) font-medium text-(--text-secondary)">
                {label}
            </span>

            {values.length === 0 ? (
                <span className="text-(--text-muted)">{t('session.none')}</span>
            ) : (
                <div className="flex flex-wrap gap-1">
                    {values.map((value) => (
                        <StatusBadge key={value} tone="neutral">
                            {value}
                        </StatusBadge>
                    ))}
                </div>
            )}
        </div>
    );
}
