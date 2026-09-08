import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';

import { ApiError, ErrorCode } from '@/api/errors';
import { useCurrentUser } from '@/auth/AuthProvider';
import { useDirection } from '@/shell/DirectionProvider';
import { Alert } from '@/ui/Alert';
import { Button } from '@/ui/Button';

import { group as fetchGroup, testMail, updateGroup, type SettingDefinition } from './api';
import { changedValues, fieldStates, invalidFields, settingKey, type Draft } from './draft';
import { ReviewChanges } from './ReviewChanges';
import { SettingRow } from './SettingRow';

export interface SettingsWorkspaceProps {
    name: string;
    definitions: SettingDefinition[];
    onPendingChange: (group: string, count: number) => void;
}

type Stage = 'editing' | 'reviewing';

/**
 * One group of settings, edited as a batch and written as one.
 *
 * The shape of this is the point:
 *
 *     server state → draft → validation → review → If-Match → atomic save
 *
 * Everything changed goes in one request, because the platform applies a batch
 * atomically: a request per field would let a group end up half-written, in a state
 * nobody chose. The read's version travels back with the write, so a page left open
 * while somebody else saved is refused rather than silently overwriting them.
 *
 * The review step is not ceremony. A settings group is a batch of independent
 * decisions applied at once, and the moment worth interrupting is the one before an
 * operator commits to all of them.
 */
export function SettingsWorkspace({ name, definitions, onPendingChange }: SettingsWorkspaceProps) {
    const { t } = useTranslation();
    const { locale } = useDirection();
    const user = useCurrentUser();
    const queryClient = useQueryClient();

    const [draft, setDraft] = useState<Draft>({});
    const [stage, setStage] = useState<Stage>('editing');
    const [conflict, setConflict] = useState<string | null>(null);
    const [precondition, setPrecondition] = useState(false);
    const [rejections, setRejections] = useState<Record<string, string>>({});
    const [formError, setFormError] = useState<string | null>(null);
    const [saved, setSaved] = useState(false);

    const state = useQuery({
        queryKey: ['settings-group', name, locale],
        queryFn: ({ signal }) => fetchGroup(name, locale, signal),
    });

    const save = useMutation({
        mutationFn: () =>
            updateGroup(name, changedValues(fields), state.data?.version ?? '', locale),
        onMutate: () => {
            setConflict(null);
            setPrecondition(false);
            setRejections({});
            setFormError(null);
            setSaved(false);
        },
        onSuccess: async () => {
            setDraft({});
            setStage('editing');
            setSaved(true);
            onPendingChange(name, 0);
            await queryClient.invalidateQueries({ queryKey: ['settings-group', name] });
            await queryClient.invalidateQueries({ queryKey: ['settings-history', name] });
        },
        onError: (error: unknown) => {
            if (!(error instanceof ApiError)) {
                throw error;
            }

            setStage('editing');

            // 428: the request carried no precondition at all. It should be
            // unreachable from this client, which is exactly why it is worth
            // surfacing plainly rather than as a generic failure — it means the
            // version never arrived, not that anything is contended.
            if (error.is(ErrorCode.PreconditionRequired)) {
                setPrecondition(true);

                return;
            }

            // 412: someone else wrote first. The edits stay on screen — discarding
            // them would be the worst possible response to "your work is out of
            // date" — and the operator chooses between rebasing onto the current
            // values or dropping their own.
            if (error.is(ErrorCode.SettingVersionConflict)) {
                setConflict(error.currentVersion ?? '');

                return;
            }

            // A rejected value names its key, so a batch of twenty says which one was
            // refused rather than failing as a whole.
            const details = error.details as { key?: unknown; message?: unknown } | null;

            if (typeof details?.key === 'string') {
                setRejections({
                    [details.key]:
                        typeof details.message === 'string' ? details.message : error.message,
                });

                return;
            }

            const validation = error.validationDetails;

            if (validation === null) {
                setFormError(error.message);

                return;
            }

            const perField: Record<string, string> = {};
            const formLevel: string[] = [];

            for (const [key, messages] of Object.entries(validation)) {
                const message = messages[0] ?? error.message;

                // `settings` on its own is the batch, not a setting in it.
                if (key === 'settings') {
                    formLevel.push(message);

                    continue;
                }

                perField[key.replace(/^settings\./, '')] = message;
            }

            setRejections(perField);
            setFormError(formLevel.length > 0 ? formLevel.join(' ') : null);
        },
    });

    const fields = fieldStates({
        definitions,
        rows: state.data?.rows ?? [],
        draft,
        permissions: user.permissions,
        rejections,
        saving: save.isPending,
        conflicted: conflict !== null,
    });

    const pending = changedValues(fields);
    const pendingCount = Object.keys(pending).length;
    const blocked = invalidFields(fields);
    const dirty = pendingCount > 0;

    const stageValue = (key: string, value: unknown) => {
        setDraft((current) => {
            const next = { ...current, [key]: value };
            // Reported up so the group list can mark where unsaved work is. Counted
            // from the draft rather than from `fields`, which has not re-derived yet.
            onPendingChange(
                name,
                Object.keys(next).filter((candidate) => {
                    const field = fields.find((f) => settingKey(f.definition) === candidate);

                    return field === undefined || !Object.is(next[candidate], field.saved);
                }).length,
            );

            return next;
        });
    };

    /** Take the server's current values and keep the operator's edits on top. */
    const rebase = async () => {
        setConflict(null);
        await queryClient.invalidateQueries({ queryKey: ['settings-group', name] });
    };

    const discard = () => {
        setConflict(null);
        setDraft({});
        setStage('editing');
        onPendingChange(name, 0);
    };

    const mail = useMutation({ mutationFn: testMail });

    if (state.isPending) {
        return <p className="p-4 text-(--text-muted)">{t('state.loading')}</p>;
    }

    if (state.error !== null) {
        return (
            <div className="p-4">
                <Alert tone="danger">
                    {state.error instanceof ApiError ? state.error.message : t('state.error')}
                </Alert>
            </div>
        );
    }

    return (
        <div className="flex min-h-0 flex-1 flex-col">
            <header className="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1 border-b border-(--border-default) px-4 py-3">
                <div>
                    <p data-eyebrow>{t('settings.groupEyebrow')}</p>
                    <h1 className="text-(length:--text-xl) text-(--text-primary)">
                        {t(`settings.group.${name}`, { defaultValue: name })}
                    </h1>
                </div>

                <div className="flex flex-col items-end">
                    <p data-eyebrow>{t('settings.version')}</p>
                    <p
                        className="text-(length:--text-sm) text-(--text-muted)"
                        data-technical
                        title={t('settings.versionHelp')}
                    >
                        {state.data?.version.slice(0, 12)}
                    </p>
                </div>
            </header>

            <div className="min-h-0 flex-1 overflow-y-auto">
                <div className="flex flex-col gap-3 p-4">
                    {precondition ? (
                        <Alert title={t('settings.precondition.title')} tone="danger">
                            {t('settings.precondition.body')}
                        </Alert>
                    ) : null}

                    {conflict !== null ? (
                        <Alert title={t('settings.conflict.title')} tone="warning">
                            <p>{t('settings.conflict.body')}</p>
                            <dl className="mt-2 grid grid-cols-2 gap-x-4 gap-y-1">
                                <dt data-eyebrow>{t('settings.conflict.yours')}</dt>
                                <dt data-eyebrow>{t('settings.conflict.theirs')}</dt>
                                <dd className="text-(length:--text-sm)" data-technical>
                                    {state.data?.version.slice(0, 12)}
                                </dd>
                                <dd className="text-(length:--text-sm)" data-technical>
                                    {conflict.slice(0, 12)}
                                </dd>
                            </dl>
                            <div className="mt-2 flex flex-wrap gap-2">
                                <Button onClick={() => void rebase()} size="sm" variant="secondary">
                                    {t('settings.conflict.rebase')}
                                </Button>
                                <Button onClick={discard} size="sm" variant="ghost">
                                    {t('settings.conflict.discard')}
                                </Button>
                            </div>
                        </Alert>
                    ) : null}

                    {formError !== null ? <Alert tone="danger">{formError}</Alert> : null}

                    {saved && !dirty ? <Alert tone="success">{t('settings.saved')}</Alert> : null}

                    {stage === 'reviewing' ? (
                        <ReviewChanges
                            fields={fields}
                            onCancel={() => setStage('editing')}
                            onConfirm={() => save.mutate()}
                            saving={save.isPending}
                        />
                    ) : null}

                    <div className="border border-(--border-default) bg-(--surface-default)">
                        {fields.map((field) => (
                            <SettingRow
                                field={field}
                                group={name}
                                key={settingKey(field.definition)}
                                onChange={stageValue}
                                onRotated={() =>
                                    void queryClient.invalidateQueries({
                                        queryKey: ['settings-group', name],
                                    })
                                }
                                version={state.data?.version ?? ''}
                            />
                        ))}
                    </div>

                    {mail.isSuccess ? <Alert tone="success">{t('settings.mailSent')}</Alert> : null}
                    {mail.error instanceof ApiError ? (
                        <Alert tone="danger">{mail.error.message}</Alert>
                    ) : null}
                </div>
            </div>

            <footer className="flex flex-wrap items-center gap-2 border-t border-(--border-default) bg-(--surface-subtle) px-4 py-3">
                <Button
                    disabled={!dirty || blocked.length > 0 || stage === 'reviewing'}
                    onClick={() => setStage('reviewing')}
                    variant="primary"
                >
                    {t('settings.review.open', { count: pendingCount })}
                </Button>

                <Button disabled={!dirty} onClick={discard} variant="ghost">
                    {t('settings.discard')}
                </Button>

                {blocked.length > 0 ? (
                    <span className="text-(length:--text-sm) text-(--text-danger)">
                        {t('settings.blocked', { count: blocked.length })}
                    </span>
                ) : null}

                {name === 'mail' && user.permissions.includes('settings.update') ? (
                    <Button
                        className="ms-auto"
                        loading={mail.isPending}
                        onClick={() => mail.mutate()}
                        variant="secondary"
                    >
                        {t('settings.testMail')}
                    </Button>
                ) : null}
            </footer>
        </div>
    );
}
