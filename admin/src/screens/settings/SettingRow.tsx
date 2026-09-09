import { AlertTriangle, Ban, Lock, Link2Off } from 'lucide-react';
import { useId } from 'react';
import { useTranslation } from 'react-i18next';

import { cn } from '@/lib/cn';
import { StatusBadge } from '@/ui/StatusBadge';
import { TONE_CLASSES } from '@/ui/state';

import type { FieldState } from './draft';
import { settingKey } from './draft';
import { MediaField } from './MediaField';
import { SecretField } from './SecretField';
import { SettingControl } from './SettingControl';
import { STATUS_LABEL, STATUS_TONE } from './state';

export interface SettingRowProps {
    field: FieldState;
    group: string;
    version: string;
    onChange: (key: string, value: unknown) => void;
    onRotated: () => void;
}

/**
 * One setting, with everything the platform says about it.
 *
 * The rail on the inline-start edge is the state, and it is the thing an operator
 * scans a long group with — which is why it is a rail and not a badge, and why the
 * word sits beside it rather than the colour carrying the meaning alone.
 *
 * Notes below the control are ordered by what stops you first: a value you may not
 * change, then a dependency that is not configured, then a setting that has been
 * withdrawn. Each says why rather than disabling the control silently — a field that
 * is simply dead reads as a broken interface, and the operator has no way to tell
 * that from a deliberate refusal.
 */
export function SettingRow({ field, group, version, onChange, onRotated }: SettingRowProps) {
    const { t } = useTranslation();
    const id = useId();
    const { definition } = field;
    const key = settingKey(definition);

    const tone = STATUS_TONE[field.status];
    const statusLabel = STATUS_LABEL[field.status];
    const describedBy: string[] = [];

    if (definition.help !== null && definition.help !== '') {
        describedBy.push(`${id}-help`);
    }

    if (field.invalid !== null || field.rejected !== null) {
        describedBy.push(`${id}-error`);
    }

    return (
        <div
            className={cn(
                'relative border-b border-(--border-default) py-3 ps-4 pe-1 last:border-b-0',
                field.changed ? 'bg-(--state-pending-tint)/30' : null,
            )}
        >
            <span
                aria-hidden
                className={cn(
                    'absolute inset-y-0 start-0 w-(--rail-width)',
                    field.status === 'unchanged' ? 'bg-transparent' : TONE_CLASSES[tone].rail,
                )}
            />

            <div className="flex flex-col gap-2">
                <div className="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-1">
                    <div className="min-w-0">
                        <label
                            className="text-(length:--text-base) font-medium text-(--text-primary)"
                            htmlFor={id}
                        >
                            {definition.label}
                        </label>
                        <p className="text-(length:--text-xs) text-(--text-muted)" data-technical>
                            {key}
                        </p>
                    </div>

                    <div className="flex shrink-0 flex-wrap items-center gap-1.5">
                        {field.deprecated ? (
                            <StatusBadge icon={<Ban className="size-3" />} tone="warning">
                                {t('settings.deprecated')}
                            </StatusBadge>
                        ) : null}

                        {statusLabel !== null ? (
                            <StatusBadge tone={tone}>{t(statusLabel)}</StatusBadge>
                        ) : null}

                        <span className="text-(length:--text-2xs) text-(--text-muted)">
                            {definition.type_label}
                        </span>
                    </div>
                </div>

                {definition.is_secret ? (
                    <SecretField
                        canRotate={field.editable}
                        configured={field.saved !== null && field.saved !== undefined}
                        group={group}
                        onRotated={onRotated}
                        settingKey={key}
                        version={version}
                    />
                ) : definition.type === 'media' ? (
                    <MediaField
                        disabled={!field.editable}
                        id={id}
                        onChange={(mediaId) => onChange(key, mediaId)}
                        value={field.value}
                    />
                ) : (
                    <SettingControl
                        aria-describedby={
                            describedBy.length === 0 ? undefined : describedBy.join(' ')
                        }
                        definition={definition}
                        disabled={!field.editable}
                        id={id}
                        invalid={field.invalid !== null || field.rejected !== null}
                        onChange={(next) => onChange(key, next)}
                        value={field.value}
                    />
                )}

                {definition.help !== null && definition.help !== '' ? (
                    <p className="text-(length:--text-sm) text-(--text-muted)" id={`${id}-help`}>
                        {definition.help}
                    </p>
                ) : null}

                {field.readOnlyReason !== null ? (
                    <Note icon={<Lock className="size-3.5" />}>
                        {field.readOnlyReason === 'permission'
                            ? t('settings.readOnly.permission', {
                                  permission: definition.permission ?? '',
                              })
                            : t('settings.readOnly.declared')}
                    </Note>
                ) : null}

                {field.unmet.length > 0 ? (
                    <Note icon={<Link2Off className="size-3.5" />}>
                        {t('settings.unavailableUntil', { keys: field.unmet.join('، ') })}
                    </Note>
                ) : null}

                {field.deprecated ? (
                    <Note icon={<AlertTriangle className="size-3.5" />}>
                        {t('settings.deprecatedNote')}
                    </Note>
                ) : null}

                {field.invalid !== null || field.rejected !== null ? (
                    <p
                        className="text-(length:--text-sm) text-(--text-danger)"
                        id={`${id}-error`}
                        role="alert"
                    >
                        {field.rejected ??
                            (field.invalid === null
                                ? null
                                : t(field.invalid.key, field.invalid.values ?? {}))}
                    </p>
                ) : null}
            </div>
        </div>
    );
}

function Note({ icon, children }: { icon: React.ReactNode; children: React.ReactNode }) {
    return (
        <p className="flex items-start gap-1.5 text-(length:--text-sm) text-(--text-secondary)">
            <span aria-hidden className="mt-0.5 shrink-0 text-(--text-muted)">
                {icon}
            </span>
            <span>{children}</span>
        </p>
    );
}
