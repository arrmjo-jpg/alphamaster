import { Menu as MenuIcon } from 'lucide-react';
import { useTranslation } from 'react-i18next';

import { AccountMenu } from './AccountMenu';
import { CommandPalette } from './CommandPalette';
import { AppearanceControl, LocaleControl } from './PreferenceControls';

export interface TopbarProps {
    navigationOpen: boolean;
    onToggleNavigation: () => void;
}

/**
 * The top edge of the content column.
 *
 * Search on the inline-start side, the viewer on the inline-end: where to go, and who
 * is going there. Nothing here configures the platform — that is Settings' job — and
 * nothing here is navigation beyond the one jump the search offers.
 *
 * Four controls, and no more. The ones a console of this kind often adds and this one
 * does not, each for a reason rather than an oversight:
 *
 *   notifications — the platform has no inbox; the notifications module manages
 *                   delivery preferences and wording, and a bell with nothing to ring
 *                   would be the most visible dead control in the product
 *   apps / grid   — there is one application
 *
 * All four stay reachable at every width. Below `sm` they shed their words and keep
 * their icons rather than disappearing, and below `md` the product mark joins them,
 * because the sidebar that normally carries it is then a drawer.
 */
export function Topbar({ navigationOpen, onToggleNavigation }: TopbarProps) {
    const { t } = useTranslation();

    return (
        <header className="sticky top-0 z-(--z-sticky) flex h-(--topbar-height) items-center gap-2 border-b border-(--shell-border) bg-(--shell-surface) px-3 sm:px-(--page-gutter)">
            <button
                aria-expanded={navigationOpen}
                aria-label={navigationOpen ? t('shell.closeNavigation') : t('shell.openNavigation')}
                className="flex size-9 shrink-0 items-center justify-center text-(--shell-text-muted) hover:bg-(--shell-hover) hover:text-(--shell-text) md:hidden"
                onClick={onToggleNavigation}
                type="button"
            >
                <MenuIcon aria-hidden className="size-5" />
            </button>

            <span className="flex min-w-0 items-center gap-2 md:hidden">
                <span aria-hidden className="h-5 w-1 shrink-0 bg-(--action-primary)" />
                <span className="hidden truncate font-bold text-(--shell-text) min-[420px]:inline">
                    {t('app.name')}
                </span>
            </span>

            <CommandPalette />

            <div className="ms-auto flex shrink-0 items-center gap-0.5">
                <LocaleControl />
                <AppearanceControl />
                <span aria-hidden className="mx-1.5 hidden h-6 w-px bg-(--shell-border) sm:block" />
                <AccountMenu />
            </div>
        </header>
    );
}
