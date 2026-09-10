import { LogOut, Menu as MenuIcon, X } from 'lucide-react';
import { useTranslation } from 'react-i18next';

import { useAuth, useCurrentUser } from '@/auth/AuthProvider';
import { cn } from '@/lib/cn';

import { DensityControl, LocaleControl, ThemeControl } from './PreferenceControls';

export interface TopbarProps {
    navigationOpen: boolean;
    onToggleNavigation: () => void;
}

/**
 * The top edge of the console.
 *
 * Near-black in both themes, like the navigation and like the sign-in cover, so the
 * frame is one continuous object and the work sits inside it. The wordmark carries
 * the same brand rail it carries on the cover — the one mark that says which product
 * this is, in the one place it appears.
 *
 * The preference controls live here rather than behind a settings screen because they
 * change how this application is read, not how the platform behaves: an operator
 * switching to Arabic or to a spacious layout is adjusting their own view and should
 * not have to navigate away to do it.
 */
export function Topbar({ navigationOpen, onToggleNavigation }: TopbarProps) {
    const { t } = useTranslation();
    const { signOut } = useAuth();
    const user = useCurrentUser();

    return (
        <header className="sticky top-0 z-(--z-sticky) flex h-(--topbar-height) items-stretch gap-1 border-b border-(--border-chrome) bg-(--surface-chrome) text-(--text-on-chrome)">
            <button
                aria-expanded={navigationOpen}
                aria-label={navigationOpen ? t('shell.closeNavigation') : t('shell.openNavigation')}
                className="px-3 text-(--text-on-chrome-muted) hover:bg-(--surface-chrome-hover) hover:text-(--text-on-chrome) md:hidden"
                onClick={onToggleNavigation}
                type="button"
            >
                {navigationOpen ? (
                    <X aria-hidden className="size-4" />
                ) : (
                    <MenuIcon aria-hidden className="size-4" />
                )}
            </button>

            <div className="flex items-center gap-2.5 px-4 md:w-(--nav-panel-width) md:border-e md:border-(--border-chrome)">
                <span aria-hidden className="h-5 w-(--rail-width) bg-(--brand-on-chrome)" />
                <span className="text-(length:--text-md) font-bold tracking-(--tracking-tight)">
                    {t('app.name')}
                </span>
            </div>

            <div className="ms-auto flex items-center gap-3 px-3">
                <div className="hidden items-center gap-2 xl:flex">
                    <ThemeControl />
                    <DensityControl />
                    <LocaleControl />
                </div>

                <div
                    className="hidden max-w-44 flex-col justify-center leading-tight sm:flex"
                    title={user.email}
                >
                    <span className="truncate text-(length:--text-sm) font-medium">
                        {user.name}
                    </span>
                    <span className="truncate text-(length:--text-2xs) text-(--text-on-chrome-muted)">
                        {user.email}
                    </span>
                </div>

                <button
                    className={cn(
                        'flex items-center gap-2 self-stretch px-3',
                        'text-(length:--text-sm) text-(--text-on-chrome-muted)',
                        'transition-colors duration-100 ease-out',
                        'hover:bg-(--surface-chrome-hover) hover:text-(--text-on-chrome)',
                    )}
                    onClick={() => void signOut()}
                    type="button"
                >
                    <LogOut aria-hidden className="size-4" />
                    <span className="hidden sm:inline">{t('auth.signOut')}</span>
                </button>
            </div>
        </header>
    );
}
