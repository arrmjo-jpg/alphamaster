import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Outlet, useLocation } from 'react-router';

import { useCurrentUser } from '@/auth/AuthProvider';
import { cn } from '@/lib/cn';

import { Navigation } from './Navigation';
import { Topbar } from './Topbar';

/**
 * The frame every module renders inside.
 *
 * A black L — the topbar across the top and the navigation down the inline-start
 * edge, both on the console surface — with the work in the field it encloses. The
 * boundary between frame and work is a rule, not a shadow, and nothing here floats.
 *
 * On a narrow screen the navigation does not simply collapse into the content: it
 * becomes a full-height drawer over it, keeping the same surface and the same active
 * rail, so the console still reads as a console on a phone. The drawer closes on
 * navigation, which is what makes it usable there and costs nothing on a desktop
 * where it is never open.
 *
 * The main region is keyed by pathname so moving between modules resets scroll and
 * focus order rather than leaving an operator halfway down a page they have left.
 */
export function AppShell() {
    const { t } = useTranslation();
    const user = useCurrentUser();
    const location = useLocation();

    const [navigationOpen, setNavigationOpen] = useState(false);

    return (
        <div className="min-h-dvh bg-(--surface-canvas)">
            <Topbar
                navigationOpen={navigationOpen}
                onToggleNavigation={() => setNavigationOpen((open) => !open)}
            />

            <div className="flex">
                {navigationOpen ? (
                    <button
                        aria-label={t('shell.closeNavigation')}
                        className="fixed inset-0 z-(--z-overlay) bg-(--slate-950)/60 md:hidden"
                        onClick={() => setNavigationOpen(false)}
                        type="button"
                    />
                ) : null}

                <aside
                    className={cn(
                        'w-(--nav-panel-width) shrink-0 border-e border-(--border-chrome) bg-(--surface-chrome)',
                        'md:sticky md:top-(--topbar-height) md:block md:h-[calc(100dvh-var(--topbar-height))]',
                        navigationOpen
                            ? 'fixed inset-y-(--topbar-height) start-0 z-(--z-overlay) block'
                            : 'hidden',
                    )}
                >
                    <Navigation
                        onNavigate={() => setNavigationOpen(false)}
                        permissions={user.permissions}
                    />
                </aside>

                <main
                    aria-label={t('shell.content')}
                    className="min-w-0 flex-1 p-(--page-gutter)"
                    key={location.pathname}
                >
                    <Outlet />
                </main>
            </div>
        </div>
    );
}
