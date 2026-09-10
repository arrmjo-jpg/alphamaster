import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useTranslation } from 'react-i18next';

import { ApiError } from '@/api/errors';
import { useCurrentUser } from '@/auth/AuthProvider';
import { absoluteTime } from '@/lib/time';
import { aiState, checkAi, type AiCheck } from '@/screens/ai/api';
import { Alert } from '@/ui/Alert';
import { Button } from '@/ui/Button';
import { StateRail } from '@/ui/StateRail';
import { StatusBadge } from '@/ui/StatusBadge';

/**
 * The AI control centre.
 *
 * Three questions, kept apart because running them together sends an operator to fix
 * the wrong thing:
 *
 *   * **Is a vendor selected?** A provider row, active and marked default.
 *   * **Does it hold a credential?** A boolean. Never the key, never a masked form of
 *     it — the API has never read one back and this screen does not ask it to.
 *   * **Does it answer?** Only knowable by asking, so it is reported from the last
 *     attempt and there is a button to ask again.
 *
 * Configuring happens on the Integrations screen and in Settings, which is why this
 * one has no form: the vendor is a provider row, the model is a setting, and giving
 * either a second control here would be a second place to disagree with the first.
 */
export function AiScreen() {
    const { t, i18n } = useTranslation();
    const viewer = useCurrentUser();
    const queryClient = useQueryClient();

    const mayCheck = viewer.permissions.includes('ai.use');

    const state = useQuery({
        queryKey: ['ai-state'],
        queryFn: ({ signal }) => aiState(signal),
    });

    const check = useMutation({
        mutationFn: () => checkAi(),
        onSuccess: () => queryClient.invalidateQueries({ queryKey: ['ai-state'] }),
    });

    return (
        <div className="flex min-w-0 flex-col gap-(--section-gap)">
            <header>
                <p data-eyebrow>{t('ai.eyebrow')}</p>
                <h1 className="text-(length:--text-2xl) text-(--text-primary)">
                    {t('modules.ai')}
                </h1>
                <p className="mt-1 max-w-prose text-(length:--text-sm) text-(--text-secondary)">
                    {t('ai.description')}
                </p>
            </header>

            {state.isPending ? <StateRail tone="info">{t('state.loading')}</StateRail> : null}

            {state.error !== null ? (
                <Alert tone="danger">
                    {state.error instanceof ApiError ? state.error.message : t('state.error')}
                </Alert>
            ) : null}

            {state.data !== undefined ? (
                <>
                    <section className="flex max-w-prose flex-col gap-3 border border-(--border-default) bg-(--surface-raised) p-4">
                        <div className="flex flex-wrap items-baseline justify-between gap-2">
                            <p data-eyebrow>{t('ai.provider')}</p>
                            <StatusBadge tone={state.data.configured ? 'success' : 'warning'}>
                                {state.data.configured ? t('ai.ready') : t('ai.notReady')}
                            </StatusBadge>
                        </div>

                        {state.data.provider === null ? (
                            <p className="text-(length:--text-sm) text-(--text-secondary)">
                                {t('ai.noProvider')}
                            </p>
                        ) : (
                            <>
                                <p className="text-(length:--text-md) text-(--text-primary)">
                                    {state.data.provider.label}
                                </p>
                                <p
                                    className="text-(length:--text-xs) text-(--text-muted)"
                                    data-technical
                                >
                                    {state.data.provider.driver}
                                </p>
                                {/* Two separate facts. A vendor chosen without a key
                                    and a vendor that is unreachable look identical from
                                    the outside and have nothing in common. */}
                                <div className="flex flex-wrap gap-1.5">
                                    <StatusBadge
                                        tone={
                                            state.data.provider.has_credentials
                                                ? 'success'
                                                : 'warning'
                                        }
                                    >
                                        {state.data.provider.has_credentials
                                            ? t('ai.credentialStored')
                                            : t('ai.credentialMissing')}
                                    </StatusBadge>
                                </div>
                            </>
                        )}

                        <p className="text-(length:--text-xs) text-(--text-muted)">
                            {t('ai.configuredElsewhere')}
                        </p>

                        {state.data.available_drivers.length > 0 ? (
                            <p className="text-(length:--text-xs) text-(--text-muted)">
                                {t('ai.availableDrivers', {
                                    drivers: state.data.available_drivers.join(t('list.separator')),
                                })}
                            </p>
                        ) : null}
                    </section>

                    <section className="flex max-w-prose flex-col gap-3 border border-(--border-default) bg-(--surface-raised) p-4">
                        <p data-eyebrow>{t('ai.lastAttempt')}</p>

                        {state.data.last_attempt === null ? (
                            <p className="text-(length:--text-sm) text-(--text-secondary)">
                                {t('ai.neverAsked')}
                            </p>
                        ) : (
                            <>
                                <div className="flex flex-wrap items-center gap-2">
                                    <StatusBadge
                                        tone={
                                            state.data.last_attempt.status === 'success'
                                                ? 'success'
                                                : 'danger'
                                        }
                                    >
                                        {state.data.last_attempt.status === 'success'
                                            ? t('ai.answered')
                                            : t('ai.didNotAnswer')}
                                    </StatusBadge>
                                    <span className="text-(length:--text-xs) text-(--text-muted)">
                                        {absoluteTime(state.data.last_attempt.at, i18n.language) ??
                                            ''}
                                    </span>
                                </div>

                                {state.data.last_attempt.error_message !== null ? (
                                    <p className="text-(length:--text-sm) text-(--text-secondary)">
                                        {state.data.last_attempt.error_message}
                                    </p>
                                ) : null}

                                {state.data.last_attempt.units !== null ? (
                                    <p className="text-(length:--text-xs) text-(--text-muted)">
                                        {/* Units, not money. Prices change, and a
                                            stored cost is wrong retroactively. */}
                                        {t('ai.units', { count: state.data.last_attempt.units })}
                                    </p>
                                ) : null}
                            </>
                        )}

                        {state.data.recent_failures > 0 ? (
                            <Alert tone="warning">
                                {t('ai.recentFailures', { count: state.data.recent_failures })}
                            </Alert>
                        ) : null}

                        {mayCheck ? (
                            <div className="flex flex-col gap-2">
                                <div>
                                    <Button
                                        loading={check.isPending}
                                        onClick={() => check.mutate()}
                                        size="sm"
                                        variant="secondary"
                                    >
                                        {t('ai.check')}
                                    </Button>
                                </div>
                                <p className="text-(length:--text-xs) text-(--text-muted)">
                                    {t('ai.checkNote')}
                                </p>
                                <CheckOutcome outcome={check.data} error={check.error} />
                            </div>
                        ) : (
                            <p className="text-(length:--text-xs) text-(--text-muted)">
                                {t('ai.checkNeedsPermission')}
                            </p>
                        )}
                    </section>
                </>
            ) : null}
        </div>
    );
}

function CheckOutcome({ outcome, error }: { outcome: AiCheck | undefined; error: Error | null }) {
    const { t } = useTranslation();

    if (error !== null) {
        return (
            <Alert tone="danger">
                {error instanceof ApiError ? error.message : t('state.error')}
            </Alert>
        );
    }

    if (outcome === undefined) {
        return null;
    }

    // The check ran either way. What it found is the payload, not the status code —
    // a failed check is a successful diagnostic.
    return outcome.answered ? (
        <Alert tone="success">{t('ai.checkAnswered', { driver: outcome.driver })}</Alert>
    ) : (
        <Alert tone="danger">
            {outcome.error_message ?? t('ai.checkFailed')}
            {outcome.error_code !== null ? (
                <span className="ms-1 text-(length:--text-xs)" data-technical>
                    ({outcome.error_code})
                </span>
            ) : null}
        </Alert>
    );
}
