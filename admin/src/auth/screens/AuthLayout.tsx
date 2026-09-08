import { useTranslation } from 'react-i18next';

import { LocaleControl, ThemeControl } from '@/shell/PreferenceControls';

export interface AuthLayoutProps {
    title: string;
    description?: string;
    children: React.ReactNode;
    /** Secondary actions, rendered below the card's own content. */
    footer?: React.ReactNode;
}

/**
 * The frame every unauthenticated screen shares.
 *
 * The preference controls are here rather than only inside the application, because
 * an operator who reads Arabic, or who needs a larger target, needs that before
 * signing in and not after. Density is deliberately absent: nothing on these screens
 * is dense, and offering a control that changes almost nothing is worse than not
 * offering it.
 */
export function AuthLayout({ title, description, children, footer }: AuthLayoutProps) {
    const { t } = useTranslation();

    return (
        <div className="flex min-h-dvh flex-col items-center justify-center gap-6 bg-(--surface-canvas) p-6">
            <main className="w-full max-w-sm">
                <div className="flex flex-col gap-5 rounded-lg border border-(--border-default) bg-(--surface-default) p-6 shadow-(--shadow-raised)">
                    <header className="flex flex-col gap-1">
                        <p className="text-(length:--text-sm) font-medium text-(--text-muted)">
                            {t('app.name')}
                        </p>
                        <h1 className="text-(length:--text-xl) font-semibold text-(--text-primary)">
                            {title}
                        </h1>
                        {description !== undefined ? (
                            <p className="text-(--text-secondary)">{description}</p>
                        ) : null}
                    </header>

                    {children}
                </div>

                {footer !== undefined ? <div className="mt-4">{footer}</div> : null}
            </main>

            <div className="flex flex-wrap items-center justify-center gap-2">
                <ThemeControl />
                <LocaleControl />
            </div>
        </div>
    );
}
