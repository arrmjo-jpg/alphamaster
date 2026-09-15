import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';

import { ApiError, ErrorCode } from '@/api/errors';
import { useCurrentUser } from '@/auth/AuthProvider';
import { languages as adminLanguages } from '@/screens/languages/api';
import { useDirection } from '@/shell/DirectionProvider';
import { Alert } from '@/ui/Alert';
import { Button } from '@/ui/Button';
import { ContentLanguageSelector } from '@/ui/ContentLanguageSelector';

import { group as fetchGroup, testMail, updateGroup, type SettingDefinition } from './api';
import { changedValues, fieldStates, invalidFields, settingKey, type Draft } from './draft';
import { ReviewChanges } from './ReviewChanges';
import { SettingRow } from './SettingRow';
import { SocialLoginSetup } from './SocialLoginSetup';

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

    // The content language: which language's value is being edited. Not the console's
    // own language, which stays whatever the operator is reading in — an Arabic console
    // must be able to write the English site name with every label around it still
    // Arabic (ADR 0043, amended).
    const [contentLocale, setContentLocale] = useState<string | null>(null);

    const localized = definitions.some((definition) => definition.is_localized);

    const languages = useQuery({
        queryKey: ['admin-languages'],
        queryFn: ({ signal }) => adminLanguages(signal),
        // Only a group with something localized in it has a language to choose.
        enabled: localized,
        staleTime: 5 * 60_000,
    });

    // The platform's default language rather than the console's: the value being
    // written belongs to the site, and the operator's reading language says nothing
    // about which language they mean to write.
    const selectedLocale =
        contentLocale ?? languages.data?.find((language) => language.is_default)?.code ?? null;

    const [draft, setDraft] = useState<Draft>({});
    const [stage, setStage] = useState<Stage>('editing');
    const [conflict, setConflict] = useState<string | null>(null);
    const [precondition, setPrecondition] = useState(false);
    const [rejections, setRejections] = useState<Record<string, string>>({});
    const [formError, setFormError] = useState<string | null>(null);
    const [saved, setSaved] = useState(false);

    const state = useQuery({
        // Both languages are in the key, and they are different things: the console's
        // decides the labels the platform answers with, the content one decides which
        // values come back.
        queryKey: ['settings-group', name, locale, selectedLocale],
        queryFn: ({ signal }) => fetchGroup(name, selectedLocale ?? undefined, signal),
        // A localized group waits for the language list rather than reading one
        // language and then immediately re-reading another.
        enabled: !localized || selectedLocale !== null || languages.isError,
    });

    const save = useMutation({
        mutationFn: () =>
            updateGroup(
                name,
                changedValues(fields),
                state.data?.version ?? '',
                selectedLocale ?? undefined,
            ),
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
            // The setup summary is built from this group's addresses, so it is stale the
            // moment they are saved.
            await queryClient.invalidateQueries({ queryKey: ['social-login-setup'] });
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

            // A rejected value names its setting, so a batch of twenty says which one was
            // refused rather than failing as a whole. The platform names it by reference,
            // `group.key`, with its messages; a bare key and one message is accepted too.
            const details = error.details as {
                key?: unknown;
                message?: unknown;
                setting?: unknown;
                messages?: unknown;
            } | null;

            const rejectedKey =
                typeof details?.key === 'string'
                    ? details.key
                    : typeof details?.setting === 'string'
                      ? details.setting.slice(details.setting.indexOf('.') + 1)
                      : null;

            if (rejectedKey !== null) {
                const messages: unknown = details?.messages;
                const first: unknown = Array.isArray(messages)
                    ? (messages as unknown[])[0]
                    : undefined;

                setRejections({
                    [rejectedKey]:
                        typeof details?.message === 'string'
                            ? details.message
                            : typeof first === 'string'
                              ? first
                              : error.message,
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
                    {/* The group, not the page. `Settings` is the h1 above this and
                        the outline has to say so, or every group reads as a page of
                        its own with no parent. */}
                    <h2 className="text-(length:--text-xl) text-(--text-primary)">
                        {t(`settings.group.${name}`, { defaultValue: name })}
                    </h2>
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

                    {localized && languages.data !== undefined && languages.data.length > 0 ? (
                        <ContentLanguageSelector
                            id={`${name}-content-language`}
                            languages={languages.data}
                            onChange={(code) => {
                                // The draft belongs to the language it was typed in.
                                // Carrying it across would write one language's words
                                // into another.
                                setDraft({});
                                setStage('editing');
                                onPendingChange(name, 0);
                                setContentLocale(code);
                            }}
                            value={selectedLocale}
                        />
                    ) : null}

                    {name === 'auth' ? <SocialLoginSetup /> : null}

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

            {/* Pinned, in both layouts. Where the workspace is a pane it is already
                the last row and this changes nothing; where the page scrolls it keeps
                the save within reach of the field that was just edited rather than at
                the far end of a long list. */}
            <footer className="sticky bottom-0 z-10 flex flex-wrap items-center gap-2 border-t border-(--border-default) bg-(--surface-subtle) px-4 py-3">
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
