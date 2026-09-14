import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import type { TFunction } from 'i18next';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';

import { ApiError } from '@/api/errors';
import { updateProvider } from '@/screens/integrations/api';
import { definitions } from '@/screens/settings/api';
import { SettingsWorkspace } from '@/screens/settings/SettingsWorkspace';
import { Alert } from '@/ui/Alert';
import { Button } from '@/ui/Button';
import { Checkbox } from '@/ui/Checkbox';
import { Field } from '@/ui/Field';
import { Input } from '@/ui/Input';
import { Panel } from '@/ui/Panel';
import { SegmentedControl } from '@/ui/SegmentedControl';
import type { StateTone } from '@/ui/state';
import { StatusBadge } from '@/ui/StatusBadge';

import {
    cdnPurges,
    cdnState,
    MAX_PURGE_ITEMS,
    queuePurge,
    retryPurge,
    verifyCdn,
    type CdnPurge,
    type CdnState,
    type PurgeKind,
    type PurgeStatus,
} from './api';

export interface CdnWorkspaceProps {
    mayConfigure: boolean;
    mayPurge: boolean;
    mayPurgeEverything: boolean;
}

const STATE_KEY = ['cdn-state'];
const PURGES_KEY = ['cdn-purges'];

const TEXTAREA =
    'min-h-28 w-full border border-(--border-strong) bg-(--surface-default) px-2 py-1.5 text-(length:--text-sm) text-(--text-primary) focus-visible:outline-2 focus-visible:outline-offset-0 focus-visible:outline-(--focus-ring) disabled:opacity-60';

/**
 * The CDN workspace (ADR 0036, ADR 0053).
 *
 * One page for everything about the edge, in the order an operator works through it: is
 * it ready, what connects it, what the vendor confirmed and allows, where media is served
 * from, then purging, and the record of every purge. Nothing on it is Cloudflare's: the
 * fields, limits and scope name come from the platform, which asks the driver.
 *
 * It never shows a purge as done. A purge is queued, and its outcome — with the vendor's
 * own error when it failed — is read from the list at the bottom, which is the only place
 * the answer exists.
 */
export function CdnWorkspace({ mayConfigure, mayPurge, mayPurgeEverything }: CdnWorkspaceProps) {
    const { t } = useTranslation();

    const state = useQuery({ queryKey: STATE_KEY, queryFn: ({ signal }) => cdnState(signal) });

    return (
        <div className="flex flex-col gap-4 p-4">
            <header>
                <p data-eyebrow>{t('cdn.eyebrow')}</p>
                <h1 className="text-(length:--text-xl) text-(--text-primary)">{t('cdn.title')}</h1>
                <p className="mt-1 max-w-prose text-(length:--text-sm) text-(--text-muted)">
                    {t('cdn.intro')}
                </p>
            </header>

            <Layers />

            {state.isPending ? (
                <p className="text-(--text-muted)">{t('state.loading')}</p>
            ) : state.error !== null ? (
                <Alert tone="danger">
                    {state.error instanceof ApiError ? state.error.message : t('state.error')}
                </Alert>
            ) : (
                <>
                    <Status state={state.data} />
                    <Connection mayConfigure={mayConfigure} state={state.data} />
                    <Verification mayConfigure={mayConfigure} state={state.data} />
                    <Limits state={state.data} />
                    <Delivery />
                    <Purge mayPurge={mayPurge} state={state.data} />
                    <PurgeEverything mayPurgeEverything={mayPurgeEverything} state={state.data} />
                </>
            )}

            <History mayPurge={mayPurge} />
        </div>
    );
}

function Layers() {
    const { t } = useTranslation();

    return (
        <Panel headingLevel={2} title={t('cdn.layers.title')}>
            <ul className="flex list-disc flex-col gap-1 ps-5 text-(length:--text-sm) text-(--text-secondary)">
                <li>{t('cdn.layers.application')}</li>
                <li>{t('cdn.layers.http')}</li>
                <li>{t('cdn.layers.edge')}</li>
            </ul>
        </Panel>
    );
}

function fieldLabel(t: TFunction, field: string): string {
    return t(`cdn.provider.field.${field}`, { defaultValue: field });
}

function Status({ state }: { state: CdnState }) {
    const { t, i18n } = useTranslation();

    return (
        <Panel headingLevel={2} title={t('cdn.status.title')}>
            <div className="flex flex-col gap-3">
                <div className="flex flex-wrap items-center gap-2">
                    <StatusBadge tone={state.configured ? 'success' : 'neutral'}>
                        {state.configured ? t('cdn.status.ready') : t('cdn.status.notReady')}
                    </StatusBadge>
                    {state.provider !== null ? (
                        <StatusBadge tone={state.provider.is_active ? 'info' : 'neutral'}>
                            {`${state.provider.label} · ${
                                state.provider.is_active
                                    ? t('cdn.status.active')
                                    : t('cdn.status.inactive')
                            }`}
                        </StatusBadge>
                    ) : null}
                </div>

                {state.missing.length > 0 ? (
                    <p className="text-(length:--text-sm) text-(--state-warning-text)">
                        {t('cdn.status.missing', {
                            fields: state.missing.map((field) => fieldLabel(t, field)).join(', '),
                        })}
                    </p>
                ) : null}

                <p className="text-(length:--text-sm) text-(--text-muted)">
                    {state.tag_header !== null
                        ? t('cdn.status.tagHeader', { header: state.tag_header })
                        : t('cdn.status.noTagHeader')}
                </p>

                <dl className="grid grid-cols-[auto_1fr] gap-x-4 gap-y-0.5 text-(length:--text-sm)">
                    <dt className="text-(--text-muted)">{t('cdn.status.pending')}</dt>
                    <dd data-technical>{state.queue.pending}</dd>
                    <dt className="text-(--text-muted)">{t('cdn.status.processing')}</dt>
                    <dd data-technical>{state.queue.processing}</dd>
                    <dt className="text-(--text-muted)">{t('cdn.status.failed')}</dt>
                    <dd data-technical>{state.queue.failed}</dd>
                    <dt className="text-(--text-muted)">{t('cdn.status.succeededDay')}</dt>
                    <dd data-technical>{state.queue.succeeded_last_day}</dd>
                    <dt className="text-(--text-muted)">{t('cdn.status.lastAttempt')}</dt>
                    <dd>
                        {state.last_attempt === null
                            ? t('cdn.status.never')
                            : `${new Date(state.last_attempt.at).toLocaleString(i18n.language)} · ${
                                  state.last_attempt.error_code ?? state.last_attempt.status
                              }`}
                    </dd>
                </dl>
            </div>
        </Panel>
    );
}

function Connection({ state, mayConfigure }: { state: CdnState; mayConfigure: boolean }) {
    const { t } = useTranslation();
    const queryClient = useQueryClient();
    const provider = state.provider;

    const [values, setValues] = useState<Record<string, string>>({});
    const [secrets, setSecrets] = useState<Record<string, string>>({});
    const [active, setActive] = useState<boolean | null>(null);

    const save = useMutation({
        mutationFn: () => {
            if (provider === null) {
                throw new Error('No provider');
            }

            const settings = Object.fromEntries(
                state.fields.settings.map((field) => [
                    field,
                    values[field] ?? provider.settings[field] ?? '',
                ]),
            );
            // Only credentials actually typed are sent: an absent key keeps the stored
            // secret, and an empty field must never overwrite one.
            const typed = Object.fromEntries(
                Object.entries(secrets).filter(([, value]) => value.trim() !== ''),
            );

            return updateProvider(provider.id, {
                settings,
                ...(Object.keys(typed).length > 0 ? { credentials: typed } : {}),
                is_active: active ?? provider.is_active,
            });
        },
        onSuccess: async () => {
            setValues({});
            setSecrets({});
            setActive(null);
            await queryClient.invalidateQueries({ queryKey: STATE_KEY });
        },
    });

    if (provider === null) {
        return (
            <Panel headingLevel={2} title={t('cdn.provider.title')}>
                <p className="text-(--text-muted)">{t('cdn.provider.none')}</p>
            </Panel>
        );
    }

    const missing = save.error instanceof ApiError ? save.error.details : null;

    return (
        <Panel headingLevel={2} title={t('cdn.provider.title')}>
            <form
                className="flex max-w-xl flex-col gap-3"
                onSubmit={(event) => {
                    event.preventDefault();
                    save.mutate();
                }}
            >
                {!mayConfigure ? <Alert tone="info">{t('cdn.provider.readOnly')}</Alert> : null}

                {state.fields.settings.map((field) => (
                    <Field key={field} label={fieldLabel(t, field)}>
                        {(props) => (
                            <Input
                                {...props}
                                data-technical
                                disabled={!mayConfigure}
                                onChange={(event) =>
                                    setValues((current) => ({
                                        ...current,
                                        [field]: event.target.value,
                                    }))
                                }
                                value={values[field] ?? provider.settings[field] ?? ''}
                            />
                        )}
                    </Field>
                ))}

                {state.fields.credentials.map((field) => (
                    <Field
                        hint={
                            provider.has_credentials
                                ? t('cdn.provider.tokenStored')
                                : t('cdn.provider.tokenHelp')
                        }
                        key={field}
                        label={fieldLabel(t, field)}
                    >
                        {(props) => (
                            <Input
                                {...props}
                                autoComplete="off"
                                disabled={!mayConfigure}
                                onChange={(event) =>
                                    setSecrets((current) => ({
                                        ...current,
                                        [field]: event.target.value,
                                    }))
                                }
                                type="password"
                                value={secrets[field] ?? ''}
                            />
                        )}
                    </Field>
                ))}

                <label className="flex items-center gap-2 text-(length:--text-sm) text-(--text-secondary)">
                    <Checkbox
                        checked={active ?? provider.is_active}
                        disabled={!mayConfigure}
                        onChange={(event) => setActive(event.target.checked)}
                    />
                    {t('cdn.provider.switchOn')}
                </label>

                {save.isSuccess ? <Alert tone="success">{t('cdn.provider.saved')}</Alert> : null}
                {save.error instanceof ApiError ? (
                    <Alert tone="danger">
                        {save.error.message}
                        {Array.isArray((missing as { missing?: unknown } | null)?.missing)
                            ? ` ${((missing as { missing: string[] }).missing ?? [])
                                  .map((field) => fieldLabel(t, field))
                                  .join(', ')}`
                            : null}
                    </Alert>
                ) : null}

                <div>
                    <Button
                        disabled={!mayConfigure}
                        loading={save.isPending}
                        type="submit"
                        variant="primary"
                    >
                        {t('cdn.provider.save')}
                    </Button>
                </div>
            </form>
        </Panel>
    );
}

function Verification({ state, mayConfigure }: { state: CdnState; mayConfigure: boolean }) {
    const { t, i18n } = useTranslation();
    const queryClient = useQueryClient();

    const verify = useMutation({
        mutationFn: verifyCdn,
        onSuccess: async () => {
            await queryClient.invalidateQueries({ queryKey: STATE_KEY });
        },
    });

    const found = state.verification;

    return (
        <Panel headingLevel={2} title={t('cdn.verify.title')}>
            <div className="flex flex-col gap-3">
                {found === null ? (
                    <p className="text-(length:--text-sm) text-(--text-muted)">
                        {t('cdn.verify.never')}
                    </p>
                ) : (
                    <dl className="grid grid-cols-[auto_1fr] gap-x-4 gap-y-0.5 text-(length:--text-sm)">
                        <dt className="text-(--text-muted)">{t('cdn.verify.scope')}</dt>
                        <dd data-technical>{found.scope_name ?? '—'}</dd>
                        <dt className="text-(--text-muted)">{t('cdn.verify.scopeStatus')}</dt>
                        <dd data-technical>{found.scope_status ?? '—'}</dd>
                        <dt className="text-(--text-muted)">{t('cdn.verify.plan')}</dt>
                        <dd data-technical>{found.plan ?? '—'}</dd>
                        <dt className="text-(--text-muted)">{t('cdn.verify.at')}</dt>
                        <dd>
                            {found.verified_at === null
                                ? '—'
                                : new Date(found.verified_at).toLocaleString(i18n.language)}
                        </dd>
                    </dl>
                )}

                {found?.error_code ? (
                    <Alert tone="warning">
                        {t('cdn.verify.failed', { code: found.error_code })}
                        {found.error_message ? ` — ${found.error_message}` : null}
                    </Alert>
                ) : null}

                {verify.data !== undefined ? (
                    verify.data.reachable ? (
                        <Alert tone="success">
                            {t('cdn.verify.done', {
                                scope: verify.data.verification.scope_name ?? '',
                            })}
                        </Alert>
                    ) : (
                        <Alert tone="danger">{t('cdn.verify.notConfirmed')}</Alert>
                    )
                ) : null}

                {verify.error instanceof ApiError ? (
                    <Alert tone="danger">{verify.error.message}</Alert>
                ) : null}

                <div>
                    <Button
                        disabled={
                            !mayConfigure || state.provider === null || state.missing.length > 0
                        }
                        loading={verify.isPending}
                        onClick={() => verify.mutate()}
                        variant="secondary"
                    >
                        {t('cdn.verify.action')}
                    </Button>
                </div>
            </div>
        </Panel>
    );
}

function Limits({ state }: { state: CdnState }) {
    const { t } = useTranslation();

    if (state.limits.length === 0) {
        return null;
    }

    return (
        <Panel headingLevel={2} title={t('cdn.limits.title')}>
            <div className="overflow-x-auto">
                <table className="w-full text-start text-(length:--text-sm)">
                    <thead>
                        <tr className="text-(--text-muted)">
                            <th className="py-1 pe-4 text-start font-normal">
                                {t('cdn.limits.kind')}
                            </th>
                            <th className="py-1 pe-4 text-start font-normal">
                                {t('cdn.limits.perCall')}
                            </th>
                            <th className="py-1 text-start font-normal">
                                {t('cdn.limits.perMinute')}
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        {state.limits.map((limit) => (
                            <tr className="border-t border-(--border-subtle)" key={limit.kind}>
                                <td className="py-1 pe-4">{t(`cdn.kind.${limit.kind}`)}</td>
                                {limit.supported ? (
                                    <>
                                        <td className="py-1 pe-4" data-technical>
                                            {limit.items_per_request}
                                        </td>
                                        <td className="py-1" data-technical>
                                            {limit.requests_per_minute ?? t('cdn.limits.unbounded')}
                                        </td>
                                    </>
                                ) : (
                                    <td className="py-1 text-(--text-muted)" colSpan={2}>
                                        {t('cdn.limits.unsupported')}
                                    </td>
                                )}
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
        </Panel>
    );
}

/**
 * The `cdn` settings group, edited with the settings workspace itself — the same review,
 * the same precondition, the same per-field permissions — rather than a second form for
 * the same values.
 */
function Delivery() {
    const { t } = useTranslation();

    const catalogue = useQuery({
        queryKey: ['settings-definitions'],
        queryFn: ({ signal }) => definitions(signal),
        staleTime: 5 * 60_000,
    });

    const group = catalogue.data?.['cdn'];

    return (
        <Panel headingLevel={2} title={t('cdn.delivery.title')}>
            <div className="flex flex-col gap-3">
                <p className="max-w-prose text-(length:--text-sm) text-(--text-muted)">
                    {t('cdn.delivery.intro')}
                </p>
                {catalogue.error instanceof ApiError ? (
                    <Alert tone="danger">{catalogue.error.message}</Alert>
                ) : null}
                {group !== undefined && group.length > 0 ? (
                    <div className="border border-(--border-default)">
                        <SettingsWorkspace
                            definitions={group}
                            name="cdn"
                            onPendingChange={() => undefined}
                        />
                    </div>
                ) : null}
            </div>
        </Panel>
    );
}

function Purge({ state, mayPurge }: { state: CdnState; mayPurge: boolean }) {
    const { t } = useTranslation();
    const queryClient = useQueryClient();

    const kinds = state.limits
        .filter((limit) => limit.supported && limit.kind !== 'everything')
        .map((limit) => limit.kind as Exclude<PurgeKind, 'everything'>);

    const [chosen, setChosen] = useState<Exclude<PurgeKind, 'everything'> | null>(null);
    const kind = chosen ?? kinds[0] ?? 'urls';
    const [text, setText] = useState('');
    const [reason, setReason] = useState('');
    const [queued, setQueued] = useState<number | null>(null);

    const items = text
        .split('\n')
        .map((line) => line.trim())
        .filter((line) => line !== '');

    const purge = useMutation({
        mutationFn: () =>
            queuePurge({ kind, items, ...(reason.trim() === '' ? {} : { reason: reason.trim() }) }),
        onMutate: () => setQueued(null),
        onSuccess: async (rows) => {
            setText('');
            setReason('');
            setQueued(rows.length);
            await queryClient.invalidateQueries({ queryKey: STATE_KEY });
            await queryClient.invalidateQueries({ queryKey: PURGES_KEY });
        },
    });

    const validation = purge.error instanceof ApiError ? purge.error.validationDetails : null;
    const tooMany = items.length > MAX_PURGE_ITEMS;

    return (
        <Panel headingLevel={2} title={t('cdn.purge.title')}>
            <form
                className="flex max-w-2xl flex-col gap-3"
                onSubmit={(event) => {
                    event.preventDefault();
                    purge.mutate();
                }}
            >
                <p className="text-(length:--text-sm) text-(--text-muted)">
                    {t('cdn.purge.intro')}
                </p>

                {!mayPurge ? <Alert tone="info">{t('cdn.purge.readOnly')}</Alert> : null}
                {mayPurge && !state.configured ? (
                    <Alert tone="warning">{t('cdn.purge.notReady')}</Alert>
                ) : null}

                {kinds.length > 0 ? (
                    <SegmentedControl
                        label={t('cdn.purge.kind')}
                        onChange={(value) => setChosen(value)}
                        options={kinds.map((value) => ({ value, label: t(`cdn.kind.${value}`) }))}
                        value={kind}
                    />
                ) : null}

                <Field
                    {...(tooMany
                        ? { error: t('cdn.purge.tooMany', { max: MAX_PURGE_ITEMS }) }
                        : {})}
                    hint={t(`cdn.purge.hint.${kind}`)}
                    label={t('cdn.purge.items')}
                >
                    {({ invalid, ...props }) => (
                        <textarea
                            {...props}
                            aria-invalid={invalid}
                            className={TEXTAREA}
                            data-technical
                            disabled={!mayPurge}
                            onChange={(event) => setText(event.target.value)}
                            value={text}
                        />
                    )}
                </Field>

                <Field label={t('cdn.purge.reason')}>
                    {(props) => (
                        <Input
                            {...props}
                            disabled={!mayPurge}
                            maxLength={255}
                            onChange={(event) => setReason(event.target.value)}
                            value={reason}
                        />
                    )}
                </Field>

                {queued !== null ? (
                    <Alert tone="success">{t('cdn.purge.queued', { count: queued })}</Alert>
                ) : null}

                {purge.error instanceof ApiError ? (
                    <Alert tone="danger">
                        {validation === null
                            ? purge.error.message
                            : Object.values(validation)
                                  .map((messages) => messages[0])
                                  .join(' ')}
                    </Alert>
                ) : null}

                <div>
                    <Button
                        disabled={!mayPurge || !state.configured || items.length === 0 || tooMany}
                        loading={purge.isPending}
                        type="submit"
                        variant="primary"
                    >
                        {t('cdn.purge.submit')}
                    </Button>
                </div>
            </form>
        </Panel>
    );
}

function PurgeEverything({
    state,
    mayPurgeEverything,
}: {
    state: CdnState;
    mayPurgeEverything: boolean;
}) {
    const { t } = useTranslation();
    const queryClient = useQueryClient();
    const [confirm, setConfirm] = useState('');
    const name = state.verification?.scope_name ?? null;

    const run = useMutation({
        mutationFn: () => queuePurge({ kind: 'everything', confirm }),
        onSuccess: async () => {
            setConfirm('');
            await queryClient.invalidateQueries({ queryKey: STATE_KEY });
            await queryClient.invalidateQueries({ queryKey: PURGES_KEY });
        },
    });

    return (
        <Panel headingLevel={2} title={t('cdn.everything.title')}>
            <div className="flex max-w-2xl flex-col gap-3 border-s-(length:--rail-width) border-(--state-warning-rail) ps-3">
                <p className="text-(length:--text-sm) text-(--text-secondary)">
                    {t('cdn.everything.intro')}
                </p>

                {!mayPurgeEverything ? (
                    <Alert tone="info">{t('cdn.everything.forbidden')}</Alert>
                ) : name === null ? (
                    <Alert tone="info">{t('cdn.everything.needsVerification')}</Alert>
                ) : (
                    <>
                        <Field label={t('cdn.everything.confirm', { name })}>
                            {(props) => (
                                <Input
                                    {...props}
                                    autoComplete="off"
                                    data-technical
                                    onChange={(event) => setConfirm(event.target.value)}
                                    value={confirm}
                                />
                            )}
                        </Field>

                        {run.isSuccess ? (
                            <Alert tone="success">{t('cdn.purge.queued', { count: 1 })}</Alert>
                        ) : null}
                        {run.error instanceof ApiError ? (
                            <Alert tone="danger">{run.error.message}</Alert>
                        ) : null}

                        <div>
                            <Button
                                disabled={!state.configured || confirm !== name}
                                loading={run.isPending}
                                onClick={() => run.mutate()}
                                variant="danger"
                            >
                                {t('cdn.everything.submit')}
                            </Button>
                        </div>
                    </>
                )}
            </div>
        </Panel>
    );
}

const STATUS_TONE: Record<PurgeStatus, StateTone> = {
    pending: 'info',
    processing: 'warning',
    succeeded: 'success',
    failed: 'danger',
};

function History({ mayPurge }: { mayPurge: boolean }) {
    const { t, i18n } = useTranslation();
    const queryClient = useQueryClient();
    const [status, setStatus] = useState<PurgeStatus | 'all'>('all');
    const [page, setPage] = useState(1);

    const purges = useQuery({
        queryKey: [...PURGES_KEY, status, page],
        queryFn: ({ signal }) => cdnPurges(status === 'all' ? null : status, page, signal),
    });

    const retry = useMutation({
        mutationFn: retryPurge,
        onSuccess: async () => {
            await queryClient.invalidateQueries({ queryKey: PURGES_KEY });
            await queryClient.invalidateQueries({ queryKey: STATE_KEY });
        },
    });

    const when = (value: string | null) =>
        value === null ? '—' : new Date(value).toLocaleString(i18n.language);

    return (
        <Panel headingLevel={2} title={t('cdn.history.title')}>
            <div className="flex flex-col gap-3">
                <SegmentedControl
                    label={t('cdn.history.title')}
                    onChange={(value) => {
                        setStatus(value);
                        setPage(1);
                    }}
                    options={[
                        { value: 'all' as const, label: t('cdn.history.all') },
                        { value: 'pending' as const, label: t('cdn.status.pending') },
                        { value: 'processing' as const, label: t('cdn.status.processing') },
                        { value: 'failed' as const, label: t('cdn.status.failed') },
                        { value: 'succeeded' as const, label: t('cdn.history.succeeded') },
                    ]}
                    value={status}
                />

                {retry.isSuccess ? <Alert tone="success">{t('cdn.history.retried')}</Alert> : null}
                {retry.error instanceof ApiError ? (
                    <Alert tone="danger">{retry.error.message}</Alert>
                ) : null}

                {purges.isPending ? (
                    <p className="text-(--text-muted)">{t('state.loading')}</p>
                ) : purges.error !== null ? (
                    <Alert tone="danger">
                        {purges.error instanceof ApiError ? purges.error.message : t('state.error')}
                    </Alert>
                ) : purges.data.rows.length === 0 ? (
                    <p className="text-(--text-muted)">{t('cdn.history.empty')}</p>
                ) : (
                    <ul className="flex flex-col border border-(--border-default) bg-(--surface-default)">
                        {purges.data.rows.map((row: CdnPurge) => (
                            <li
                                className="flex flex-col gap-1 border-b border-(--border-subtle) p-3 last:border-b-0"
                                key={row.id}
                            >
                                <div className="flex flex-wrap items-center justify-between gap-2">
                                    <div className="flex flex-wrap items-center gap-2">
                                        <StatusBadge
                                            tone={
                                                STATUS_TONE[row.status as PurgeStatus] ?? 'neutral'
                                            }
                                        >
                                            {row.status_label}
                                        </StatusBadge>
                                        <span className="text-(--text-primary)">
                                            {row.kind_label}
                                        </span>
                                        <span className="text-(length:--text-sm) text-(--text-muted)">
                                            {t('cdn.history.items', { count: row.item_count })}
                                            {' · '}
                                            {t('cdn.history.attempts', { count: row.attempts })}
                                        </span>
                                    </div>
                                    {mayPurge && row.status === 'failed' ? (
                                        <Button
                                            disabled={retry.isPending}
                                            onClick={() => retry.mutate(row.id)}
                                            size="sm"
                                            variant="secondary"
                                        >
                                            {t('cdn.history.retry')}
                                        </Button>
                                    ) : null}
                                </div>

                                {row.items.length > 0 ? (
                                    <p
                                        className="truncate text-(length:--text-sm) text-(--text-secondary)"
                                        data-technical
                                    >
                                        {row.items.slice(0, 3).join(', ')}
                                        {row.items.length > 3 ? ' …' : ''}
                                    </p>
                                ) : null}

                                {row.reason ? (
                                    <p className="text-(length:--text-sm) text-(--text-secondary)">
                                        {row.reason}
                                    </p>
                                ) : null}

                                {row.error_code !== null ? (
                                    <p className="text-(length:--text-sm) text-(--text-danger)">
                                        <span data-technical>{row.error_code}</span>
                                        {row.error_message ? ` — ${row.error_message}` : null}
                                    </p>
                                ) : null}

                                <p className="text-(length:--text-xs) text-(--text-muted)">
                                    {when(row.created_at)}
                                    {row.status === 'pending' && row.available_at !== null
                                        ? ` · ${t('cdn.history.nextAttempt', { at: when(row.available_at) })}`
                                        : null}
                                </p>
                            </li>
                        ))}
                    </ul>
                )}

                {purges.data !== undefined && purges.data.pages > 1 ? (
                    <div className="flex items-center gap-2">
                        <Button
                            disabled={page <= 1}
                            onClick={() => setPage((current) => current - 1)}
                            size="sm"
                            variant="ghost"
                        >
                            {t('cdn.history.newer')}
                        </Button>
                        <span className="text-(length:--text-sm) text-(--text-muted)">
                            {t('cdn.history.page', {
                                page: purges.data.page,
                                pages: purges.data.pages,
                            })}
                        </span>
                        <Button
                            disabled={page >= purges.data.pages}
                            onClick={() => setPage((current) => current + 1)}
                            size="sm"
                            variant="ghost"
                        >
                            {t('cdn.history.older')}
                        </Button>
                    </div>
                ) : null}
            </div>
        </Panel>
    );
}
