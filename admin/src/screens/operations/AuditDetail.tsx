import { X } from 'lucide-react';
import { useTranslation } from 'react-i18next';

import { absoluteTime, relativeTime } from '@/lib/time';
import { useDirection } from '@/shell/DirectionProvider';
import { Button } from '@/ui/Button';
import { StatusBadge } from '@/ui/StatusBadge';

import type { AuditRecord } from './api';

export interface AuditDetailProps {
    record: AuditRecord;
    onClose: () => void;
    /** Narrows the trail to everything this actor, subject or action produced. */
    onFilter: (patch: { actorId?: string; subject?: string; action?: string }) => void;
}

/**
 * One recorded action, in full.
 *
 * `context` is rendered as it was stored, and that is safe here and only here: the
 * redaction is structural. No code path writes a secret into it, so there is nothing
 * for this panel to remember to strip — and a panel that filtered on the way out would
 * imply the stored record needed filtering, while being the wrong place to fix it if it
 * ever did.
 *
 * The actor is an identifier and stays one. Core records who acted by id and never
 * imports the module that knows what a user is, so there is no name to resolve here
 * without this client inventing a join the platform deliberately does not have — and
 * the id is what survives the account being deleted, which is exactly when the record
 * matters most.
 */
export function AuditDetail({ record, onClose, onFilter }: AuditDetailProps) {
    const { t } = useTranslation();
    const { locale } = useDirection();

    const failed = record.outcome === 'failed';

    return (
        <div className="flex flex-col border border-(--border-default) bg-(--surface-default)">
            <header className="flex items-start justify-between gap-2 border-b border-(--border-default) px-3 py-2.5">
                <div className="min-w-0">
                    <p data-eyebrow>{t('operations.audit.entry')}</p>
                    <h3 className="text-(length:--text-lg) text-(--text-primary)">
                        {record.action_label}
                    </h3>
                    <p
                        className="truncate text-(length:--text-sm) text-(--text-muted)"
                        data-technical
                    >
                        {record.action}
                    </p>
                </div>
                <Button
                    aria-label={t('operations.audit.close')}
                    onClick={onClose}
                    size="icon"
                    variant="ghost"
                >
                    <X aria-hidden className="size-4" />
                </Button>
            </header>

            <div className="flex flex-col gap-4 p-3">
                <div className="flex flex-wrap items-center gap-2">
                    <StatusBadge tone={failed ? 'danger' : 'success'}>
                        {failed
                            ? t('operations.audit.outcome.failed')
                            : t('operations.audit.outcome.succeeded')}
                    </StatusBadge>
                    <span
                        className="text-(length:--text-sm) text-(--text-muted)"
                        title={absoluteTime(record.created_at, locale) ?? undefined}
                    >
                        {relativeTime(record.created_at, locale)}
                    </span>
                </div>

                <dl className="grid grid-cols-[auto_1fr] gap-x-4 gap-y-1 text-(length:--text-sm)">
                    <dt className="text-(--text-muted)">{t('operations.audit.fields.subject')}</dt>
                    <dd className="min-w-0 text-(--text-primary)">
                        {record.subject === null ? (
                            <span className="text-(--text-muted)">
                                {t('operations.audit.fields.noSubject')}
                            </span>
                        ) : (
                            <button
                                className="break-all text-start underline-offset-2 hover:underline"
                                data-technical
                                onClick={() => onFilter({ subject: record.subject ?? '' })}
                                type="button"
                            >
                                {record.subject}
                            </button>
                        )}
                    </dd>

                    <dt className="text-(--text-muted)">{t('operations.audit.fields.actor')}</dt>
                    <dd className="min-w-0 text-(--text-primary)">
                        {record.actor_id === null ? (
                            <span className="text-(--text-muted)">
                                {t('operations.audit.fields.systemActor')}
                            </span>
                        ) : (
                            <button
                                className="break-all text-start underline-offset-2 hover:underline"
                                data-technical
                                onClick={() => onFilter({ actorId: record.actor_id ?? '' })}
                                type="button"
                            >
                                {record.actor_id}
                            </button>
                        )}
                    </dd>

                    <dt className="text-(--text-muted)">
                        {t('operations.audit.fields.correlation')}
                    </dt>
                    <dd className="min-w-0 text-(--text-primary)">
                        {record.correlation_id === null ? (
                            <span className="text-(--text-muted)">
                                {t('operations.audit.fields.noCorrelation')}
                            </span>
                        ) : (
                            <span className="break-all" data-technical>
                                {record.correlation_id}
                            </span>
                        )}
                    </dd>

                    <dt className="text-(--text-muted)">{t('operations.audit.fields.recorded')}</dt>
                    <dd className="min-w-0 text-(--text-primary)">
                        {absoluteTime(record.created_at, locale)}
                    </dd>
                </dl>

                <div className="flex flex-col gap-1">
                    <p data-eyebrow>{t('operations.audit.fields.context')}</p>
                    {record.context === null || Object.keys(record.context).length === 0 ? (
                        <p className="text-(length:--text-sm) text-(--text-muted)">
                            {t('operations.audit.fields.noContext')}
                        </p>
                    ) : (
                        <pre className="overflow-x-auto border border-(--border-default) bg-(--surface-subtle) p-2 text-(length:--text-xs) text-(--text-primary)">
                            <code data-technical>{JSON.stringify(record.context, null, 2)}</code>
                        </pre>
                    )}
                </div>

                <div>
                    <Button
                        onClick={() => onFilter({ action: record.action })}
                        size="sm"
                        variant="secondary"
                    >
                        {t('operations.audit.showAll', { action: record.action_label })}
                    </Button>
                </div>
            </div>
        </div>
    );
}
