import { X } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Link, Outlet, useLocation } from 'react-router';

import { useCurrentUser } from '@/auth/AuthProvider';
import { cn } from '@/lib/cn';
import { HOME_PATH } from '@/modules/registry';

import { Navigation } from './Navigation';
import { Topbar } from './Topbar';

/**
 * The frame every module renders inside.
 *
 * Two columns. The sidebar runs the full height of the window on the inline-start
 * edge and owns the product mark at its head; the top bar spans only the content
 * column beside it. That is the composition an operator already knows from every
 * serious console — the navigation is a place, not a strip under the header — and it
 * gives the mark a home that is not competing with the preference menus for width.
 *
 * Both are the shell surface, set apart from the ground by their own rule, and both
 * follow the theme. The first version drew them near-black in both themes, which in
 * light mode made the console two products at once: a dark frame around a light one.
 *
 * On a narrow screen the sidebar becomes a full-height drawer over the content,
 * carrying the mark and a close control of its own, and closes on navigation — which
 * is what makes it usable on a phone and costs nothing on a desktop where it is never
 * open.
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
        <div className="min-h-dvh bg-(--surface-canvas) md:flex">
            {navigationOpen ? (
                <button
                    aria-label={t('shell.closeNavigation')}
                    className="fixed inset-0 z-(--z-overlay) bg-(--slate-950)/50 md:hidden"
                    onClick={() => setNavigationOpen(false)}
                    type="button"
                />
            ) : null}

            <aside
                className={cn(
                    'w-(--nav-panel-width) shrink-0 flex-col border-e border-(--shell-border) bg-(--shell-surface)',
                    'md:sticky md:top-0 md:flex md:h-dvh',
                    navigationOpen
                        ? 'fixed inset-y-0 start-0 z-(--z-overlay) flex shadow-(--shadow-overlay)'
                        : 'hidden',
                )}
            >
                <div className="flex h-(--topbar-height) shrink-0 items-center gap-3 border-b border-(--shell-border) px-5">
                    <Link
                        className="flex min-w-0 items-center gap-3"
                        onClick={() => setNavigationOpen(false)}
                        to={HOME_PATH}
                    >
                        {/* The brand rail — the same mark the sign-in cover carries,
                            and the one place in the console it is spent on identity
                            rather than on state. */}
                        <span aria-hidden className="h-7 w-1 shrink-0 bg-(--action-primary)" />
                        <span className="truncate text-(length:--text-lg) font-bold tracking-(--tracking-tight) text-(--shell-text)">
                            {t('app.name')}
                        </span>
                    </Link>

                    <button
                        aria-label={t('shell.closeNavigation')}
                        className="ms-auto flex size-8 items-center justify-center text-(--shell-text-muted) hover:bg-(--shell-hover) hover:text-(--shell-text) md:hidden"
                        onClick={() => setNavigationOpen(false)}
                        type="button"
                    >
                        <X aria-hidden className="size-4" />
                    </button>
                </div>

                <div className="min-h-0 flex-1 overflow-y-auto">
                    <Navigation
                        onNavigate={() => setNavigationOpen(false)}
                        permissions={user.permissions}
                    />
                </div>
            </aside>

            <div className="flex min-w-0 flex-1 flex-col">
                <Topbar
                    navigationOpen={navigationOpen}
                    onToggleNavigation={() => setNavigationOpen((open) => !open)}
                />

                <main
                    aria-label={t('shell.content')}
                    className="mx-auto w-full max-w-[1600px] min-w-0 flex-1 p-(--page-gutter)"
                    key={location.pathname}
                >
                    <Outlet />
                </main>
            </div>
        </div>
    );
}
