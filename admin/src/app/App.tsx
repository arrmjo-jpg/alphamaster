import { useTranslation } from 'react-i18next';

import { DensityControl, LocaleControl, ThemeControl } from '@/shell/PreferenceControls';
import { StateRail } from '@/ui/StateRail';
import { StatusBadge } from '@/ui/StatusBadge';

/**
 * The foundation's own screen.
 *
 * It exists so the platform layer — tokens, theme, density, direction, translation —
 * is verifiable before anything is built on it. Routing and the real shell arrive
 * with the authenticated application.
 */
export function App() {
    const { t } = useTranslation();

    return (
        <main className="mx-auto flex max-w-2xl flex-col gap-6 p-8">
            <header className="flex flex-col gap-1">
                <h1 className="text-(length:--text-2xl) font-semibold text-(--text-primary)">
                    {t('app.name')}
                </h1>
                <p className="text-(--text-secondary)">{t('app.foundation')}</p>
            </header>

            <StateRail tone="info">
                <p className="text-(--text-secondary)">{t('app.foundationNote')}</p>
            </StateRail>

            <section className="flex flex-col gap-4 rounded-lg border border-(--border-default) bg-(--surface-default) p-4">
                <div className="flex flex-col gap-2">
                    <span className="text-(length:--text-sm) font-medium text-(--text-secondary)">
                        {t('theme.label')}
                    </span>
                    <ThemeControl />
                </div>

                <div className="flex flex-col gap-2">
                    <span className="text-(length:--text-sm) font-medium text-(--text-secondary)">
                        {t('density.label')}
                    </span>
                    <DensityControl />
                </div>

                <div className="flex flex-col gap-2">
                    <span className="text-(length:--text-sm) font-medium text-(--text-secondary)">
                        {t('language.label')}
                    </span>
                    <LocaleControl />
                </div>
            </section>

            <div className="flex flex-wrap gap-2">
                <StatusBadge tone="success">Operational</StatusBadge>
                <StatusBadge tone="warning">Degraded</StatusBadge>
                <StatusBadge tone="danger">Failed</StatusBadge>
                <StatusBadge tone="pending">Staged</StatusBadge>
                <StatusBadge tone="info">Info</StatusBadge>
                <StatusBadge tone="neutral">Inactive</StatusBadge>
            </div>
        </main>
    );
}
