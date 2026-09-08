import { useState } from 'react';
import { useTranslation } from 'react-i18next';

import { cn } from '@/lib/cn';
import { Input } from '@/ui/Input';
import { SegmentedControl } from '@/ui/SegmentedControl';

import type { SettingDefinition } from './api';
import { allowedValues } from './validation';

export interface SettingControlProps {
    definition: SettingDefinition;
    value: unknown;
    disabled: boolean;
    id: string;
    invalid: boolean;
    'aria-describedby': string | undefined;
    onChange: (value: unknown) => void;
}

/**
 * One control per setting type, and the type decides which.
 *
 * The platform declares eight, and each of them is a different question. A boolean is
 * two buttons; a bounded string is a choice, not free text; a number is a number
 * field that can still be emptied; JSON is text that has to stay editable while it is
 * half-written. Reducing all of them to a text input would be less code and would
 * make every one of those questions the same question.
 *
 * `media` is not here: an image is a file with an upload, a preview and a size limit,
 * which is a control of its own rather than a variant of this one.
 */
export function SettingControl({
    definition,
    value,
    disabled,
    id,
    invalid,
    onChange,
    ...described
}: SettingControlProps) {
    const { t } = useTranslation();
    const choices = allowedValues(definition);

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

    // A rule that names the permitted values makes this a choice. Offering free text
    // and then refusing three quarters of it is a worse interface than the list.
    if (choices !== null) {
        return (
            <select
                className={fieldClass(invalid)}
                disabled={disabled}
                id={id}
                onChange={(event) => onChange(event.target.value)}
                value={typeof value === 'string' ? value : ''}
                {...described}
            >
                {definition.nullable ? <option value="">{t('settings.notSet')}</option> : null}
                {choices.map((choice) => (
                    <option key={choice} value={choice}>
                        {choice}
                    </option>
                ))}
            </select>
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
                step={definition.type === 'float' ? 'any' : '1'}
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
            inputMode={definition.type === 'url' ? 'url' : undefined}
            invalid={invalid}
            onChange={(event) => onChange(event.target.value)}
            // The semantic type, so a phone keyboard offers the right layout and the
            // browser can autofill. `text` for everything would be one line shorter.
            type={
                definition.type === 'email' ? 'email' : definition.type === 'url' ? 'url' : 'text'
            }
            value={typeof value === 'string' ? value : ''}
            {...described}
        />
    );
}

function fieldClass(invalid: boolean): string {
    return cn(
        'h-(--field-height) w-full border bg-(--surface-default) px-2',
        'text-(length:--text-base) text-(--text-primary)',
        'focus-visible:outline-2 focus-visible:outline-offset-0 focus-visible:outline-(--focus-ring)',
        'disabled:cursor-not-allowed disabled:opacity-50',
        invalid ? 'border-(--state-danger-rail)' : 'border-(--border-strong)',
    );
}

/**
 * A structured value, edited as the JSON it is stored as.
 *
 * Text that does not parse is held as typed rather than discarded — an editor that
 * threw away a half-finished object on every keystroke would be unusable — and the
 * unparseable state is reported rather than staged, because the platform would refuse
 * it with a message about a type rather than about the bracket that is missing.
 */
function JsonControl({
    value,
    disabled,
    id,
    invalid,
    onChange,
    ...described
}: Omit<SettingControlProps, 'definition'>) {
    const { t } = useTranslation();
    const [text, setText] = useState(() => stringify(value));
    const [malformed, setMalformed] = useState(false);

    return (
        <div className="flex flex-col gap-1">
            <textarea
                className={cn(
                    'min-h-28 w-full border bg-(--surface-default) p-2',
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
                spellCheck={false}
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
