import { CheckCircle2 } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { Link } from 'react-router';

import { cn } from '@/lib/cn';
import { Panel } from '@/ui/Panel';
import { TONE_CLASSES } from '@/ui/state';

import type { Attention, Finding } from './attention';
import { destinationFor } from './attention';

export interface AttentionPanelProps {
    attention: Attention;
    loading: boolean;
    /** The account's own grants, so a destination is only offered where it works. */
    permissions: readonly string[];
}

/**
 * The one region on this screen an operator reads first.
 *
 * It is empty most days, and that is the point: a panel that always has something in
 * it stops being read. So nothing lands here that an operator could reasonably decide
 * to ignore — the counts, the totals and the merely interesting all belong further
 * down the page.
 *
 * When it is empty it names the checks it made. "All clear" that quietly excludes
 * what the account could not read is the failure mode this whole panel exists to
 * avoid.
 */
export function AttentionPanel({ attention, loading, permissions }: AttentionPanelProps) {
    const { t } = useTranslation();

    const critical = attention.findings.filter((f) => f.severity === 'critical').length;

    return (
        <Panel
            aside={
                attention.findings.length === 0
                    ? undefined
                    : t('dashboard.attention.count', { count: attention.findings.length })
            }
            loading={loading}
            title={t('dashboard.attention.title')}
        >
            {attention.findings.length === 0 ? (
                <Clear attention={attention} />
            ) : (
                <ul className="flex flex-col divide-y divide-(--border-default)">
                    {attention.findings.map((finding) => (
                        <FindingRow finding={finding} key={finding.id} permissions={permissions} />
                    ))}
                </ul>
            )}

            {critical > 0 ? (
                // Said in words as well as in the rails, because the rails are the
                // only other place severity appears and colour is not a channel
                // everyone has.
                <p className="sr-only" role="status">
                    {t('dashboard.attention.criticalCount', { count: critical })}
                </p>
            ) : null}
        </Panel>
    );
}

function FindingRow({
    finding,
    permissions,
}: {
    finding: Finding;
    permissions: readonly string[];
}) {
    const { t } = useTranslation();
    const tone = finding.severity === 'critical' ? 'danger' : 'warning';
    const destination = destinationFor(finding);
    const reachable = destination !== null && permissions.includes(destination.permission);

    return (
        <li className="relative py-2.5 ps-3 first:pt-0 last:pb-0">
            <span
                aria-hidden
                className={cn(
                    'absolute inset-y-0 start-0 w-(--rail-width)',
                    TONE_CLASSES[tone].rail,
                )}
            />

            <div className="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-1">
                <p className={cn('font-medium', TONE_CLASSES[tone].text)}>
                    {t(`dashboard.attention.finding.${finding.kind}`, { count: finding.count })}
                </p>

                {reachable ? (
                    <Link
                        className="text-(length:--text-sm) text-(--action-primary) underline underline-offset-2"
                        to={destination.path}
                    >
                        {t(`dashboard.attention.go.${finding.kind}`)}
                    </Link>
                ) : null}
            </div>

            {finding.subjects.length > 0 ? (
                // Named, not counted. "Three administrators are locked out" is a
                // number; the three names are the work.
                <p className="mt-0.5 text-(length:--text-sm) text-(--text-secondary)">
                    {finding.subjects.join(t('list.separator'))}
                </p>
            ) : null}

            <p className="mt-0.5 text-(length:--text-xs) text-(--text-muted)">
                {t(`dashboard.attention.why.${finding.kind}`)}
            </p>
        </li>
    );
}

/**
 * Nothing to report, and the exact extent of "nothing".
 *
 * The skipped checks are listed by name. An operator with a narrow set of grants sees
 * a clear board covering less than they might assume, and this is where they find
 * that out.
 */
function Clear({ attention }: { attention: Attention }) {
    const { t } = useTranslation();

    return (
        <div className="flex items-start gap-2">
            <CheckCircle2
                aria-hidden
                className="mt-0.5 size-4 shrink-0 text-(--state-success-rail)"
            />
            <div className="flex min-w-0 flex-col gap-1">
                <p className="text-(--text-primary)">{t('dashboard.attention.clear')}</p>

                <p className="text-(length:--text-xs) text-(--text-muted)">
                    {t('dashboard.attention.checked', {
                        checks: attention.checked
                            .map((check) => t(`dashboard.attention.check.${check}`))
                            .join(t('list.separator')),
                    })}
                </p>

                {attention.skipped.length > 0 ? (
                    <p className="text-(length:--text-xs) text-(--state-warning-text)">
                        {t('dashboard.attention.skipped', {
                            checks: attention.skipped
                                .map((check) => t(`dashboard.attention.check.${check}`))
                                .join(t('list.separator')),
                        })}
                    </p>
                ) : null}
            </div>
        </div>
    );
}
