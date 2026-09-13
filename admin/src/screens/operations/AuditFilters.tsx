import { X } from 'lucide-react';
import { useTranslation } from 'react-i18next';

import { Button } from '@/ui/Button';
import { Input } from '@/ui/Input';

import { EMPTY_QUERY, type AuditQuery } from './query';

export interface AuditFiltersProps {
    value: AuditQuery;
    onChange: (value: AuditQuery) => void;
    /** Every action the current page contains, for the suggestion list. */
    knownActions: readonly { value: string; label: string }[];
}

/**
 * The trail's own filters, and nothing beyond them.
 *
 * Each control below is an equality or a bound the endpoint actually accepts. There is
 * no free-text box, because there is no free-text parameter — the trail is indexed on
 * these columns and a search across `context` would be a scan over the one column whose
 * contents vary per action.
 *
 * The action field is a text input with suggestions rather than a select, and that
 * distinction is deliberate. The suggestions are only the actions on the page in front
 * of the operator; the platform publishes no catalogue of every action it can record,
 * so a select would either be this console's own copy of one — wrong the first time an
 * action was added — or a list that silently hid the action somebody was looking for.
 */
export function AuditFilters({ value, onChange, knownActions }: AuditFiltersProps) {
    const { t } = useTranslation();

    const set = (patch: Partial<AuditQuery>) => onChange({ ...value, ...patch });
    const active = Object.values(value).some((entry) => entry !== '');

    return (
        <div className="flex flex-col gap-2 border border-(--border-default) bg-(--surface-default) px-3 py-2.5">
            <div className="flex flex-wrap items-end gap-3">
                <label className="flex min-w-44 flex-1 flex-col gap-1">
                    <span className="text-(length:--text-sm) font-medium text-(--text-secondary)">
                        {t('operations.audit.filters.action')}
                    </span>
                    <Input
                        data-technical
                        list="audit-actions"
                        onChange={(event) => set({ action: event.target.value })}
                        placeholder={t('operations.audit.filters.actionPlaceholder')}
                        value={value.action}
                    />
                    <datalist id="audit-actions">
                        {knownActions.map((action) => (
                            <option key={action.value} value={action.value}>
                                {action.label}
                            </option>
                        ))}
                    </datalist>
                </label>

                <label className="flex min-w-44 flex-1 flex-col gap-1">
                    <span className="text-(length:--text-sm) font-medium text-(--text-secondary)">
                        {t('operations.audit.filters.subject')}
                    </span>
                    <Input
                        data-technical
                        onChange={(event) => set({ subject: event.target.value })}
                        placeholder={t('operations.audit.filters.subjectPlaceholder')}
                        value={value.subject}
                    />
                </label>

                <label className="flex min-w-44 flex-1 flex-col gap-1">
                    <span className="text-(length:--text-sm) font-medium text-(--text-secondary)">
                        {t('operations.audit.filters.actor')}
                    </span>
                    <Input
                        data-technical
                        onChange={(event) => set({ actorId: event.target.value })}
                        placeholder={t('operations.audit.filters.actorPlaceholder')}
                        value={value.actorId}
                    />
                </label>

                <label className="flex flex-col gap-1">
                    <span className="text-(length:--text-sm) font-medium text-(--text-secondary)">
                        {t('operations.audit.filters.outcome')}
                    </span>
                    <select
                        className="h-(--field-height) border border-(--border-strong) bg-(--surface-default) px-2 text-(length:--text-base) text-(--text-primary) focus-visible:outline-2 focus-visible:outline-offset-0 focus-visible:outline-(--focus-ring)"
                        onChange={(event) => set({ outcome: event.target.value })}
                        value={value.outcome}
                    >
                        <option value="">{t('operations.audit.filters.anyOutcome')}</option>
                        {/* The two the platform records. `AuditRecord` defines them as
                            constants and nothing else writes the column. */}
                        <option value="succeeded">{t('operations.audit.outcome.succeeded')}</option>
                        <option value="failed">{t('operations.audit.outcome.failed')}</option>
                    </select>
                </label>

                <label className="flex flex-col gap-1">
                    <span className="text-(length:--text-sm) font-medium text-(--text-secondary)">
                        {t('operations.audit.filters.from')}
                    </span>
                    <Input
                        onChange={(event) => set({ from: event.target.value })}
                        type="datetime-local"
                        value={value.from}
                    />
                </label>

                <label className="flex flex-col gap-1">
                    <span className="text-(length:--text-sm) font-medium text-(--text-secondary)">
                        {t('operations.audit.filters.to')}
                    </span>
                    <Input
                        onChange={(event) => set({ to: event.target.value })}
                        type="datetime-local"
                        value={value.to}
                    />
                </label>

                {active ? (
                    <Button onClick={() => onChange(EMPTY_QUERY)} variant="ghost">
                        <X aria-hidden className="size-3.5" />
                        {t('operations.audit.filters.clear')}
                    </Button>
                ) : null}
            </div>

            <p className="text-(length:--text-sm) text-(--text-muted)">
                {t('operations.audit.filters.note')}
            </p>
        </div>
    );
}
