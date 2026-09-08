import { LogOut, Menu as MenuIcon, X } from 'lucide-react';
import { useTranslation } from 'react-i18next';

import { useAuth, useCurrentUser } from '@/auth/AuthProvider';
import { Button } from '@/ui/Button';

import { DensityControl, LocaleControl, ThemeControl } from './PreferenceControls';

export interface TopbarProps {
    navigationOpen: boolean;
    onToggleNavigation: () => void;
}

/**
 * Identity, preferences, and the way out.
 *
 * The preference controls live here rather than behind a settings screen because
 * they change how this application is read, not how the platform behaves — an
 * operator switching to Arabic or to a spacious layout is adjusting their own view
 * and should not have to navigate away to do it.
 */
export function Topbar({ navigationOpen, onToggleNavigation }: TopbarProps) {
    const { t } = useTranslation();
    const { signOut } = useAuth();
    const user = useCurrentUser();

    return (
        <header className="sticky top-0 z-(--z-sticky) flex h-(--topbar-height) items-center gap-2 border-b border-(--border-default) bg-(--surface-default) px-2">
            <Button
                aria-expanded={navigationOpen}
                aria-label={navigationOpen ? t('shell.closeNavigation') : t('shell.openNavigation')}
                className="md:hidden"
                onClick={onToggleNavigation}
                size="icon"
                variant="ghost"
            >
                {navigationOpen ? (
                    <X aria-hidden className="size-4" />
                ) : (
                    <MenuIcon aria-hidden className="size-4" />
                )}
            </Button>

            <span className="font-semibold text-(--text-primary)">{t('app.name')}</span>

            <div className="ms-auto flex items-center gap-2">
                <div className="hidden items-center gap-2 lg:flex">
                    <ThemeControl />
                    <DensityControl />
                    <LocaleControl />
                </div>

                <span
                    className="hidden max-w-40 truncate text-(length:--text-sm) text-(--text-secondary) sm:inline"
                    title={user.email}
                >
                    {user.name}
                </span>

                <Button
                    aria-label={t('auth.signOut')}
                    onClick={() => void signOut()}
                    variant="ghost"
                >
                    <LogOut aria-hidden className="size-4" />
                    <span className="hidden sm:inline">{t('auth.signOut')}</span>
                </Button>
            </div>
        </header>
    );
}
