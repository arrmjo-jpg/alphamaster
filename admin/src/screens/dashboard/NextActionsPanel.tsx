import { ArrowRight } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { Link } from 'react-router';

import { Panel } from '@/ui/Panel';

import type { Attention } from './attention';
import { destinationFor } from './attention';
import type { CapabilityRow } from '@/screens/integrations/capabilities';

export interface NextActionsPanelProps {
    attention: Attention;
    capabilities: CapabilityRow[] | null;
    permissions: readonly string[];
    loading: boolean;
    headingLevel?: 2 | 3;
}

interface Action {
    id: string;
    path: string;
    permission: string;
    count: number;
}

/**
 * The work this console can actually carry out, given what the state says.
 *
 * Every entry is a route that exists, reachable by this account, addressing something
 * observed in the data — not a checklist, not onboarding, and not a list of things
 * somebody might like to do one day. If the platform is quiet and correctly
 * configured this panel is empty, and it says so plainly rather than manufacturing
 * suggestions to fill itself.
 *
 * It is deliberately not a copy of the attention panel. A finding can be urgent with
 * nowhere in this product to act on it — a silent platform is the standing example,
 * since nothing in an admin console restarts one — and a next action can be worth
 * doing without anything being wrong: mail configured and never once exercised is the
 * state most easily mistaken for working, and sending a test is exactly what to do
 * about it.
 */
export function NextActionsPanel({
    attention,
    capabilities,
    permissions,
    loading,
    headingLevel,
}: NextActionsPanelProps) {
    const { t } = useTranslation();

    const actions: Action[] = [];

    for (const finding of attention.findings) {
        const destination = destinationFor(finding);

        if (destination !== null && permissions.includes(destination.permission)) {
            actions.push({
                id: finding.kind,
                path: destination.path,
                permission: destination.permission,
                count: finding.count,
            });
        }
    }

    // Configured and never exercised. Not a fault, so it is not in the attention
    // panel; still the one thing worth doing before trusting it.
    const idleMail = (capabilities ?? []).find(
        (row) => row.state === 'idle' && /mail/i.test(row.label),
    );

    if (
        idleMail !== undefined &&
        permissions.includes('settings.view') &&
        !actions.some((action) => action.path === '/settings/mail')
    ) {
        actions.push({
            id: 'test-mail',
            path: '/settings/mail',
            permission: 'settings.view',
            count: 1,
        });
    }

    return (
        <Panel
            {...(headingLevel === undefined ? {} : { headingLevel })}
            loading={loading}
            title={t('dashboard.next.title')}
        >
            {actions.length === 0 ? (
                <div className="flex flex-col gap-1">
                    <p className="text-(--text-primary)">{t('dashboard.next.none')}</p>
                    {/* Why it is empty, because "no suggestions" and "nothing this
                        console can do about it" look identical otherwise. */}
                    <p className="text-(length:--text-xs) text-(--text-muted)">
                        {t('dashboard.next.noneNote')}
                    </p>
                </div>
            ) : (
                <ul className="flex flex-col divide-y divide-(--border-default)">
                    {actions.map((action) => (
                        <li key={action.id}>
                            <Link
                                className="group flex items-baseline justify-between gap-3 py-2.5 first:pt-0 hover:bg-(--action-ghost-hover)"
                                to={action.path}
                            >
                                <span className="min-w-0">
                                    <span className="block text-(--text-primary) underline-offset-2 group-hover:underline">
                                        {t(`dashboard.next.action.${action.id}`, {
                                            count: action.count,
                                        })}
                                    </span>
                                    <span className="block text-(length:--text-xs) text-(--text-muted)">
                                        {t(`dashboard.next.because.${action.id}`, {
                                            count: action.count,
                                        })}
                                    </span>
                                </span>
                                <ArrowRight
                                    aria-hidden
                                    className="size-4 shrink-0 text-(--text-muted) rtl:rotate-180"
                                />
                            </Link>
                        </li>
                    ))}
                </ul>
            )}
        </Panel>
    );
}
