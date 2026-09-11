import { useQueries } from '@tanstack/react-query';
import { Star } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';

import { ApiError } from '@/api/errors';
import { useCurrentUser } from '@/auth/AuthProvider';
import { cn } from '@/lib/cn';
import {
    integrationProviders,
    integrationUsage,
    usageFor,
    type IntegrationProvider,
    type IntegrationUsage,
} from '@/screens/integrations/api';
import { summarise } from '@/screens/integrations/capabilities';
import { ProviderDetail } from '@/screens/integrations/ProviderDetail';
import { UsagePanel } from '@/screens/integrations/UsagePanel';
import { Alert } from '@/ui/Alert';
import { Button } from '@/ui/Button';
import { StatusBadge } from '@/ui/StatusBadge';
import { TONE_CLASSES, type StateTone } from '@/ui/state';

/**
 * Vendor configuration, organised the way the platform organises it.
 *
 * A capability is the unit that matters: exactly one provider serves it at a time, the
 * rest are alternatives, and the questions an operator has — is this working, which one
 * is answering, what happened last time it was asked — are questions about the
 * capability rather than about any single row. So providers are grouped under theirs,
 * with the capability's own condition stated above them.
 *
 * The condition comes from two sources deliberately. The provider rows say what
 * somebody configured; the usage log says what actually happened. A perfectly
 * configured provider with nothing but failures behind it looks healthy in the first
 * and is not, and that gap is the reason both are read.
 *
 * The two are also asked for separately, and a failure in one does not take the other
 * with it. Losing the activity log costs the half of the judgement that comes from it,
 * and the screen says so rather than presenting configuration alone as a verdict —
 * "nothing has failed" and "I could not look" are the same picture unless the
 * difference is stated.
 *
 * There is no create and no delete, because the API has neither: rows exist for the
 * capabilities the platform can consume, and a control offering to add a third SMS
 * vendor would be offering to write a row no driver could serve.
 */
export function IntegrationsScreen() {
    const { t } = useTranslation();
    const viewer = useCurrentUser();
    const [selectedId, setSelectedId] = useState<string | null>(null);

    const mayUpdate = viewer.permissions.includes('integrations.update');

    const [providers, usage] = useQueries({
        queries: [
            {
                queryKey: ['integration-providers'],
                queryFn: ({ signal }: { signal: AbortSignal }) => integrationProviders(signal),
            },
            {
                queryKey: ['integration-usage'],
                queryFn: ({ signal }: { signal: AbortSignal }) => integrationUsage(signal),
            },
        ],
    });

    // Both, before anything is judged. A request still in flight is not a request
    // that failed, and rendering a capability as "condition unknown" while its
    // activity is merely on its way would say something untrue for as long as the
    // network took.
    if (providers.isPending || usage.isPending) {
        return <LoadingSkeleton />;
    }

    // Only the provider list is fatal. Without it there is no screen; without the
    // activity log there is most of one.
    if (providers.error !== null) {
        return (
            <Screen>
                <Alert title={t('integrations.unavailable')} tone="danger">
                    <p>
                        {providers.error instanceof ApiError
                            ? providers.error.message
                            : t('state.error')}
                    </p>
                    <Button
                        className="mt-2"
                        onClick={() => void providers.refetch()}
                        size="sm"
                        variant="secondary"
                    >
                        {t('state.retry')}
                    </Button>
                </Alert>
            </Screen>
        );
    }

    const rows = providers.data ?? [];
    /** Null where the activity log could not be read, and only there. */
    const attempts: IntegrationUsage[] | null = usage.error !== null ? null : (usage.data ?? []);

    const capabilities = summarise(rows, attempts ?? [], (capability) => capability);
    const selected = rows.find((provider) => provider.id === selectedId) ?? null;

    return (
        <Screen>
            <div className="grid grid-cols-1 gap-(--section-gap) xl:grid-cols-[1fr_var(--panel-width-docked)]">
                <div className="flex min-w-0 flex-col gap-(--section-gap)">
                    {capabilities.length === 0 ? (
                        <EmptyState
                            body={t('integrations.noProvidersNote')}
                            title={t('integrations.noProviders')}
                        />
                    ) : (
                        capabilities.map((capability) => (
                            <section
                                aria-label={capability.label}
                                className="border border-(--border-default) bg-(--surface-default)"
                                key={capability.capability}
                            >
                                <header className="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-1 border-b border-(--border-strong) px-3 py-2.5">
                                    <div className="flex flex-wrap items-center gap-2">
                                        <h2 className="text-(length:--text-lg) text-(--text-primary)">
                                            {capability.label}
                                        </h2>
                                        {/* Configuration alone cannot say whether a
                                            capability is working, so with no activity
                                            log there is no verdict to show. */}
                                        {attempts === null ? (
                                            <StatusBadge tone="neutral">
                                                {t('integrations.state.unknown')}
                                            </StatusBadge>
                                        ) : (
                                            <StatusBadge tone={capability.tone}>
                                                {t(`integrations.state.${capability.state}`)}
                                            </StatusBadge>
                                        )}
                                    </div>
                                    <p className="text-(length:--text-sm) text-(--text-muted)">
                                        {attempts === null
                                            ? t('integrations.activityUnavailableShort')
                                            : t('integrations.attemptsValue', {
                                                  count: capability.attempts,
                                                  failures: capability.failures,
                                              })}
                                    </p>
                                </header>

                                <ul>
                                    {rows
                                        .filter(
                                            (provider) =>
                                                provider.capability === capability.capability,
                                        )
                                        .map((provider) => (
                                            <li key={provider.id}>
                                                <ProviderRow
                                                    onSelect={() => setSelectedId(provider.id)}
                                                    provider={provider}
                                                    selected={provider.id === selectedId}
                                                    usage={
                                                        attempts === null
                                                            ? null
                                                            : usageFor(provider, attempts)
                                                    }
                                                />
                                            </li>
                                        ))}
                                </ul>
                            </section>
                        ))
                    )}

                    <UsagePanel
                        error={usage.error}
                        loading={usage.isPending}
                        onRetry={() => void usage.refetch()}
                        usage={attempts ?? []}
                    />
                </div>

                <aside aria-label={t('integrations.detail')} className="min-w-0">
                    {selected === null ? (
                        <p className="border border-(--border-default) bg-(--surface-default) p-4 text-(--text-muted)">
                            {t('integrations.chooseProvider')}
                        </p>
                    ) : (
                        <ProviderDetail
                            // Keyed by provider, so choosing another one mounts a new
                            // panel rather than carrying the previous provider's
                            // half-finished draft into it.
                            key={selected.id}
                            mayUpdate={mayUpdate}
                            onClose={() => setSelectedId(null)}
                            provider={selected}
                            usage={attempts}
                        />
                    )}
                </aside>
            </div>
        </Screen>
    );
}

/** The page frame, so every state below carries the same heading. */
function Screen({ children }: { children: React.ReactNode }) {
    const { t } = useTranslation();

    return (
        <div className="flex flex-col gap-(--section-gap)">
            <header>
                <p data-eyebrow>{t('integrations.eyebrow')}</p>
                <h1 className="text-(length:--text-2xl) text-(--text-primary)">
                    {t('modules.integrations')}
                </h1>
            </header>
            {children}
        </div>
    );
}

/**
 * The shape the screen is about to be.
 *
 * A skeleton rather than a word, because this layout is known before the data arrives
 * and holding the space is what stops the page jumping when it does.
 */
function LoadingSkeleton() {
    const { t } = useTranslation();

    return (
        <Screen>
            <div
                aria-label={t('state.loading')}
                className="grid grid-cols-1 gap-(--section-gap) xl:grid-cols-[1fr_var(--panel-width-docked)]"
                role="status"
            >
                <div className="flex flex-col gap-(--section-gap)">
                    {[0, 1].map((block) => (
                        <div
                            className="border border-(--border-default) bg-(--surface-default)"
                            key={block}
                        >
                            <div className="border-b border-(--border-strong) px-3 py-2.5">
                                <span className="block h-4 w-40 animate-pulse bg-(--action-secondary)" />
                            </div>
                            <div className="flex flex-col gap-2 p-3">
                                <span className="h-4 w-2/3 animate-pulse bg-(--action-secondary)" />
                                <span className="h-4 w-1/2 animate-pulse bg-(--action-secondary)" />
                            </div>
                        </div>
                    ))}
                </div>
                <div className="border border-(--border-default) bg-(--surface-default) p-3">
                    <span className="block h-4 w-1/2 animate-pulse bg-(--action-secondary)" />
                </div>
            </div>
        </Screen>
    );
}

function EmptyState({ title, body }: { title: string; body: string }) {
    return (
        <div className="border border-(--border-default) bg-(--surface-default) p-6">
            <p className="text-(length:--text-md) text-(--text-primary)">{title}</p>
            <p className="mt-1 max-w-prose text-(--text-muted)">{body}</p>
        </div>
    );
}

/**
 * One provider, as a row.
 *
 * The rail carries the row's own condition rather than its capability's: a switched-off
 * alternative sitting under a healthy capability is neither healthy nor a problem, and
 * a column of rails that all said the same thing would say nothing.
 */
function ProviderRow({
    provider,
    usage,
    selected,
    onSelect,
}: {
    provider: IntegrationProvider;
    /** Null where the activity log could not be read. */
    usage: readonly IntegrationUsage[] | null;
    selected: boolean;
    onSelect: () => void;
}) {
    const { t } = useTranslation();

    const failures =
        usage === null ? 0 : usage.filter((entry) => entry.status === 'failure').length;
    const tone = providerTone(provider, usage === null ? null : usage.length, failures);

    return (
        <button
            aria-current={selected}
            className={cn(
                'relative w-full border-b border-(--border-default) px-3 py-2.5 ps-4 text-start last:border-b-0',
                'transition-colors duration-100 ease-out',
                'focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-(--focus-ring)',
                selected ? 'bg-(--action-secondary)' : 'hover:bg-(--action-ghost-hover)',
            )}
            onClick={onSelect}
            type="button"
        >
            <span
                aria-hidden
                className={cn(
                    'absolute inset-y-0 start-0 w-(--rail-width)',
                    TONE_CLASSES[tone].rail,
                )}
            />

            <span className="flex flex-wrap items-baseline gap-x-2 gap-y-1">
                <span className="font-medium text-(--text-primary)">{provider.label}</span>
                <span className="text-(length:--text-xs) text-(--text-muted)" data-technical>
                    {provider.driver}
                </span>
            </span>

            <span className="mt-1 flex flex-wrap items-center gap-1.5">
                {provider.is_default ? (
                    <StatusBadge icon={<Star className="size-3" />} tone="info">
                        {t('integrations.default')}
                    </StatusBadge>
                ) : null}
                <StatusBadge tone={provider.is_active ? 'success' : 'neutral'}>
                    {provider.is_active ? t('integrations.active') : t('integrations.inactive')}
                </StatusBadge>
                <StatusBadge tone={provider.has_credentials ? 'success' : 'warning'}>
                    {provider.has_credentials
                        ? t('integrations.credentials.configured')
                        : t('integrations.credentials.unconfigured')}
                </StatusBadge>
                <span className="text-(length:--text-xs) text-(--text-muted)">
                    {usage === null
                        ? t('integrations.activityUnavailableShort')
                        : t('integrations.attemptsValue', { count: usage.length, failures })}
                </span>
            </span>
        </button>
    );
}

/**
 * A single provider's condition.
 *
 * Deliberately not the capability judgement applied to one row: an inactive provider is
 * not "unconfigured", it is an alternative somebody switched off.
 *
 * An active provider holding no credentials is a warning, and no exception is made for
 * the drivers that happen not to need any. Which ones those are is not something the
 * platform publishes — nothing in the provider resource says whether a driver requires
 * credentials — so the alternative would be this console keeping its own list of driver
 * names, which would be wrong the first time one was added. The dashboard's attention
 * check already reads it exactly this way.
 *
 * `attempts` is null where the activity log could not be read, and the answer then
 * stops at what configuration alone can support.
 */
function providerTone(
    provider: IntegrationProvider,
    attempts: number | null,
    failures: number,
): StateTone {
    if (!provider.is_active) {
        return 'neutral';
    }

    if (!provider.has_credentials) {
        return 'warning';
    }

    if (attempts === null) {
        return 'neutral';
    }

    if (attempts > 0 && failures === attempts) {
        return 'danger';
    }

    if (failures > 0) {
        return 'warning';
    }

    return attempts === 0 ? 'info' : 'success';
}
