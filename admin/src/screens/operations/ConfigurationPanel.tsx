import { useMutation } from '@tanstack/react-query';
import { Download, Upload } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';

import { ApiError } from '@/api/errors';
import { Alert } from '@/ui/Alert';
import { Button } from '@/ui/Button';
import { Checkbox } from '@/ui/Checkbox';
import { Field } from '@/ui/Field';
import { Input } from '@/ui/Input';

import { exportConfiguration, restoreConfiguration } from './api';

/** The endpoint's own rule: a path on the configured disk that cannot climb out of it. */
const LOCATION = /^[A-Za-z0-9._/-]+$/;

/**
 * Moving configuration in and out of this deployment (ADR 0039).
 *
 * Not a database backup, and the panel says so: infrastructure takes those, on its own
 * schedule, with its own retention. This is the other thing — a way to seed an
 * environment, move configuration deliberately, and answer "what was this set to"
 * without restoring a whole database.
 *
 * Neither direction offers a file. The export writes to the configured disk and answers
 * with a receipt; a download would be a second copy of the artefact travelling by a
 * route with different access controls, which is why the endpoint does not return one.
 * The restore takes a location on that same disk for the same reason.
 *
 * A restore is the destructive one — it rewrites configuration wholesale from a
 * document the operator may not have read — so it confirms, and the confirmation
 * appears below the trigger with the reversible control first.
 */
export function ConfigurationPanel() {
    const { t } = useTranslation();

    const [includeSecrets, setIncludeSecrets] = useState(false);
    const [location, setLocation] = useState('');
    const [confirming, setConfirming] = useState(false);

    const write = useMutation({ mutationFn: () => exportConfiguration(includeSecrets) });
    const read = useMutation({
        mutationFn: () => restoreConfiguration(location.trim()),
        onSuccess: () => setConfirming(false),
    });

    const locationValid = location.trim() !== '' && LOCATION.test(location.trim());

    return (
        <div className="grid grid-cols-1 gap-(--section-gap) xl:grid-cols-2">
            <section className="flex flex-col gap-3 border border-(--border-default) bg-(--surface-default) p-3">
                <div>
                    <h3 data-eyebrow>{t('operations.configuration.export.title')}</h3>
                    <p className="mt-1 text-(--text-secondary)">
                        {t('operations.configuration.export.body')}
                    </p>
                </div>

                <label className="flex items-start gap-2">
                    <Checkbox
                        checked={includeSecrets}
                        onChange={(event) => setIncludeSecrets(event.target.checked)}
                    />
                    <span className="flex flex-col">
                        <span className="text-(--text-primary)">
                            {t('operations.configuration.export.includeSecrets')}
                        </span>
                        <span className="text-(length:--text-sm) text-(--text-muted)">
                            {t('operations.configuration.export.includeSecretsNote')}
                        </span>
                    </span>
                </label>

                {write.error instanceof ApiError ? (
                    <Alert tone="danger">{write.error.message}</Alert>
                ) : null}

                {write.data !== undefined ? (
                    <Alert title={t('operations.configuration.export.written')} tone="success">
                        <p className="break-all" data-technical>
                            {write.data.location}
                        </p>
                        <p className="mt-1">
                            {t('operations.configuration.export.sections', {
                                sections: write.data.sections.join(', '),
                            })}
                        </p>
                        {/* Named rather than counted: an operator restoring this
                            elsewhere needs to know which credentials they will have to
                            supply by hand. */}
                        {write.data.omitted_secrets.length > 0 ? (
                            <p className="mt-1">
                                {t('operations.configuration.export.omitted', {
                                    keys: write.data.omitted_secrets.join(', '),
                                })}
                            </p>
                        ) : null}
                    </Alert>
                ) : null}

                <div>
                    <Button
                        loading={write.isPending}
                        onClick={() => write.mutate()}
                        variant="secondary"
                    >
                        <Download aria-hidden className="size-3.5" />
                        {t('operations.configuration.export.action')}
                    </Button>
                </div>
            </section>

            <section className="flex flex-col gap-3 border border-(--border-default) bg-(--surface-default) p-3">
                <div>
                    <h3 data-eyebrow>{t('operations.configuration.restore.title')}</h3>
                    <p className="mt-1 text-(--text-secondary)">
                        {t('operations.configuration.restore.body')}
                    </p>
                </div>

                <Field
                    hint={t('operations.configuration.restore.locationHint')}
                    label={t('operations.configuration.restore.location')}
                    {...(location === '' || locationValid
                        ? {}
                        : { error: t('operations.configuration.restore.locationInvalid') })}
                >
                    {({ id, 'aria-describedby': describedBy, invalid }) => (
                        <Input
                            aria-describedby={describedBy}
                            data-technical
                            id={id}
                            invalid={invalid}
                            onChange={(event) => setLocation(event.target.value)}
                            placeholder="configuration/2026-09-09.json"
                            value={location}
                        />
                    )}
                </Field>

                <div>
                    <Button
                        disabled={!locationValid || confirming}
                        onClick={() => setConfirming(true)}
                        variant="secondary"
                    >
                        <Upload aria-hidden className="size-3.5" />
                        {t('operations.configuration.restore.action')}
                    </Button>
                </div>

                {confirming ? (
                    <div className="flex flex-col gap-2 border-s-(length:--rail-width) border-(--state-danger-rail) ps-2">
                        <p className="text-(length:--text-sm) text-(--state-danger-text)">
                            {t('operations.configuration.restore.warning')}
                        </p>
                        {/* Cancel first: it is the control nearest the trigger, so the
                            cheapest mistake lands on the reversible action. */}
                        <div className="flex flex-wrap gap-2">
                            <Button
                                onClick={() => setConfirming(false)}
                                size="sm"
                                variant="secondary"
                            >
                                {t('operations.cancel')}
                            </Button>
                            <Button
                                loading={read.isPending}
                                onClick={() => read.mutate()}
                                size="sm"
                                variant="danger"
                            >
                                {t('operations.configuration.restore.confirm')}
                            </Button>
                        </div>
                    </div>
                ) : null}

                {read.error instanceof ApiError ? (
                    <Alert tone="danger">{read.error.message}</Alert>
                ) : null}

                {read.data !== undefined ? (
                    <Alert
                        title={t('operations.configuration.restore.done')}
                        tone={read.data.encrypted_restorable ? 'success' : 'warning'}
                    >
                        <p>
                            {read.data.encrypted_restorable
                                ? t('operations.configuration.restore.keyMatched')
                                : t('operations.configuration.restore.keyDiffered')}
                        </p>
                        <ul className="mt-2 flex flex-col gap-1">
                            {read.data.sections.map((section) => (
                                <li key={section.section}>
                                    <span data-technical>{section.section}</span>
                                    {': '}
                                    {t('operations.configuration.restore.restored', {
                                        count: section.restored,
                                    })}
                                    {section.skipped.length > 0 ? (
                                        <ul className="mt-0.5 flex flex-col">
                                            {section.skipped.map((skip) => (
                                                <li
                                                    className="text-(length:--text-sm) text-(--text-muted)"
                                                    key={skip.key}
                                                >
                                                    <span data-technical>{skip.key}</span> —{' '}
                                                    {skip.reason}
                                                </li>
                                            ))}
                                        </ul>
                                    ) : null}
                                </li>
                            ))}
                        </ul>
                    </Alert>
                ) : null}
            </section>
        </div>
    );
}
