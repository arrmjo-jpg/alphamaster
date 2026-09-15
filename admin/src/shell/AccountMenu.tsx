import { BadgeCheck, CircleAlert, LogOut, UserRound } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { useNavigate } from 'react-router';

import { useAuth, useCurrentUser } from '@/auth/AuthProvider';
import { cn } from '@/lib/cn';
import { MODULES } from '@/modules/registry';
import { Menu, MenuItem, MenuSeparator } from '@/ui/Menu';
import { StatusBadge } from '@/ui/StatusBadge';

import { initialsOf } from './initials';

function Avatar({ name, url, size }: { name: string; url: string | null; size: 'sm' | 'lg' }) {
    const box = size === 'sm' ? 'size-8 text-(length:--text-sm)' : 'size-11 text-(length:--text-lg)';

    // The picture when the account has one that is ready to serve, and the initials otherwise:
    // a picture still being processed is null from the platform, not a broken image here.
    if (url !== null) {
        return <img alt="" aria-hidden className={cn('shrink-0 object-cover', box)} src={url} />;
    }

    return (
        <span
            aria-hidden
            className={cn(
                'inline-flex shrink-0 items-center justify-center bg-(--action-primary-subtle) font-bold text-(--text-brand)',
                box,
            )}
        >
            {initialsOf(name)}
        </span>
    );
}

/**
 * Who is signed in, and the things that belong to them.
 *
 * The signed-in account is not a module. It has no place in the navigation, which
 * answers "where do I go in this system" — an account is not somewhere you go, it is
 * whose session this is. So it lives at the inline-end of the top bar, where every
 * console of this kind puts it, behind a square avatar that carries the account's
 * initials in the brand's wash.
 *
 * Sign-out moved with it. As a bare button in the top bar it was one mis-click from a
 * lost session, sitting next to preference controls that are safe to press; behind a
 * menu it takes an open and a choice, which is the right amount of friction for the one
 * control here that ends the session.
 *
 * The account's own page is linked from here, because the account page exists now and
 * this is where a page about the viewer belongs — not in the navigation, which answers
 * "where do I go in this system". The link is drawn only when the registry declares the
 * module, so a build without it gets no entry pointing at nothing.
 *
 * The account page is also where the password, the picture and the second factor are
 * managed (ADR 0057), so one entry leads to all of it rather than a separate Security item
 * pointing at a section of the same page. The avatar is the account's picture when it has
 * one ready to serve, and its initials otherwise.
 */
export function AccountMenu() {
    const { t } = useTranslation();
    const { signOut } = useAuth();
    const user = useCurrentUser();
    const navigate = useNavigate();

    // Found in the registry rather than written as a path, so the entry follows the
    // module wherever it is declared — and disappears if it ever is not.
    const accountPage = MODULES.find((module) => module.id === 'account');

    return (
        <Menu
            label={t('account.label')}
            panelClassName="min-w-64"
            trigger={
                <>
                    <Avatar name={user.name} size="sm" url={user.avatar_url ?? null} />
                    <span className="hidden max-w-32 truncate text-(--shell-text) lg:inline">
                        {user.name}
                    </span>
                </>
            }
        >
            {/* Identity, not a choice: the panel opens onto who you are, and nothing
                here is focusable because none of it does anything. */}
            <div className="flex items-start gap-3 px-3 py-3">
                <Avatar name={user.name} size="lg" url={user.avatar_url ?? null} />
                <div className="flex min-w-0 flex-col gap-0.5">
                    <span className="truncate text-(length:--text-md) font-bold text-(--text-primary)">
                        {user.name}
                    </span>
                    <span className="text-(length:--text-sm) break-all text-(--text-secondary)">
                        {user.email}
                    </span>
                    <span className="pt-1">
                        {user.email_verified ? (
                            <StatusBadge icon={<BadgeCheck className="size-3" />} tone="success">
                                {t('account.emailVerified')}
                            </StatusBadge>
                        ) : (
                            <StatusBadge icon={<CircleAlert className="size-3" />} tone="warning">
                                {t('account.emailUnverified')}
                            </StatusBadge>
                        )}
                    </span>
                </div>
            </div>

            {accountPage !== undefined ? (
                <>
                    <MenuSeparator />

                    <MenuItem
                        icon={<UserRound className="size-4" />}
                        onSelect={() => void navigate(accountPage.path)}
                    >
                        {t(accountPage.label)}
                    </MenuItem>
                </>
            ) : null}

            <MenuSeparator />

            <MenuItem
                icon={<LogOut className="size-4" />}
                onSelect={() => void signOut()}
                tone="danger"
            >
                {t('auth.signOut')}
            </MenuItem>
        </Menu>
    );
}
