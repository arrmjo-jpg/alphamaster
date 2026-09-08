import { ArrowRight } from 'lucide-react';
import { useTranslation } from 'react-i18next';

import { Button } from '@/ui/Button';

import type { FieldState } from './draft';
import { settingKey } from './draft';

export interface ReviewChangesProps {
    fields: FieldState[];
    saving: boolean;
    onConfirm: () => void;
    onCancel: () => void;
}

/**
 * Old beside new, before anything is written.
 *
 * A settings group is not a form: it is a batch of independent decisions applied
 * atomically, and the moment worth interrupting is the one before an operator commits
 * to all of them at once. Naming each affected key and showing what it was against
 * what it will be turns "save" from a hopeful act into a reviewed one.
 *
 * A secret never appears here — it is never in the draft to begin with, because
 * rotation is its own operation.
 */
export function ReviewChanges({ fields, saving, onConfirm, onCancel }: ReviewChangesProps) {
    const { t } = useTranslation();
    const changed = fields.filter((field) => field.changed);

    return (
        <section
            aria-label={t('settings.review.title')}
            className="border border-(--state-pending-rail) bg-(--surface-default)"
        >
            <header className="flex items-baseline justify-between gap-3 border-b border-(--border-default) bg-(--state-pending-tint) px-3 py-2.5">
                <h2 className="text-(--state-pending-text)" data-eyebrow>
                    {t('settings.review.title')}
                </h2>
                <span className="text-(length:--text-sm) text-(--state-pending-text)">
                    {t('settings.review.count', { count: changed.length })}
                </span>
            </header>

            <ul className="divide-y divide-(--border-default)">
                {changed.map((field) => (
                    <li className="px-3 py-2.5" key={settingKey(field.definition)}>
                        <p className="text-(length:--text-sm) font-medium text-(--text-primary)">
                            {field.definition.label}
                        </p>
                        <p className="text-(length:--text-xs) text-(--text-muted)" data-technical>
                            {settingKey(field.definition)}
                        </p>

                        <div className="mt-1.5 grid grid-cols-[1fr_auto_1fr] items-start gap-2">
                            <Value label={t('settings.review.current')} value={field.saved} />
                            <ArrowRight
                                aria-hidden
                                className="mt-5 size-3.5 shrink-0 text-(--text-muted) rtl:rotate-180"
                            />
                            <Value emphasis label={t('settings.review.next')} value={field.value} />
                        </div>
                    </li>
                ))}
            </ul>

            <footer className="flex flex-wrap gap-2 border-t border-(--border-default) px-3 py-2.5">
                <Button loading={saving} onClick={onConfirm} variant="primary">
                    {t('settings.review.confirm', { count: changed.length })}
                </Button>
                <Button disabled={saving} onClick={onCancel} variant="ghost">
                    {t('settings.review.back')}
                </Button>
            </footer>
        </section>
    );
}

function Value({
    label,
    value,
    emphasis = false,
}: {
    label: string;
    value: unknown;
    emphasis?: boolean;
}) {
    const { t } = useTranslation();

    return (
        <div className="min-w-0">
            <p data-eyebrow>{label}</p>
            <p
                className={
                    'mt-0.5 break-words text-(length:--text-sm) ' +
                    (emphasis ? 'text-(--text-primary)' : 'text-(--text-muted) line-through')
                }
                data-technical
            >
                {format(value) ?? t('settings.notSet')}
            </p>
        </div>
    );
}

/** A stored value as one readable line, whatever its type. */
function format(value: unknown): string | null {
    if (value === null || value === undefined || value === '') {
        return null;
    }

    if (typeof value === 'string') {
        return value;
    }

    if (typeof value === 'boolean') {
        return value ? 'true' : 'false';
    }

    return JSON.stringify(value);
}
