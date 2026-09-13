import { useQuery } from '@tanstack/react-query';
import { useTranslation } from 'react-i18next';

import { platformHealth } from '@/api/health';
import { LocaleControl, ThemeControl } from '@/shell/PreferenceControls';

export interface AuthCoverProps {
    /** Names the step: sign in, verify, enrol. Rendered as the form's heading. */
    title: string;
    description?: string;
    children: React.ReactNode;
    /** Secondary actions, below the form and separated by a rule. */
    footer?: React.ReactNode;
}

/**
 * The authentication cover: one composition across the whole viewport.
 *
 * Not a card on a background. The page is split into two full-height regions — an
 * identity field and a work field — and the form sits *in* the second one rather than
 * floating above it. There is no radius, no shadow and no container: the boundary
 * between the two halves is a single rule, which is the whole visual device.
 *
 * The identity half is near-black in both themes. That is deliberate: it is the same
 * surface the console's navigation uses, so signing in already looks like the product
 * an operator is about to be inside, rather than a marketing page in front of it.
 *
 * What it says is true rather than decorative. The strip along the bottom reports the
 * platform's own liveness probe, live, before anyone has signed in — a running system
 * describing itself. An illustration would have filled the same space and meant
 * nothing.
 *
 * Under 1024px the split becomes a stack: the identity field collapses to a full
 * width band that keeps the wordmark and the status, and the form takes the rest.
 * Still full-bleed, still no card.
 */
export function AuthCover({ title, description, children, footer }: AuthCoverProps) {
    const { t } = useTranslation();

    return (
        <div className="grid min-h-dvh grid-rows-[auto_1fr] lg:grid-cols-[1.15fr_1fr] lg:grid-rows-1">
            <IdentityField />

            <main className="flex flex-col justify-center bg-(--surface-default) px-6 py-10 sm:px-10 lg:px-14">
                <div className="w-full max-w-md">
                    <header className="flex flex-col gap-2">
                        <span data-eyebrow>{t('auth.cover.eyebrow')}</span>
                        <h1 className="text-(length:--text-2xl) text-(--text-primary)">{title}</h1>
                        {description !== undefined ? (
                            <p className="text-(--text-secondary)">{description}</p>
                        ) : null}
                    </header>

                    <div className="mt-6 border-t border-(--border-default) pt-6">{children}</div>

                    {footer !== undefined ? (
                        <div className="mt-6 border-t border-(--border-default) pt-4">{footer}</div>
                    ) : null}

                    <div className="mt-8 flex flex-wrap items-center gap-2 border-t border-(--border-default) pt-4">
                        <ThemeControl />
                        <LocaleControl />
                    </div>
                </div>
            </main>
        </div>
    );
}

/**
 * The identity half: wordmark, statement, and the platform reporting on itself.
 *
 * The hairline grid is drawn with two repeating-linear-gradients rather than an
 * image — it costs nothing, scales to any viewport, and is the one ornamental thing
 * on the page. It is barely visible on purpose; the point is that the surface reads
 * as measured rather than as an empty rectangle.
 */
function IdentityField() {
    const { t } = useTranslation();

    const health = useQuery({
        queryKey: ['platform-health'],
        queryFn: ({ signal }) => platformHealth(signal),
        retry: false,
        staleTime: 30_000,
    });

    return (
        <aside className="relative isolate flex flex-col justify-between overflow-hidden bg-(--surface-chrome) px-6 py-8 text-(--text-on-chrome) sm:px-10 lg:px-14 lg:py-14">
            <div
                aria-hidden
                className="pointer-events-none absolute inset-0 -z-10 opacity-[0.07]"
                style={{
                    backgroundImage:
                        'repeating-linear-gradient(to right, currentColor 0 1px, transparent 1px 96px),' +
                        'repeating-linear-gradient(to bottom, currentColor 0 1px, transparent 1px 96px)',
                }}
            />

            <div className="flex items-center gap-3">
                <span aria-hidden className="h-6 w-(--rail-width) bg-(--brand-on-chrome)" />
                <span className="text-(length:--text-md) font-bold tracking-(--tracking-tight) text-(--text-on-chrome)">
                    {t('app.name')}
                </span>
            </div>

            <div className="my-10 flex max-w-xl flex-col gap-5 lg:my-0">
                <span className="text-(--text-on-chrome-muted)" data-eyebrow>
                    {t('auth.cover.kicker')}
                </span>
                <p className="text-(length:--text-3xl) font-bold leading-(--leading-tight) tracking-(--tracking-tight) text-(--text-on-chrome) lg:text-(length:--text-4xl)">
                    {t('auth.cover.statement')}
                </p>
                <p className="max-w-prose text-(--text-on-chrome-muted)">
                    {t('auth.cover.detail')}
                </p>
            </div>

            <dl className="grid grid-cols-2 gap-x-6 gap-y-3 border-t border-(--border-chrome) pt-5 sm:grid-cols-3">
                <Fact label={t('auth.cover.platform')}>
                    <span className="inline-flex items-center gap-2">
                        <span
                            aria-hidden
                            className={
                                'size-1.5 rounded-full ' +
                                (health.isSuccess
                                    ? 'bg-(--state-success-rail)'
                                    : health.isPending
                                      ? 'bg-(--state-neutral-rail)'
                                      : 'bg-(--state-danger-rail)')
                            }
                        />
                        {health.isPending
                            ? t('state.loading')
                            : health.isSuccess
                              ? t('dashboard.platform.answering')
                              : t('dashboard.platform.notAnswering')}
                    </span>
                </Fact>

                <Fact label={t('auth.cover.build')}>
                    <span data-technical>{health.data?.framework ?? '—'}</span>
                </Fact>

                <Fact className="hidden sm:block" label={t('auth.cover.perimeter')}>
                    {t('auth.cover.perimeterValue')}
                </Fact>
            </dl>
        </aside>
    );
}

function Fact({
    label,
    children,
    className,
}: {
    label: string;
    children: React.ReactNode;
    className?: string;
}) {
    return (
        <div className={className}>
            <dt className="text-(--text-on-chrome-muted)" data-eyebrow>
                {label}
            </dt>
            <dd className="mt-1 text-(length:--text-sm) text-(--text-on-chrome)">{children}</dd>
        </div>
    );
}
