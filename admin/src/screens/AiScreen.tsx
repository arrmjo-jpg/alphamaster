import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { KeyRound, Star } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';

import { ApiError } from '@/api/errors';
import { useCurrentUser } from '@/auth/AuthProvider';
import { absoluteTime } from '@/lib/time';
import {
    aiState,
    checkAi,
    makeAiDefault,
    removeAiKey,
    saveAiProvider,
    type AiCheck,
    type AiProvider,
    type AiState,
} from '@/screens/ai/api';
import { Alert } from '@/ui/Alert';
import { Button } from '@/ui/Button';
import { Input } from '@/ui/Input';
import { StateRail } from '@/ui/StateRail';
import { StatusBadge } from '@/ui/StatusBadge';

/** The option that turns the model dropdown into a typed model ID. */
const OTHER_MODEL = '__other__';

const FIELD =
    'h-(--field-height) w-full border border-(--border-strong) bg-(--surface-default) px-2 text-(length:--text-sm) text-(--text-primary) focus-visible:outline-2 focus-visible:outline-offset-0 focus-visible:outline-(--focus-ring)';

/**
 * The AI control centre: set up each provider, test it, choose which one answers.
 *
 * One small form — provider, API key, model, test, save — and each provider keeps its
 * own. The provider dropdown chooses which one is being edited; it does not switch any
 * other provider off. Several can be configured at once; a provider is ready when it
 * holds a key, and one is the default: the one the platform's AI tasks use, with no
 * automatic switching between them. How a driver reaches its vendor — an address, a
 * header, what it calls its credential — is the platform's, and is never asked for.
 *
 * The key is never shown. The platform reports only whether one is stored; typing a
 * new one replaces it, and leaving the field empty keeps it.
 */
export function AiScreen() {
    const { t } = useTranslation();
    const viewer = useCurrentUser();
    const mayConfigure = viewer.permissions.includes('integrations.update');
    const mayCheck = viewer.permissions.includes('ai.use');

    const state = useQuery({
        queryKey: ['ai-state'],
        queryFn: ({ signal }) => aiState(signal),
    });

    const [selected, setSelected] = useState<string | null>(null);

    const providers = state.data?.providers ?? [];
    const editing =
        providers.find((provider) => provider.driver === selected) ??
        providers.find((provider) => provider.is_default) ??
        providers[0];

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
                <div className="grid grid-cols-1 gap-(--section-gap) xl:grid-cols-[minmax(0,1fr)_minmax(0,1fr)]">
                    <div className="flex min-w-0 flex-col gap-(--section-gap)">
                        <Providers
                            mayConfigure={mayConfigure}
                            onConfigure={setSelected}
                            providers={providers}
                        />
                        <Activity mayCheck={mayCheck} state={state.data} />
                    </div>

                    {editing === undefined ? null : mayConfigure ? (
                        <Setup
                            // Keyed by provider, so choosing another one starts from that
                            // provider's own saved model and key state, never the last one's.
                            key={editing.driver}
                            mayCheck={mayCheck}
                            onSelect={setSelected}
                            provider={editing}
                            providers={providers}
                        />
                    ) : (
                        <p className="border border-(--border-default) bg-(--surface-raised) p-4 text-(length:--text-sm) text-(--text-muted)">
                            {t('ai.setup.needsPermission')}
                        </p>
                    )}
                </div>
            ) : null}
        </div>
    );
}

function Providers({
    providers,
    mayConfigure,
    onConfigure,
}: {
    providers: AiProvider[];
    mayConfigure: boolean;
    onConfigure: (driver: string) => void;
}) {
    const { t } = useTranslation();
    const queryClient = useQueryClient();

    const promote = useMutation({
        mutationFn: (driver: string) => makeAiDefault(driver),
        onSuccess: () => queryClient.invalidateQueries({ queryKey: ['ai-state'] }),
    });

    return (
        <section className="flex flex-col gap-3 border border-(--border-default) bg-(--surface-raised) p-4">
            <div>
                <h2 className="text-(length:--text-md) font-medium text-(--text-primary)">
                    {t('ai.providersTitle')}
                </h2>
                <p className="mt-1 text-(length:--text-sm) text-(--text-muted)">
                    {t('ai.providersNote')}
                </p>
            </div>

            <ul className="divide-y divide-(--border-default) border border-(--border-default) bg-(--surface-default)">
                {providers.map((provider) => (
                    <li
                        className="flex flex-wrap items-center justify-between gap-3 px-3 py-2.5"
                        key={provider.driver}
                    >
                        <div className="flex min-w-0 flex-col gap-1">
                            <span className="font-medium text-(--text-primary)">
                                {provider.label}
                            </span>
                            <span
                                className="text-(length:--text-xs) text-(--text-muted)"
                                data-technical
                            >
                                {provider.effective_model}
                            </span>
                            <span className="flex flex-wrap gap-1">
                                <StatusBadge tone={provider.has_key ? 'success' : 'neutral'}>
                                    {provider.has_key ? t('ai.keyStored') : t('ai.noKey')}
                                </StatusBadge>
                                {provider.is_default ? (
                                    <StatusBadge icon={<Star className="size-3" />} tone="info">
                                        {t('ai.isDefault')}
                                    </StatusBadge>
                                ) : null}
                            </span>
                        </div>

                        {mayConfigure ? (
                            <div className="flex flex-wrap gap-2">
                                <Button
                                    onClick={() => onConfigure(provider.driver)}
                                    size="sm"
                                    variant="secondary"
                                >
                                    {t('ai.configure')}
                                </Button>
                                {provider.is_default || !provider.has_key ? null : (
                                    <Button
                                        loading={
                                            promote.isPending &&
                                            promote.variables === provider.driver
                                        }
                                        onClick={() => promote.mutate(provider.driver)}
                                        size="sm"
                                        variant="ghost"
                                    >
                                        <Star aria-hidden className="size-3.5" />
                                        {t('ai.makeDefault')}
                                    </Button>
                                )}
                            </div>
                        ) : null}
                    </li>
                ))}
            </ul>

            {promote.error instanceof ApiError ? (
                <Alert tone="danger">{promote.error.message}</Alert>
            ) : null}
        </section>
    );
}

/**
 * One provider's setup.
 *
 * Starts from what the platform holds for this provider — its saved model, or its own
 * default — so a model belonging to one vendor is never carried over to another.
 */
function Setup({
    provider,
    providers,
    mayCheck,
    onSelect,
}: {
    provider: AiProvider;
    providers: AiProvider[];
    mayCheck: boolean;
    onSelect: (driver: string) => void;
}) {
    const { t } = useTranslation();
    const queryClient = useQueryClient();

    const initialModel = provider.model ?? provider.default_model;
    const suggested = provider.suggested_models.includes(initialModel);

    const [apiKey, setApiKey] = useState('');
    const [choice, setChoice] = useState(suggested ? initialModel : OTHER_MODEL);
    const [typedModel, setTypedModel] = useState(suggested ? '' : initialModel);
    const [confirmingRemoval, setConfirmingRemoval] = useState(false);

    const model = (choice === OTHER_MODEL ? typedModel : choice).trim();
    const key = apiKey.trim();

    const refresh = async () => {
        await queryClient.invalidateQueries({ queryKey: ['ai-state'] });
        await queryClient.invalidateQueries({ queryKey: ['translation-overview'] });
    };

    const test = useMutation({
        mutationFn: () =>
            checkAi({
                provider: provider.driver,
                model,
                ...(key === '' ? {} : { api_key: key }),
            }),
    });

    const save = useMutation({
        mutationFn: () =>
            saveAiProvider(provider.driver, {
                model,
                ...(key === '' ? {} : { api_key: key }),
            }),
        onSuccess: async () => {
            // The key is not kept in the form once the platform holds it.
            setApiKey('');
            await refresh();
        },
    });

    const remove = useMutation({
        mutationFn: () => removeAiKey(provider.driver),
        onSuccess: async () => {
            setConfirmingRemoval(false);
            await refresh();
        },
    });

    const canTest = mayCheck && model !== '' && (key !== '' || provider.has_key);
    const canSave = model !== '' && (key !== '' || provider.has_key);

    return (
        <section className="flex min-w-0 flex-col gap-4 border border-(--border-default) bg-(--surface-raised) p-4">
            <h2 className="text-(length:--text-md) font-medium text-(--text-primary)">
                {t('ai.setupTitle')}
            </h2>

            <div className="flex flex-col gap-1">
                <label
                    className="text-(length:--text-sm) font-medium text-(--text-secondary)"
                    htmlFor="ai-provider"
                >
                    {t('ai.setup.provider')}
                </label>
                <select
                    className={FIELD}
                    id="ai-provider"
                    onChange={(event) => onSelect(event.target.value)}
                    value={provider.driver}
                >
                    {providers.map((option) => (
                        <option key={option.driver} value={option.driver}>
                            {option.label}
                        </option>
                    ))}
                </select>
            </div>

            <div className="flex flex-col gap-1">
                <label
                    className="text-(length:--text-sm) font-medium text-(--text-secondary)"
                    htmlFor="ai-key"
                >
                    {t('ai.setup.apiKey', { provider: provider.label })}
                </label>
                <Input
                    autoComplete="new-password"
                    id="ai-key"
                    onChange={(event) => setApiKey(event.target.value)}
                    spellCheck={false}
                    type="password"
                    value={apiKey}
                />
                <p className="text-(length:--text-xs) text-(--text-muted)">
                    {provider.has_key
                        ? t('ai.setup.apiKeyStored')
                        : t('ai.setup.apiKeyNew', { provider: provider.label })}
                </p>
            </div>

            <div className="flex flex-col gap-1">
                <label
                    className="text-(length:--text-sm) font-medium text-(--text-secondary)"
                    htmlFor="ai-model"
                >
                    {t('ai.setup.model')}
                </label>
                <select
                    className={FIELD}
                    id="ai-model"
                    onChange={(event) => setChoice(event.target.value)}
                    value={choice}
                >
                    {provider.suggested_models.map((suggestion) => (
                        <option key={suggestion} value={suggestion}>
                            {suggestion === provider.default_model
                                ? t('ai.setup.defaultModel', { model: suggestion })
                                : suggestion}
                        </option>
                    ))}
                    <option value={OTHER_MODEL}>{t('ai.setup.otherModel')}</option>
                </select>
                {choice === OTHER_MODEL ? (
                    <Input
                        aria-label={t('ai.setup.customModel')}
                        data-technical
                        id="ai-model-custom"
                        onChange={(event) => setTypedModel(event.target.value)}
                        placeholder={provider.default_model}
                        spellCheck={false}
                        value={typedModel}
                    />
                ) : null}
                <p className="text-(length:--text-xs) text-(--text-muted)">
                    {t('ai.setup.modelHint', { provider: provider.label })}
                </p>
            </div>

            <div className="flex flex-wrap items-center gap-2">
                <Button
                    disabled={!canTest}
                    loading={test.isPending}
                    onClick={() => test.mutate()}
                    variant="secondary"
                >
                    {t('ai.setup.test')}
                </Button>
                <Button
                    disabled={!canSave}
                    loading={save.isPending}
                    onClick={() => save.mutate()}
                    variant="primary"
                >
                    {t('ai.setup.save')}
                </Button>
            </div>
            <p className="text-(length:--text-xs) text-(--text-muted)">
                {mayCheck ? t('ai.setup.testNote') : t('ai.setup.testNeedsPermission')}
            </p>

            <TestOutcome error={test.error} outcome={test.data} provider={provider.label} />

            {save.error instanceof ApiError ? (
                <Alert tone="danger">{save.error.message}</Alert>
            ) : null}
            {save.data !== undefined && save.isSuccess ? (
                <Alert tone="success">
                    {save.data.is_default
                        ? t('ai.setup.savedDefault', { provider: provider.label })
                        : t('ai.setup.saved', { provider: provider.label })}
                </Alert>
            ) : null}

            {provider.has_key ? (
                <div className="flex flex-col gap-2 border-t border-(--border-default) pt-3">
                    {confirmingRemoval ? (
                        <>
                            <p className="text-(length:--text-sm) text-(--state-danger-text)">
                                {provider.is_default
                                    ? t('ai.setup.removeKeyConfirmDefault', {
                                          provider: provider.label,
                                      })
                                    : t('ai.setup.removeKeyConfirm', { provider: provider.label })}
                            </p>
                            <div className="flex flex-wrap gap-2">
                                <Button
                                    loading={remove.isPending}
                                    onClick={() => remove.mutate()}
                                    size="sm"
                                    variant="secondary"
                                >
                                    {t('ai.setup.removeKeyYes')}
                                </Button>
                                <Button
                                    onClick={() => setConfirmingRemoval(false)}
                                    size="sm"
                                    variant="ghost"
                                >
                                    {t('ai.setup.cancel')}
                                </Button>
                            </div>
                        </>
                    ) : (
                        <div>
                            <Button
                                onClick={() => setConfirmingRemoval(true)}
                                size="sm"
                                variant="ghost"
                            >
                                <KeyRound aria-hidden className="size-3.5" />
                                {t('ai.setup.removeKey')}
                            </Button>
                        </div>
                    )}
                    {remove.error instanceof ApiError ? (
                        <Alert tone="danger">{remove.error.message}</Alert>
                    ) : null}
                </div>
            ) : null}
        </section>
    );
}

/** What the platform can do right now: which provider answers, and what it last did. */
function Activity({ state, mayCheck }: { state: AiState; mayCheck: boolean }) {
    const { t, i18n } = useTranslation();
    const queryClient = useQueryClient();

    const check = useMutation({
        mutationFn: () => checkAi(),
        onSuccess: () => queryClient.invalidateQueries({ queryKey: ['ai-state'] }),
    });

    return (
        <section className="flex flex-col gap-3 border border-(--border-default) bg-(--surface-raised) p-4">
            <div className="flex flex-wrap items-baseline justify-between gap-2">
                <p data-eyebrow>{t('ai.defaultProvider')}</p>
                <StatusBadge tone={state.configured ? 'success' : 'warning'}>
                    {state.configured ? t('ai.ready') : t('ai.notReady')}
                </StatusBadge>
            </div>

            {state.provider === null ? (
                <p className="text-(length:--text-sm) text-(--text-secondary)">
                    {t('ai.noProvider')}
                </p>
            ) : (
                <p className="text-(length:--text-sm) text-(--text-primary)">
                    {state.provider.label}{' '}
                    <span className="text-(--text-muted)" data-technical>
                        · {state.provider.model}
                    </span>
                </p>
            )}

            <p data-eyebrow>{t('ai.lastAttempt')}</p>
            {state.last_attempt === null ? (
                <p className="text-(length:--text-sm) text-(--text-secondary)">
                    {t('ai.neverAsked')}
                </p>
            ) : (
                <>
                    <div className="flex flex-wrap items-center gap-2">
                        <StatusBadge
                            tone={state.last_attempt.status === 'success' ? 'success' : 'danger'}
                        >
                            {state.last_attempt.status === 'success'
                                ? t('ai.answered')
                                : t('ai.didNotAnswer')}
                        </StatusBadge>
                        <span className="text-(length:--text-xs) text-(--text-muted)">
                            {absoluteTime(state.last_attempt.at, i18n.language) ?? ''}
                        </span>
                    </div>
                    {state.last_attempt.error_message !== null ? (
                        <p className="text-(length:--text-sm) text-(--text-secondary)">
                            {state.last_attempt.error_message}
                        </p>
                    ) : null}
                    {state.last_attempt.units !== null ? (
                        <p className="text-(length:--text-xs) text-(--text-muted)">
                            {t('ai.units', { count: state.last_attempt.units })}
                        </p>
                    ) : null}
                </>
            )}

            {state.recent_failures > 0 ? (
                <Alert tone="warning">
                    {t('ai.recentFailures', { count: state.recent_failures })}
                </Alert>
            ) : null}

            {mayCheck && state.provider !== null ? (
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
                    <TestOutcome
                        error={check.error}
                        outcome={check.data}
                        provider={state.provider.label}
                    />
                </div>
            ) : null}
        </section>
    );
}

function TestOutcome({
    outcome,
    error,
    provider,
}: {
    outcome: AiCheck | undefined;
    error: Error | null;
    provider: string;
}) {
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

    // The test ran either way. What it found is the payload, not the status code — a
    // failed test is a successful diagnostic.
    return outcome.answered ? (
        <Alert tone="success">
            {t('ai.setup.answered', { provider, model: outcome.model ?? '' })}
        </Alert>
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
