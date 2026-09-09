import { useMutation } from '@tanstack/react-query';
import { KeyRound } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';

import { ApiError } from '@/api/errors';
import { Alert } from '@/ui/Alert';
import { Button } from '@/ui/Button';
import { Input } from '@/ui/Input';

import { rotateSecret } from './api';

export interface SecretFieldProps {
    group: string;
    settingKey: string;
    /** Whether the platform holds a value. Never the value itself. */
    configured: boolean;
    version: string;
    canRotate: boolean;
    onRotated: () => void;
}

/**
 * A credential: shown as present or absent, never as a value.
 *
 * The platform does not return it and this cannot display it. Rendering an empty text
 * field here would invite an operator to type into something that would then write an
 * empty credential, which is a different operation with a different audit action.
 *
 * Rotation is one request, not a wizard. The platform takes a candidate, verifies it,
 * and commits only if that verification permits — a failure is a 422 carrying the
 * result, and the stored credential is exactly what it was: not cleared, not
 * replaced, not partially applied (ADR 0038). Presenting separate verify and commit
 * steps would describe a sequence the backend does not have and would imply a window
 * in which the old credential was already gone.
 *
 * It carries the group's precondition for the same reason every other write does.
 */
export function SecretField({
    group,
    settingKey,
    configured,
    version,
    canRotate,
    onRotated,
}: SecretFieldProps) {
    const { t } = useTranslation();
    const [open, setOpen] = useState(false);
    const [candidate, setCandidate] = useState('');

    const rotation = useMutation({
        mutationFn: () => rotateSecret(group, settingKey, candidate, version),
        onSuccess: () => {
            // Cleared immediately. A credential has no business staying in a form
            // field once it has been accepted.
            setCandidate('');
            setOpen(false);
            onRotated();
        },
    });

    const failure = rotation.error instanceof ApiError ? rotation.error : null;

    return (
        <div className="flex flex-col gap-2">
            <div className="flex flex-wrap items-center gap-2">
                <KeyRound aria-hidden className="size-4 text-(--text-muted)" />
                <span className="text-(length:--text-sm) text-(--text-primary)">
                    {configured ? t('settings.secretSet') : t('settings.secretUnset')}
                </span>

                {canRotate ? (
                    <Button
                        onClick={() => setOpen((current) => !current)}
                        size="sm"
                        variant="ghost"
                    >
                        {open ? t('settings.history.cancel') : t('settings.secret.rotate')}
                    </Button>
                ) : null}
            </div>

            <p className="text-(length:--text-sm) text-(--text-muted)">
                {t('settings.secretExplanation')}
            </p>

            {open ? (
                <form
                    className="flex flex-col gap-2 border-s-(length:--rail-width) border-(--state-warning-rail) bg-(--surface-subtle) p-3"
                    onSubmit={(event) => {
                        event.preventDefault();
                        rotation.mutate();
                    }}
                >
                    <label
                        className="text-(length:--text-sm) font-medium text-(--text-secondary)"
                        htmlFor={`${group}-${settingKey}-candidate`}
                    >
                        {t('settings.secret.candidate')}
                    </label>

                    <Input
                        autoComplete="off"
                        id={`${group}-${settingKey}-candidate`}
                        onChange={(event) => setCandidate(event.target.value)}
                        type="password"
                        value={candidate}
                    />

                    <p className="text-(length:--text-sm) text-(--text-muted)">
                        {t('settings.secret.verifyNote')}
                    </p>

                    {failure !== null ? (
                        <Alert tone="danger">
                            <p>{failure.message}</p>
                            <p className="mt-1 text-(length:--text-sm)">
                                {t('settings.secret.unchangedOnFailure')}
                            </p>
                        </Alert>
                    ) : null}

                    <div>
                        <Button
                            disabled={candidate === ''}
                            loading={rotation.isPending}
                            type="submit"
                            variant="primary"
                        >
                            {t('settings.secret.commit')}
                        </Button>
                    </div>
                </form>
            ) : null}
        </div>
    );
}
