import { useMutation, useQueryClient } from '@tanstack/react-query';
import { KeyRound, ShieldCheck, ShieldOff, Star, X } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';

import { ApiError } from '@/api/errors';
import { absoluteTime, relativeTime } from '@/lib/time';
import { useDirection } from '@/shell/DirectionProvider';
import { Alert } from '@/ui/Alert';
import { Button } from '@/ui/Button';
import { Field } from '@/ui/Field';
import { Input } from '@/ui/Input';
import { StatusBadge } from '@/ui/StatusBadge';

import {
    makeDefault,
    updateProvider,
    usageFor,
    type IntegrationProvider,
    type IntegrationUsage,
    type ProviderChanges,
} from './api';
import { KeyValueEditor } from './KeyValueEditor';
import { fromPairs, toPairs, type Pair } from './pairs';

export interface ProviderDetailProps {
    provider: IntegrationProvider;
    /** Null where the activity log could not be read, and only there. */
    usage: readonly IntegrationUsage[] | null;
    /** Whether this viewer holds `integrations.update`. */
    mayUpdate: boolean;
    onClose: () => void;
}

/**
 * One provider, and everything that can be done to it.
 *
 * Configuration and credentials are separate forms on purpose. The configuration form
 * sends only what changed, so saving a label cannot disturb anything else; the
 * credential form is a replacement and says so, because that is what the endpoint
 * does — a submitted map becomes the stored map in full. Merging the two would make
 * every ordinary edit a credential write.
 *
 * Nothing here can read a secret. The API reports credentials as present or absent and
 * never returns them, so this panel shows a state and offers to overwrite it. An
 * interface that appeared to show the stored value would either be lying or would mean
 * the platform had started publishing keys.
 *
 * The draft belongs to the provider it was opened on. The caller keys this component by
 * provider id, so selecting another one mounts a new panel with a new draft rather than
 * showing the first provider's edits under the second one's name. Nothing resets the
 * form on a refetch, either: the list is refreshed on an interval and on window focus,
 * and an effect that adopted every new object would discard what an operator typed
 * while they were looking at something else. A write syncs the form from its own
 * response, which is the one moment the server's copy should win.
 */
export function ProviderDetail({ provider, usage, mayUpdate, onClose }: ProviderDetailProps) {
    const { t } = useTranslation();
    const { locale } = useDirection();
    const queryClient = useQueryClient();

    const [label, setLabel] = useState(provider.label);
    const [priority, setPriority] = useState(String(provider.priority));
    const [settings, setSettings] = useState<Pair[]>(() => toPairs(provider.settings));
    /** Null while no replacement is being composed; a list once one is. */
    const [credentials, setCredentials] = useState<Pair[] | null>(null);
    const [clearing, setClearing] = useState(false);

    /** Take the server's copy as the form's state. */
    const adopt = (source: IntegrationProvider) => {
        setLabel(source.label);
        setPriority(String(source.priority));
        setSettings(toPairs(source.settings));
    };

    const refresh = async () => {
        await queryClient.invalidateQueries({ queryKey: ['integration-providers'] });
    };

    const save = useMutation({
        mutationFn: (changes: ProviderChanges) => updateProvider(provider.id, changes),
        onSuccess: async (updated) => {
            // Every write answers with the canonical provider, so the form is put back
            // in step with it here rather than by watching the list.
            adopt(updated);
            await refresh();
        },
    });

    const promote = useMutation({
        mutationFn: () => makeDefault(provider.id),
        onSuccess: refresh,
    });

    const attempts = usage === null ? null : usageFor(provider, usage);
    const failures = (attempts ?? []).filter((entry) => entry.status === 'failure');

    const priorityNumber = Number.parseInt(priority, 10);
    const priorityValid =
        Number.isInteger(priorityNumber) && priorityNumber >= 0 && priorityNumber <= 1000;

    const settingsChanged =
        JSON.stringify(fromPairs(settings)) !==
        JSON.stringify(fromPairs(toPairs(provider.settings)));
    const dirty =
        label !== provider.label || priority !== String(provider.priority) || settingsChanged;

    // Only what changed. Every field is `sometimes`, so an absent key leaves the stored
    // value alone — which is the difference between editing a label and rewriting a
    // provider.
    const saveConfiguration = () => {
        save.mutate({
            ...(label === provider.label ? {} : { label }),
            ...(priority === String(provider.priority) ? {} : { priority: priorityNumber }),
            ...(settingsChanged ? { settings: fromPairs(settings) } : {}),
        });
    };

    // Spread rather than passed, because `exactOptionalPropertyTypes` makes an
    // explicit `undefined` a different thing from an absent prop.
    const fieldError = (name: string): { error?: string } => {
        const message =
            save.error instanceof ApiError ? save.error.validationDetails?.[name]?.[0] : undefined;

        return message === undefined ? {} : { error: message };
    };

    return (
        <div className="flex flex-col border border-(--border-default) bg-(--surface-default)">
            <header className="flex items-start justify-between gap-2 border-b border-(--border-default) px-3 py-2.5">
                <div className="min-w-0">
                    <p data-eyebrow>{provider.capability_label}</p>
                    <h2 className="truncate text-(length:--text-lg) text-(--text-primary)">
                        {provider.label}
                    </h2>
                    <p className="text-(length:--text-sm) text-(--text-muted)" data-technical>
                        {provider.driver}
                    </p>
                </div>
                <Button
                    aria-label={t('integrations.close')}
                    onClick={onClose}
                    size="icon"
                    variant="ghost"
                >
                    <X aria-hidden className="size-4" />
                </Button>
            </header>

            <div className="flex flex-col gap-4 p-3">
                <div className="flex flex-wrap gap-1.5">
                    <StatusBadge tone={provider.is_active ? 'success' : 'neutral'}>
                        {provider.is_active ? t('integrations.active') : t('integrations.inactive')}
                    </StatusBadge>
                    {provider.is_default ? (
                        <StatusBadge icon={<Star className="size-3" />} tone="info">
                            {t('integrations.default')}
                        </StatusBadge>
                    ) : null}
                    <StatusBadge
                        icon={
                            provider.has_credentials ? (
                                <ShieldCheck className="size-3" />
                            ) : (
                                <ShieldOff className="size-3" />
                            )
                        }
                        tone={provider.has_credentials ? 'success' : 'warning'}
                    >
                        {provider.has_credentials
                            ? t('integrations.credentials.configured')
                            : t('integrations.credentials.unconfigured')}
                    </StatusBadge>
                </div>

                <dl className="grid grid-cols-[auto_1fr] gap-x-4 gap-y-1 text-(length:--text-sm)">
                    <dt className="text-(--text-muted)">{t('integrations.attemptsLabel')}</dt>
                    <dd className="text-(--text-primary)">
                        {attempts === null
                            ? t('integrations.activityUnavailableShort')
                            : t('integrations.attemptsValue', {
                                  count: attempts.length,
                                  failures: failures.length,
                              })}
                    </dd>
                    <dt className="text-(--text-muted)">{t('integrations.updatedAt')}</dt>
                    <dd
                        className="text-(--text-primary)"
                        title={absoluteTime(provider.updated_at, locale) ?? undefined}
                    >
                        {relativeTime(provider.updated_at, locale)}
                    </dd>
                </dl>

                {mayUpdate ? (
                    <>
                        <section className="flex flex-col gap-2">
                            <h3 data-eyebrow>{t('integrations.stateSection')}</h3>

                            <div className="flex flex-wrap gap-2">
                                <Button
                                    loading={save.isPending}
                                    onClick={() => save.mutate({ is_active: !provider.is_active })}
                                    size="sm"
                                    variant={provider.is_active ? 'secondary' : 'primary'}
                                >
                                    {provider.is_active
                                        ? t('integrations.deactivate')
                                        : t('integrations.activate')}
                                </Button>

                                {provider.is_default ? null : (
                                    <Button
                                        // The platform refuses a default that is switched
                                        // off, so the control is refused here too rather
                                        // than sending a request certain to come back 422.
                                        disabled={!provider.is_active}
                                        loading={promote.isPending}
                                        onClick={() => promote.mutate()}
                                        size="sm"
                                        variant="secondary"
                                    >
                                        <Star aria-hidden className="size-3.5" />
                                        {t('integrations.makeDefault')}
                                    </Button>
                                )}
                            </div>

                            {!provider.is_active && !provider.is_default ? (
                                <p className="text-(length:--text-sm) text-(--text-muted)">
                                    {t('integrations.defaultNeedsActive')}
                                </p>
                            ) : null}

                            {promote.error instanceof ApiError ? (
                                <Alert tone="danger">{promote.error.message}</Alert>
                            ) : null}
                        </section>

                        <section className="flex flex-col gap-2">
                            <h3 data-eyebrow>{t('integrations.configuration')}</h3>

                            <Field {...fieldError('label')} label={t('integrations.label')}>
                                {({ id, 'aria-describedby': describedBy, invalid }) => (
                                    <Input
                                        aria-describedby={describedBy}
                                        id={id}
                                        invalid={invalid}
                                        onChange={(event) => setLabel(event.target.value)}
                                        value={label}
                                    />
                                )}
                            </Field>

                            <Field
                                {...fieldError('priority')}
                                hint={t('integrations.priorityHint')}
                                label={t('integrations.priority')}
                            >
                                {({ id, 'aria-describedby': describedBy, invalid }) => (
                                    <Input
                                        aria-describedby={describedBy}
                                        id={id}
                                        invalid={invalid || !priorityValid}
                                        max={1000}
                                        min={0}
                                        onChange={(event) => setPriority(event.target.value)}
                                        type="number"
                                        value={priority}
                                    />
                                )}
                            </Field>

                            <div className="flex flex-col gap-1">
                                <p className="text-(length:--text-sm) font-medium text-(--text-secondary)">
                                    {t('integrations.settings')}
                                </p>
                                <p className="text-(length:--text-sm) text-(--text-muted)">
                                    {t('integrations.settingsHint')}
                                </p>
                                <KeyValueEditor
                                    addLabel={t('integrations.settingsAdd')}
                                    emptyMessage={t('integrations.settingsNone')}
                                    label={t('integrations.settings')}
                                    namePlaceholder={t('integrations.name')}
                                    onChange={setSettings}
                                    pairs={settings}
                                    valuePlaceholder={t('integrations.value')}
                                />
                            </div>

                            {save.error instanceof ApiError &&
                            save.error.validationDetails === null ? (
                                <Alert tone="danger">{save.error.message}</Alert>
                            ) : null}

                            {save.isSuccess && !dirty ? (
                                <Alert tone="success">{t('integrations.saved')}</Alert>
                            ) : null}

                            <div className="flex flex-wrap gap-2">
                                <Button
                                    disabled={!dirty || !priorityValid}
                                    loading={save.isPending}
                                    onClick={saveConfiguration}
                                    variant="primary"
                                >
                                    {t('integrations.save')}
                                </Button>
                                <Button
                                    disabled={!dirty}
                                    onClick={() => {
                                        setLabel(provider.label);
                                        setPriority(String(provider.priority));
                                        setSettings(toPairs(provider.settings));
                                    }}
                                    variant="ghost"
                                >
                                    {t('integrations.discard')}
                                </Button>
                            </div>
                        </section>

                        <section className="flex flex-col gap-2 border-t border-(--border-default) pt-3">
                            <h3 data-eyebrow>{t('integrations.credentials.title')}</h3>
                            <p className="text-(length:--text-sm) text-(--text-muted)">
                                {t('integrations.credentials.explanation')}
                            </p>

                            {credentials === null ? (
                                <div className="flex flex-wrap gap-2">
                                    <Button
                                        onClick={() => setCredentials([])}
                                        size="sm"
                                        variant="secondary"
                                    >
                                        <KeyRound aria-hidden className="size-3.5" />
                                        {provider.has_credentials
                                            ? t('integrations.credentials.replace')
                                            : t('integrations.credentials.set')}
                                    </Button>

                                    {provider.has_credentials ? (
                                        <Button
                                            onClick={() => setClearing(true)}
                                            size="sm"
                                            variant="ghost"
                                        >
                                            {t('integrations.credentials.clear')}
                                        </Button>
                                    ) : null}
                                </div>
                            ) : (
                                <div className="flex flex-col gap-2 border-s-(length:--rail-width) border-(--state-pending-rail) ps-2">
                                    <p className="text-(length:--text-sm) text-(--state-pending-text)">
                                        {t('integrations.credentials.replaceWarning')}
                                    </p>

                                    <KeyValueEditor
                                        addLabel={t('integrations.credentials.add')}
                                        emptyMessage={t('integrations.credentials.startEmpty')}
                                        label={t('integrations.credentials.title')}
                                        namePlaceholder={t('integrations.name')}
                                        onChange={setCredentials}
                                        pairs={credentials}
                                        secret
                                        valuePlaceholder={t('integrations.credentials.value')}
                                    />

                                    <div className="flex flex-wrap gap-2">
                                        <Button
                                            disabled={
                                                Object.keys(fromPairs(credentials)).length === 0
                                            }
                                            loading={save.isPending}
                                            onClick={() =>
                                                save.mutate(
                                                    { credentials: fromPairs(credentials) },
                                                    { onSuccess: () => setCredentials(null) },
                                                )
                                            }
                                            size="sm"
                                            variant="primary"
                                        >
                                            {t('integrations.credentials.submit')}
                                        </Button>
                                        <Button
                                            onClick={() => setCredentials(null)}
                                            size="sm"
                                            variant="ghost"
                                        >
                                            {t('integrations.cancel')}
                                        </Button>
                                    </div>
                                </div>
                            )}

                            {clearing ? (
                                <div className="flex flex-col gap-2 border-s-(length:--rail-width) border-(--state-danger-rail) ps-2">
                                    <p className="text-(length:--text-sm) text-(--state-danger-text)">
                                        {t('integrations.credentials.clearWarning')}
                                    </p>
                                    <div className="flex flex-wrap gap-2">
                                        <Button
                                            loading={save.isPending}
                                            onClick={() =>
                                                save.mutate(
                                                    { credentials: null },
                                                    { onSuccess: () => setClearing(false) },
                                                )
                                            }
                                            size="sm"
                                            variant="danger"
                                        >
                                            {t('integrations.credentials.clearConfirm')}
                                        </Button>
                                        <Button
                                            onClick={() => setClearing(false)}
                                            size="sm"
                                            variant="ghost"
                                        >
                                            {t('integrations.cancel')}
                                        </Button>
                                    </div>
                                </div>
                            ) : null}
                        </section>
                    </>
                ) : (
                    <Alert tone="info">{t('integrations.readOnly')}</Alert>
                )}

                <section className="flex flex-col gap-2 border-t border-(--border-default) pt-3">
                    <h3 data-eyebrow>{t('integrations.recentAttempts')}</h3>

                    {attempts === null ? (
                        <p className="text-(length:--text-sm) text-(--text-muted)">
                            {t('integrations.activityUnavailable')}
                        </p>
                    ) : attempts.length === 0 ? (
                        <p className="text-(length:--text-sm) text-(--text-muted)">
                            {t('integrations.noAttempts')}
                        </p>
                    ) : (
                        <ul className="flex flex-col gap-2">
                            {attempts.slice(0, 8).map((entry) => (
                                <li className="flex flex-col" key={entry.id}>
                                    <span className="flex flex-wrap items-center gap-2">
                                        <StatusBadge
                                            tone={entry.status === 'failure' ? 'danger' : 'success'}
                                        >
                                            {entry.status === 'failure'
                                                ? t('integrations.usage.failure')
                                                : t('integrations.usage.success')}
                                        </StatusBadge>
                                        <span
                                            className="text-(length:--text-xs) text-(--text-muted)"
                                            title={
                                                absoluteTime(entry.created_at, locale) ?? undefined
                                            }
                                        >
                                            {relativeTime(entry.created_at, locale)}
                                        </span>
                                        {entry.duration_ms === null ? null : (
                                            <span
                                                className="text-(length:--text-xs) text-(--text-muted)"
                                                data-technical
                                            >
                                                {t('integrations.usage.duration', {
                                                    ms: entry.duration_ms,
                                                })}
                                            </span>
                                        )}
                                    </span>
                                    {entry.error_code === null ? null : (
                                        <span className="text-(length:--text-sm) text-(--text-danger)">
                                            <span data-technical>{entry.error_code}</span>
                                            {entry.error_message === null
                                                ? null
                                                : ` · ${entry.error_message}`}
                                        </span>
                                    )}
                                </li>
                            ))}
                        </ul>
                    )}
                </section>
            </div>
        </div>
    );
}
