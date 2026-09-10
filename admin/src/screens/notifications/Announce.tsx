import { useMutation, useQueryClient } from '@tanstack/react-query';
import { Send } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';

import type { AnnouncementAudience } from '@/api/generated';
import { ApiError } from '@/api/errors';
import { Alert } from '@/ui/Alert';
import { Button } from '@/ui/Button';
import { Field } from '@/ui/Field';
import { Input } from '@/ui/Input';

import { announce } from './api';

const AUDIENCES: AnnouncementAudience[] = ['everyone', 'administrators'];

/**
 * Writing to the people on the platform.
 *
 * The one notification an administrator raises by hand. Every other type describes
 * something that happened and has a producer rather than a button; an announcement is
 * the case where the operator is the event.
 *
 * Sending is confirmed before it happens, in the shape the rest of this console uses
 * for an irreversible act — the trigger stays put and disabled, Cancel comes first —
 * because there is no unsend. The confirmation names the audience rather than asking
 * "are you sure": what makes this worth a second look is who it reaches.
 *
 * The audience is the platform's closed set, not a query composed here. Suspended
 * accounts are in neither, which the endpoint decides and this says.
 */
export function Announce() {
    const { t } = useTranslation();
    const queryClient = useQueryClient();

    const [subject, setSubject] = useState('');
    const [body, setBody] = useState('');
    const [audience, setAudience] = useState<AnnouncementAudience>('administrators');
    const [confirming, setConfirming] = useState(false);

    const send = useMutation({
        mutationFn: () => announce({ subject: subject.trim(), body: body.trim(), audience }),
        onSuccess: async () => {
            setConfirming(false);
            setSubject('');
            setBody('');
            // The sender is usually inside the audience they just wrote to.
            await queryClient.invalidateQueries({ queryKey: ['notification-inbox'] });
        },
    });

    const fieldError = (name: string): { error?: string } => {
        const message =
            send.error instanceof ApiError ? send.error.validationDetails?.[name]?.[0] : undefined;

        return message === undefined ? {} : { error: message };
    };

    const complete = subject.trim() !== '' && body.trim() !== '';

    return (
        <div className="flex min-w-0 flex-col gap-3 border border-(--border-default) bg-(--surface-default) p-3">
            <Field {...fieldError('subject')} label={t('notifications.announce.subject')} required>
                {({ id, 'aria-describedby': describedBy, invalid }) => (
                    <Input
                        aria-describedby={describedBy}
                        id={id}
                        invalid={invalid}
                        onChange={(event) => {
                            setSubject(event.target.value);
                            setConfirming(false);
                        }}
                        value={subject}
                    />
                )}
            </Field>

            <Field
                {...fieldError('body')}
                hint={t('notifications.announce.bodyHint')}
                label={t('notifications.announce.body')}
                required
            >
                {({ id, 'aria-describedby': describedBy, invalid }) => (
                    <textarea
                        aria-describedby={describedBy}
                        aria-invalid={invalid}
                        className="min-h-24 border border-(--border-strong) bg-(--surface-default) p-2 text-(length:--text-base) text-(--text-primary) focus-visible:outline-2 focus-visible:outline-offset-0 focus-visible:outline-(--focus-ring)"
                        id={id}
                        onChange={(event) => {
                            setBody(event.target.value);
                            setConfirming(false);
                        }}
                        rows={4}
                        value={body}
                    />
                )}
            </Field>

            <div className="flex flex-col gap-1">
                <label
                    className="text-(length:--text-sm) font-medium text-(--text-secondary)"
                    htmlFor="announcement-audience"
                >
                    {t('notifications.announce.audience')}
                </label>
                <select
                    className="h-(--field-height) border border-(--border-strong) bg-(--surface-default) px-2 text-(length:--text-base) text-(--text-primary) focus-visible:outline-2 focus-visible:outline-offset-0 focus-visible:outline-(--focus-ring)"
                    id="announcement-audience"
                    onChange={(event) => {
                        setAudience(event.target.value as AnnouncementAudience);
                        setConfirming(false);
                    }}
                    value={audience}
                >
                    {AUDIENCES.map((option) => (
                        <option key={option} value={option}>
                            {t(`notifications.announce.audiences.${option}`)}
                        </option>
                    ))}
                </select>
                <p className="text-(length:--text-sm) text-(--text-muted)">
                    {t('notifications.announce.audienceHint')}
                </p>
            </div>

            {send.error instanceof ApiError && send.error.validationDetails === null ? (
                <Alert tone="danger">{send.error.message}</Alert>
            ) : null}

            {send.isSuccess && !confirming ? (
                <Alert tone="success">
                    {t('notifications.announce.sent', { count: send.data.recipients })}
                </Alert>
            ) : null}

            <div className="flex flex-col gap-2">
                <div>
                    <Button
                        disabled={!complete || confirming}
                        onClick={() => setConfirming(true)}
                        variant="primary"
                    >
                        <Send aria-hidden className="size-3.5" />
                        {t('notifications.announce.send')}
                    </Button>
                </div>

                {confirming ? (
                    <div className="flex flex-col gap-2 border-s-(length:--rail-width) border-(--state-warning-rail) ps-2">
                        <p className="text-(length:--text-sm) text-(--state-warning-text)">
                            {t('notifications.announce.confirm', {
                                audience: t(`notifications.announce.audiences.${audience}`),
                            })}
                        </p>
                        {/* Cancel first: the cheapest mistake lands on the reversible
                            action, and this one has no undo. */}
                        <div className="flex flex-wrap gap-2">
                            <Button
                                onClick={() => setConfirming(false)}
                                size="sm"
                                variant="secondary"
                            >
                                {t('notifications.announce.cancel')}
                            </Button>
                            <Button
                                loading={send.isPending}
                                onClick={() => send.mutate()}
                                size="sm"
                                variant="primary"
                            >
                                {t('notifications.announce.confirmSend')}
                            </Button>
                        </div>
                    </div>
                ) : null}
            </div>
        </div>
    );
}
