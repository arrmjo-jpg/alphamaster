import { Check, Copy } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';

import { ApiError } from '@/api/errors';
import { beginMfaEnrolment, confirmMfaEnrolment } from '@/auth/api';
import { useAuth } from '@/auth/AuthProvider';
import type { MfaEnrolment } from '@/auth/contract';
import { Alert } from '@/ui/Alert';
import { Button } from '@/ui/Button';
import { Field } from '@/ui/Field';
import { Input } from '@/ui/Input';
import { SegmentedControl } from '@/ui/SegmentedControl';

import { AuthCover } from './AuthCover';

type Method = 'totp' | 'sms_otp';
type Stage = 'choose' | 'confirm' | 'recovery';

/**
 * Enrolling the second factor an administrator cannot proceed without.
 *
 * Three stages, because they are three different decisions: which method, then
 * proving possession of it, then keeping the recovery codes. The last one is a stage
 * rather than a toast for a reason — the codes are shown once and never again, so the
 * screen refuses to move on until they have been acknowledged.
 *
 * Confirming is also the exchange: an administrator who arrived here on an enrolment
 * credential receives a real session in the same response (ADR 0013), which is why
 * nothing here signs in a second time.
 */
export function MfaEnrolmentScreen() {
    const { t } = useTranslation();
    const { enrolmentCompleted, signOut } = useAuth();

    const [method, setMethod] = useState<Method>('totp');
    const [phone, setPhone] = useState('');
    const [stage, setStage] = useState<Stage>('choose');
    const [enrolment, setEnrolment] = useState<MfaEnrolment | null>(null);
    const [code, setCode] = useState('');
    const [recoveryCodes, setRecoveryCodes] = useState<string[]>([]);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [acknowledged, setAcknowledged] = useState(false);
    const [copied, setCopied] = useState(false);

    const begin = async () => {
        setBusy(true);
        setError(null);

        try {
            const started = await beginMfaEnrolment(
                method,
                method === 'sms_otp' ? phone : undefined,
            );
            setEnrolment(started);
            setStage('confirm');
        } catch (caught) {
            if (!(caught instanceof ApiError)) {
                throw caught;
            }

            setError(caught.message);
        } finally {
            setBusy(false);
        }
    };

    const confirm = async () => {
        setBusy(true);
        setError(null);

        try {
            const result = await confirmMfaEnrolment(method, code);
            setRecoveryCodes(result.recovery_codes);
            setStage('recovery');
        } catch (caught) {
            if (!(caught instanceof ApiError)) {
                throw caught;
            }

            setError(caught.message);
            setCode('');
        } finally {
            setBusy(false);
        }
    };

    return (
        <AuthCover
            description={t('auth.enrol.description')}
            footer={
                stage === 'recovery' ? null : (
                    <Button className="w-full" onClick={() => void signOut()} variant="ghost">
                        {t('auth.startOver')}
                    </Button>
                )
            }
            title={t('auth.enrol.title')}
        >
            <div className="flex flex-col gap-4">
                {error !== null ? <Alert tone="danger">{error}</Alert> : null}

                {stage === 'choose' ? (
                    <>
                        <div className="flex flex-col gap-2">
                            <span className="text-(length:--text-sm) font-medium text-(--text-secondary)">
                                {t('auth.enrol.method')}
                            </span>
                            <SegmentedControl<Method>
                                label={t('auth.enrol.method')}
                                onChange={setMethod}
                                options={[
                                    { value: 'totp', label: t('auth.enrol.totp') },
                                    { value: 'sms_otp', label: t('auth.enrol.sms') },
                                ]}
                                value={method}
                            />
                        </div>

                        {method === 'sms_otp' ? (
                            <Field
                                hint={t('auth.enrol.phoneHint')}
                                label={t('auth.enrol.phone')}
                                required
                            >
                                {({ id, ...described }) => (
                                    <Input
                                        autoComplete="tel"
                                        id={id}
                                        inputMode="tel"
                                        onChange={(event) => setPhone(event.target.value)}
                                        value={phone}
                                        {...described}
                                    />
                                )}
                            </Field>
                        ) : null}

                        <Button
                            disabled={method === 'sms_otp' && phone.trim() === ''}
                            loading={busy}
                            onClick={() => void begin()}
                            variant="primary"
                        >
                            {t('auth.enrol.begin')}
                        </Button>
                    </>
                ) : null}

                {stage === 'confirm' && enrolment !== null ? (
                    <>
                        {enrolment.uri !== undefined ? (
                            <TotpEnrolment secret={enrolment.secret ?? ''} uri={enrolment.uri} />
                        ) : null}

                        {enrolment.destination !== undefined ? (
                            <Alert tone="info">
                                {t('auth.mfa.sentTo', { destination: enrolment.destination })}
                            </Alert>
                        ) : null}

                        <Field hint={t('auth.mfa.codeHint')} label={t('auth.mfa.code')} required>
                            {({ id, ...described }) => (
                                <Input
                                    autoComplete="one-time-code"
                                    id={id}
                                    inputMode="numeric"
                                    onChange={(event) => setCode(event.target.value)}
                                    value={code}
                                    {...described}
                                />
                            )}
                        </Field>

                        <Button
                            disabled={code.trim() === ''}
                            loading={busy}
                            onClick={() => void confirm()}
                            variant="primary"
                        >
                            {t('auth.enrol.confirm')}
                        </Button>
                    </>
                ) : null}

                {stage === 'recovery' ? (
                    <>
                        <Alert title={t('auth.enrol.recoveryTitle')} tone="warning">
                            {t('auth.enrol.recoveryBody')}
                        </Alert>

                        <ul
                            className="grid grid-cols-2 gap-1 border border-(--border-default) bg-(--surface-subtle) p-3"
                            data-technical
                        >
                            {recoveryCodes.map((recoveryCode) => (
                                <li className="text-(length:--text-sm)" key={recoveryCode}>
                                    {recoveryCode}
                                </li>
                            ))}
                        </ul>

                        <Button
                            onClick={() => {
                                void navigator.clipboard
                                    ?.writeText(recoveryCodes.join('\n'))
                                    .then(() => setCopied(true))
                                    .catch(() => setCopied(false));
                            }}
                            variant="secondary"
                        >
                            {copied ? (
                                <Check aria-hidden className="size-4" />
                            ) : (
                                <Copy aria-hidden className="size-4" />
                            )}
                            {copied ? t('auth.enrol.copied') : t('auth.enrol.copy')}
                        </Button>

                        <label className="flex items-start gap-2 text-(--text-secondary)">
                            <input
                                checked={acknowledged}
                                className="mt-1"
                                onChange={(event) => setAcknowledged(event.target.checked)}
                                type="checkbox"
                            />
                            {t('auth.enrol.acknowledge')}
                        </label>

                        <Button
                            disabled={!acknowledged}
                            onClick={() => void enrolmentCompleted()}
                            variant="primary"
                        >
                            {t('auth.enrol.continue')}
                        </Button>
                    </>
                ) : null}
            </div>
        </AuthCover>
    );
}

/**
 * The provisioning URI as a QR code, with the secret spelled out beneath it.
 *
 * Both, because the QR is unusable on the machine the authenticator app is not on,
 * and a base32 secret typed by hand is how enrolment fails at the last step. The
 * encoder runs in the bundle — nothing about this secret leaves the page.
 */
function TotpEnrolment({ uri, secret }: { uri: string; secret: string }) {
    const { t } = useTranslation();
    const canvas = useRef<HTMLCanvasElement>(null);
    const [failed, setFailed] = useState(false);

    useEffect(() => {
        const element = canvas.current;

        if (element === null) {
            return;
        }

        // Imported here rather than at the top of the file so the encoder is its own
        // chunk. It is needed on exactly one screen, once per administrator, and
        // carrying it in the initial bundle would make every page load pay for
        // enrolment.
        void import('qrcode')
            .then((module) => module.default.toCanvas(element, uri, { margin: 1, width: 180 }))
            .catch(() => setFailed(true));
    }, [uri]);

    return (
        <div className="flex flex-col items-center gap-3">
            {failed ? (
                <Alert tone="warning">{t('auth.enrol.qrFailed')}</Alert>
            ) : (
                <canvas
                    aria-label={t('auth.enrol.qrLabel')}
                    className="bg-white p-2"
                    ref={canvas}
                    role="img"
                />
            )}

            <div className="w-full text-center">
                <p className="text-(length:--text-sm) text-(--text-muted)">
                    {t('auth.enrol.secretLabel')}
                </p>
                <p className="text-(length:--text-sm) text-(--text-primary)" data-technical>
                    {secret}
                </p>
            </div>
        </div>
    );
}
