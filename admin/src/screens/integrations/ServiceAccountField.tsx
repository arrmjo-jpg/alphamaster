import { useId, useState } from 'react';
import { useTranslation } from 'react-i18next';

import { Alert } from '@/ui/Alert';
import { Button } from '@/ui/Button';

/** The fields FCM v1 needs from a Google service-account document. */
const REQUIRED = ['project_id', 'client_email', 'private_key'] as const;

export interface ServiceAccountFieldProps {
    busy: boolean;
    /** The platform's refusal of the last submission, where there was one. */
    error?: string;
    /** Receives the document exactly as pasted. */
    onSubmit: (document: string) => void;
    onCancel: () => void;
}

/**
 * A Google service-account file, pasted whole.
 *
 * Firebase authenticates with a JSON file containing a private key rather than with an
 * API key (ADR 0045 §2). The operator pastes the file as Firebase issued it and the
 * document is sent as it is: the platform is what validates it and decides what to keep
 * — the project, the client address and the private key, encrypted — so there is one
 * definition of a usable service account, not one here and another on the server.
 *
 * What this component checks is only enough to say something useful before sending:
 * that the text is JSON and names the fields a service account has. It names which of
 * them it found, never their values, and the key itself is never rendered anywhere but
 * the textarea the operator pasted it into.
 */
export function ServiceAccountField({ busy, error, onSubmit, onCancel }: ServiceAccountFieldProps) {
    const { t } = useTranslation();
    const id = useId();
    const [text, setText] = useState('');

    const parsed = parse(text);
    const missing = parsed === null ? [...REQUIRED] : REQUIRED.filter((key) => !parsed[key]);

    return (
        <div className="flex flex-col gap-2">
            <label
                className="text-(length:--text-sm) font-medium text-(--text-secondary)"
                htmlFor={id}
            >
                {t('integrations.serviceAccount.label')}
            </label>
            <textarea
                // Never remembered or suggested by the browser, for the reason the
                // key-value editor gives: a credential offered back on another screen is
                // the one way a value this console cannot read could still leak from it.
                autoComplete="off"
                className="min-h-40 w-full border border-(--border-strong) bg-(--surface-default) p-2 font-mono text-(length:--text-xs) text-(--text-primary) focus-visible:outline-2 focus-visible:outline-offset-0 focus-visible:outline-(--focus-ring)"
                data-technical
                id={id}
                onChange={(event) => setText(event.target.value)}
                spellCheck={false}
                value={text}
            />
            <p className="text-(length:--text-xs) text-(--text-muted)">
                {t('integrations.serviceAccount.hint')}
            </p>

            {text.trim() !== '' && parsed === null ? (
                <Alert tone="danger">{t('integrations.serviceAccount.notJson')}</Alert>
            ) : null}

            {parsed !== null && missing.length > 0 ? (
                <Alert tone="warning">
                    {t('integrations.serviceAccount.missing', {
                        fields: missing.join(t('list.separator')),
                    })}
                </Alert>
            ) : null}

            {parsed !== null && missing.length === 0 ? (
                // Which fields were found, and never what they hold.
                <p className="text-(length:--text-xs) text-(--state-success-text)">
                    {t('integrations.serviceAccount.found', { project: parsed.project_id })}
                </p>
            ) : null}

            {error === undefined ? null : <Alert tone="danger">{error}</Alert>}

            <div className="flex flex-wrap gap-2">
                <Button
                    disabled={parsed === null || missing.length > 0}
                    loading={busy}
                    // The text stays until the platform accepts it, so a refusal can be
                    // corrected in place; the panel closes on success and takes it along.
                    onClick={() => onSubmit(text.trim())}
                    size="sm"
                    variant="primary"
                >
                    {t('integrations.serviceAccount.save')}
                </Button>
                <Button
                    onClick={() => {
                        setText('');
                        onCancel();
                    }}
                    size="sm"
                    variant="ghost"
                >
                    {t('integrations.cancel')}
                </Button>
            </div>
        </div>
    );
}

/**
 * The document's string fields, or null when the text is not a JSON object.
 */
function parse(text: string): Record<string, string | undefined> | null {
    if (text.trim() === '') {
        return null;
    }

    try {
        const value: unknown = JSON.parse(text);

        if (value === null || typeof value !== 'object' || Array.isArray(value)) {
            return null;
        }

        const fields: Record<string, string | undefined> = {};

        for (const [key, entry] of Object.entries(value as Record<string, unknown>)) {
            if (typeof entry === 'string' && entry.trim() !== '') {
                fields[key] = entry;
            }
        }

        return fields;
    } catch {
        return null;
    }
}
