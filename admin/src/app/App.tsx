import { useTranslation } from 'react-i18next';

import { useAuth, useCurrentUser } from '@/auth/AuthProvider';
import { DensityControl, LocaleControl, ThemeControl } from '@/shell/PreferenceControls';
import { Button } from '@/ui/Button';
import { StateRail } from '@/ui/StateRail';
import { StatusBadge } from '@/ui/StatusBadge';

/**
 * What an authenticated administrator sees, until the shell replaces it.
 *
 * Deliberately thin: the session, the grants behind it and the display preferences.
 * Navigation, the dashboard and the settings screens are the following changes — this
 * exists so that a session can be signed into, looked at and signed out of, which is
 * the whole of what this change claims to add.
 */
export function App() {
    const { t } = useTranslation();
    const { signOut } = useAuth();
    const user = useCurrentUser();

    return (
        <main className="mx-auto flex max-w-2xl flex-col gap-6 p-8">
            <header className="flex items-start justify-between gap-4">
                <div className="flex flex-col gap-1">
                    <h1 className="text-(length:--text-2xl) font-semibold text-(--text-primary)">
                        {t('app.name')}
                    </h1>
                    <p className="text-(--text-secondary)">{t('app.foundation')}</p>
                </div>

                <Button onClick={() => void signOut()} variant="secondary">
                    {t('auth.signOut')}
                </Button>
            </header>

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

            <section className="flex flex-col gap-4 rounded-lg border border-(--border-default) bg-(--surface-default) p-4">
                <Preference label={t('theme.label')}>
                    <ThemeControl />
                </Preference>
                <Preference label={t('density.label')}>
                    <DensityControl />
                </Preference>
                <Preference label={t('language.label')}>
                    <LocaleControl />
                </Preference>
            </section>
        </main>
    );
}

function Preference({ label, children }: { label: string; children: React.ReactNode }) {
    return (
        <div className="flex flex-col gap-2">
            <span className="text-(length:--text-sm) font-medium text-(--text-secondary)">
                {label}
            </span>
            {children}
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
