import { useId, useState } from 'react';
import { useTranslation } from 'react-i18next';

import { Alert } from '@/ui/Alert';
import { Button } from '@/ui/Button';

/** The three fields FCM v1 needs from a Google service-account document. */
const REQUIRED = ['project_id', 'client_email', 'private_key'] as const;

export interface ServiceAccountFieldProps {
    busy: boolean;
    onSubmit: (credentials: Record<string, string>) => void;
    onCancel: () => void;
}

/**
 * A Google service-account document, pasted whole.
 *
 * Firebase authenticates with a JSON file containing a private key rather than with an
 * API key (ADR 0045 §2), and a private key is multi-line PEM — which the key-value
 * editor's single-line inputs cannot hold without mangling it. So this driver takes a
 * paste of the file, parses it here, and sends only the three fields the driver uses.
 *
 * Nothing is echoed back. The panel names which of the required fields it found, never
 * their values, and the text is dropped the moment it is sent or cancelled. The key
 * itself is never rendered anywhere but the textarea the operator pasted it into.
 */
export function ServiceAccountField({ busy, onSubmit, onCancel }: ServiceAccountFieldProps) {
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

            <div className="flex flex-wrap gap-2">
                <Button
                    disabled={parsed === null || missing.length > 0}
                    loading={busy}
                    onClick={() => {
                        if (parsed === null) {
                            return;
                        }

                        // Only what the driver reads. A service-account file carries a
                        // dozen other fields, and storing what nothing uses is keeping
                        // secret material for no reason.
                        onSubmit({
                            project_id: parsed.project_id ?? '',
                            client_email: parsed.client_email ?? '',
                            private_key: parsed.private_key ?? '',
                        });
                        setText('');
                    }}
                    size="sm"
                    variant="primary"
                >
                    {t('integrations.credentials.submit')}
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
