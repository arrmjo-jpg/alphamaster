import { KeyRound } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';

import { cn } from '@/lib/cn';
import { Field } from '@/ui/Field';
import { Input } from '@/ui/Input';
import { SegmentedControl } from '@/ui/SegmentedControl';
import { StateRail } from '@/ui/StateRail';
import { StatusBadge } from '@/ui/StatusBadge';

import { settingKey, type FieldState } from './draft';

export interface SettingFieldProps {
    field: FieldState;
    /** The message the platform returned for this key, if it refused it. */
    error?: string;
    onChange: (key: string, value: unknown) => void;
}

/**
 * One setting, rendered the way its type and its risk deserve.
 *
 * A boolean is two buttons and a number is a number field, because a trivial setting
 * should stay trivial — complexity belongs where a change is risky, not spread evenly
 * across every row. What is never trivial is *whether it changed*: an edited row
 * carries the `pending` rail, which is the one state this product added for exactly
 * this, so an operator can scan a long group and see what they are about to write.
 */
export function SettingField({ field, error, onChange }: SettingFieldProps) {
    const { t } = useTranslation();
    const { definition } = field;

    if (definition.is_secret) {
        return <SecretField field={field} />;
    }

    const hint = [
        definition.help,
        field.unmet.length > 0
            ? t('settings.unmetDependencies', { keys: field.unmet.join(', ') })
            : null,
        !field.editable ? t('settings.notEditable') : null,
    ]
        .filter((line): line is string => line !== null && line !== '')
        .join(' · ');

    return (
        <StateRail tone={field.changed ? 'pending' : 'neutral'}>
            <Field
                {...(hint !== '' ? { hint } : {})}
                {...(error !== undefined ? { error } : {})}
                label={definition.label}
            >
                {({ id, invalid, ...described }) => (
                    <Control
                        definition={definition}
                        disabled={!field.editable}
                        id={id}
                        invalid={invalid}
                        onChange={(value) => onChange(settingKey(definition), value)}
                        value={field.value}
                        {...described}
                    />
                )}
            </Field>
        </StateRail>
    );
}

interface ControlProps {
    definition: FieldState['definition'];
    value: unknown;
    disabled: boolean;
    id: string;
    invalid: boolean;
    'aria-describedby': string | undefined;
    onChange: (value: unknown) => void;
}

function Control({
    definition,
    value,
    disabled,
    id,
    invalid,
    onChange,
    ...described
}: ControlProps) {
    const { t } = useTranslation();

    if (definition.type === 'boolean') {
        return (
            <SegmentedControl<'on' | 'off'>
                label={definition.label}
                onChange={(next) => onChange(next === 'on')}
                options={[
                    { value: 'on', label: t('settings.on') },
                    { value: 'off', label: t('settings.off') },
                ]}
                value={value === true ? 'on' : 'off'}
            />
        );
    }

    if (definition.type === 'integer' || definition.type === 'float') {
        return (
            <Input
                disabled={disabled}
                id={id}
                invalid={invalid}
                // An empty field is null, not zero. A nullable setting has to be
                // clearable, and coercing blank to 0 would quietly write a value the
                // operator did not choose.
                onChange={(event) =>
                    onChange(event.target.value === '' ? null : Number(event.target.value))
                }
                type="number"
                value={typeof value === 'number' ? value : ''}
                {...described}
            />
        );
    }

    if (definition.type === 'json') {
        return (
            <JsonControl
                disabled={disabled}
                id={id}
                invalid={invalid}
                onChange={onChange}
                value={value}
                {...described}
            />
        );
    }

    return (
        <Input
            disabled={disabled}
            id={id}
            invalid={invalid}
            onChange={(event) => onChange(event.target.value)}
            type={
                definition.type === 'email' ? 'email' : definition.type === 'url' ? 'url' : 'text'
            }
            value={typeof value === 'string' ? value : ''}
            {...described}
        />
    );
}

/**
 * A structured value, edited as the JSON it is stored as.
 *
 * Text that does not parse is held as typed rather than discarded — an editor that
 * threw away a half-finished object on every keystroke would be unusable — and the
 * unparseable state is reported rather than submitted, because the platform would
 * refuse it with a message about a type rather than about the bracket that is
 * missing.
 */
function JsonControl({
    value,
    disabled,
    id,
    invalid,
    onChange,
    ...described
}: Omit<ControlProps, 'definition'>) {
    const { t } = useTranslation();
    const [text, setText] = useState(() => stringify(value));
    const [malformed, setMalformed] = useState(false);

    return (
        <div className="flex flex-col gap-1">
            <textarea
                className={cn(
                    'min-h-24 w-full border bg-(--surface-default) p-2',
                    'font-(family-name:--font-mono) text-(length:--text-sm) text-(--text-primary)',
                    'focus-visible:outline-2 focus-visible:outline-offset-0 focus-visible:outline-(--focus-ring)',
                    'disabled:cursor-not-allowed disabled:opacity-50',
                    invalid || malformed
                        ? 'border-(--state-danger-rail)'
                        : 'border-(--border-strong)',
                )}
                disabled={disabled}
                id={id}
                onChange={(event) => {
                    const next = event.target.value;
                    setText(next);

                    try {
                        onChange(JSON.parse(next));
                        setMalformed(false);
                    } catch {
                        setMalformed(true);
                    }
                }}
                value={text}
                {...described}
            />
            {malformed ? (
                <p className="text-(length:--text-sm) text-(--text-danger)" role="alert">
                    {t('settings.malformedJson')}
                </p>
            ) : null}
        </div>
    );
}

function stringify(value: unknown): string {
    if (value === null || value === undefined) {
        return '';
    }

    return typeof value === 'string' ? value : JSON.stringify(value, null, 2);
}

/**
 * A secret is shown as present or absent, and never as a value.
 *
 * The platform does not return it — it cannot be edited in place, and rotation is a
 * separate operation on its own permission (ADR 0018, ADR 0039). Rendering an empty
 * text field here would invite an operator to type into something that would then
 * write an empty credential.
 */
function SecretField({ field }: { field: FieldState }) {
    const { t } = useTranslation();
    const present = field.row?.value !== null && field.row?.value !== undefined;

    return (
        <StateRail tone="neutral">
            <div className="flex flex-col gap-1">
                <span className="text-(length:--text-sm) font-medium text-(--text-secondary)">
                    {field.definition.label}
                </span>
                <div className="flex items-center gap-2">
                    <KeyRound aria-hidden className="size-4 text-(--text-muted)" />
                    <StatusBadge tone={present ? 'success' : 'warning'}>
                        {present ? t('settings.secretSet') : t('settings.secretUnset')}
                    </StatusBadge>
                </div>
                <p className="text-(length:--text-sm) text-(--text-muted)">
                    {t('settings.secretExplanation')}
                </p>
            </div>
        </StateRail>
    );
}
