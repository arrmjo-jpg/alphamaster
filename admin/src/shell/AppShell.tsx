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
 * Two columns on a wide screen, one with a drawer on a narrow one. The drawer closes
 * on navigation, which is the behaviour that makes it usable on a phone and costs
 * nothing on a desktop where it is never open in the first place.
 *
 * The main region is keyed by pathname so that moving between modules resets scroll
 * and focus order rather than leaving an operator halfway down a page they have just
 * left.
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
                <aside
                    className={cn(
                        'w-(--nav-panel-width) shrink-0 border-e border-(--border-default) bg-(--surface-default)',
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
                    className="min-w-0 flex-1 p-(--section-gap)"
                    key={location.pathname}
                >
                    <Outlet />
                </main>
            </div>
        </div>
    );
}
