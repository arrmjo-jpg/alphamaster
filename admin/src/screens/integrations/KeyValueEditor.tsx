import { Plus, Trash2 } from 'lucide-react';
import { useTranslation } from 'react-i18next';

import { Button } from '@/ui/Button';
import { Input } from '@/ui/Input';

import { newPair, type Pair } from './pairs';

export interface KeyValueEditorProps {
    /** Names the group for assistive technology. */
    label: string;
    pairs: Pair[];
    onChange: (pairs: Pair[]) => void;
    /** Masks values and stops the browser offering to remember them. */
    secret?: boolean;
    namePlaceholder: string;
    valuePlaceholder: string;
    addLabel: string;
    emptyMessage: string;
}

/**
 * A keyed map, edited as rows.
 *
 * The name is typed rather than chosen from a list, because there is no list: the API
 * constrains what a value may be and says nothing about which keys a driver expects,
 * and no driver publishes that. A select here would be this console inventing a
 * catalogue the platform does not have.
 *
 * A blank name means the row is not sent. That is deliberate and is why there is no
 * validation message for it — an empty row is how someone starts typing, not a mistake
 * they have made yet.
 */
export function KeyValueEditor({
    label,
    pairs,
    onChange,
    secret = false,
    namePlaceholder,
    valuePlaceholder,
    addLabel,
    emptyMessage,
}: KeyValueEditorProps) {
    const { t } = useTranslation();

    const replace = (uid: string, patch: Partial<Pair>) =>
        onChange(pairs.map((pair) => (pair.uid === uid ? { ...pair, ...patch } : pair)));

    return (
        <fieldset className="flex flex-col gap-2">
            <legend className="sr-only">{label}</legend>

            {pairs.length === 0 ? (
                <p className="text-(length:--text-sm) text-(--text-muted)">{emptyMessage}</p>
            ) : null}

            {pairs.map((pair) => (
                <div className="flex items-center gap-2" key={pair.uid}>
                    <Input
                        aria-label={namePlaceholder}
                        className="w-2/5 min-w-0"
                        data-technical
                        onChange={(event) => replace(pair.uid, { name: event.target.value })}
                        placeholder={namePlaceholder}
                        value={pair.name}
                    />
                    <Input
                        aria-label={valuePlaceholder}
                        // Never remembered and never suggested: a stored credential
                        // offered back by the browser on another screen is the one way a
                        // value this console cannot read could still leak from it.
                        autoComplete={secret ? 'new-password' : 'off'}
                        className="min-w-0 flex-1"
                        onChange={(event) => replace(pair.uid, { value: event.target.value })}
                        placeholder={valuePlaceholder}
                        type={secret ? 'password' : 'text'}
                        value={pair.value}
                    />
                    <Button
                        aria-label={t('integrations.credentials.removeRow', { name: pair.name })}
                        onClick={() => onChange(pairs.filter((row) => row.uid !== pair.uid))}
                        size="icon"
                        type="button"
                        variant="ghost"
                    >
                        <Trash2 aria-hidden className="size-3.5" />
                    </Button>
                </div>
            ))}

            <div>
                <Button
                    onClick={() => onChange([...pairs, newPair()])}
                    size="sm"
                    type="button"
                    variant="ghost"
                >
                    <Plus aria-hidden className="size-3.5" />
                    {addLabel}
                </Button>
            </div>
        </fieldset>
    );
}
