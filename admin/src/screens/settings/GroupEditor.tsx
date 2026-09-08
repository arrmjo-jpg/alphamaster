import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';

import { ApiError, ErrorCode } from '@/api/errors';
import { useCurrentUser } from '@/auth/AuthProvider';
import { useDirection } from '@/shell/DirectionProvider';
import { Alert } from '@/ui/Alert';
import { Button } from '@/ui/Button';
import { Panel } from '@/ui/Panel';

import { group as fetchGroup, testMail, updateGroup, type SettingDefinition } from './api';
import { changedValues, fieldStates, settingKey, type Draft } from './draft';
import { HistoryPanel } from './HistoryPanel';
import { SettingField } from './SettingField';

export interface GroupEditorProps {
    name: string;
    definitions: SettingDefinition[];
}

/**
 * One group of settings, edited as a batch.
 *
 * Everything changed is written in one request, because the platform applies a batch
 * atomically: a request per field would let a group end up half-written, in a state
 * nobody chose. The read's version travels back with the write, so a page left open
 * while somebody else saved is refused rather than silently overwriting them.
 */
export function GroupEditor({ name, definitions }: GroupEditorProps) {
    const { t } = useTranslation();
    const { locale } = useDirection();
    const user = useCurrentUser();
    const queryClient = useQueryClient();

    const [draft, setDraft] = useState<Draft>({});
    const [conflict, setConflict] = useState<string | null>(null);
    const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({});
    // A refusal that names no field — the batch itself was malformed — has nowhere to
    // sit beside an input, and would otherwise be swallowed: the save button would
    // simply do nothing, twice, and then the operator would reload.
    const [formError, setFormError] = useState<string | null>(null);
    const [saved, setSaved] = useState(false);

    const state = useQuery({
        queryKey: ['settings-group', name, locale],
        queryFn: ({ signal }) => fetchGroup(name, locale, signal),
    });

    const fields = fieldStates(definitions, state.data?.rows ?? [], draft, user.permissions);
    const pending = changedValues(fields);
    const dirty = Object.keys(pending).length > 0;

    const save = useMutation({
        mutationFn: () => updateGroup(name, pending, state.data?.version ?? '', locale),
        onMutate: () => {
            setConflict(null);
            setFieldErrors({});
            setFormError(null);
            setSaved(false);
        },
        onSuccess: async () => {
            setDraft({});
            setSaved(true);
            await queryClient.invalidateQueries({ queryKey: ['settings-group', name] });
            await queryClient.invalidateQueries({ queryKey: ['settings-history', name] });
        },
        onError: (error: unknown) => {
            if (!(error instanceof ApiError)) {
                throw error;
            }

            // Someone else wrote first. The edits stay on screen — discarding them
            // would be the worst possible response to "your work is out of date" —
            // and the operator is told what happened and given the one action that
            // resolves it.
            if (error.is(ErrorCode.SettingVersionConflict)) {
                setConflict(error.currentVersion ?? '');

                return;
            }

            // A rejected value names its key, so a batch of twenty says which one was
            // refused rather than failing as a whole.
            const details = error.details as { key?: unknown; message?: unknown } | null;

            if (typeof details?.key === 'string') {
                setFieldErrors({
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

            setFieldErrors(perField);
            setFormError(formLevel.length > 0 ? formLevel.join(' ') : null);
        },
    });

    const reload = async () => {
        setConflict(null);
        await queryClient.invalidateQueries({ queryKey: ['settings-group', name] });
    };

    const mail = useMutation({ mutationFn: testMail });

    return (
        <div className="flex flex-col gap-(--section-gap)">
            <Panel
                aside={
                    state.data !== undefined ? (
                        <span data-technical title={t('settings.versionHelp')}>
                            {state.data.version.slice(0, 12)}
                        </span>
                    ) : undefined
                }
                error={state.error}
                loading={state.isPending}
                onRetry={() => void state.refetch()}
                title={name}
            >
                <div className="flex flex-col gap-4">
                    {conflict !== null ? (
                        <Alert title={t('settings.conflict.title')} tone="warning">
                            <p>{t('settings.conflict.body')}</p>
                            <Button
                                className="mt-2"
                                onClick={() => void reload()}
                                size="sm"
                                variant="secondary"
                            >
                                {t('settings.conflict.reload')}
                            </Button>
                        </Alert>
                    ) : null}

                    {formError !== null ? <Alert tone="danger">{formError}</Alert> : null}

                    {saved && !dirty ? <Alert tone="success">{t('settings.saved')}</Alert> : null}

                    {fields.map((field) => (
                        <SettingField
                            field={field}
                            key={field.definition.key}
                            onChange={(key, value) =>
                                setDraft((current) => ({ ...current, [key]: value }))
                            }
                            {...(fieldErrors[settingKey(field.definition)] !== undefined
                                ? { error: fieldErrors[settingKey(field.definition)] }
                                : {})}
                        />
                    ))}

                    <div className="flex flex-wrap items-center gap-2">
                        <Button
                            disabled={!dirty}
                            loading={save.isPending}
                            onClick={() => save.mutate()}
                            variant="primary"
                        >
                            {t('settings.save', { count: Object.keys(pending).length })}
                        </Button>

                        <Button disabled={!dirty} onClick={() => setDraft({})} variant="ghost">
                            {t('settings.discard')}
                        </Button>

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
                    </div>

                    {mail.isSuccess ? <Alert tone="success">{t('settings.mailSent')}</Alert> : null}
                    {mail.error instanceof ApiError ? (
                        <Alert tone="danger">{mail.error.message}</Alert>
                    ) : null}
                </div>
            </Panel>

            <HistoryPanel group={name} version={state.data?.version ?? ''} />
        </div>
    );
}
