import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { useState } from 'react';
import { describe, expect, it } from 'vitest';

import { formatDuration, parseDuration } from '@/lib/duration';

import type { SettingDefinition } from './api';
import { SettingControl } from './SettingControl';
import { validate } from './validation';

import '@/i18n';

/**
 * Durations stored as seconds and edited as `HH:MM:SS` (ADR 0054): the media analysis
 * limits, and any other setting that declares its unit as seconds.
 */

function durationDefinition(overrides: Partial<SettingDefinition> = {}): SettingDefinition {
    return {
        key: 'media_analysis.max_video_duration_seconds',
        group: 'media_analysis',
        name: 'max_video_duration_seconds',
        label: 'Longest video to analyse',
        help: null,
        type: 'integer',
        type_label: 'Integer',
        nullable: true,
        editable: true,
        is_secret: false,
        is_public: false,
        is_localized: false,
        reach: 'platform',
        reach_notice: null,
        default: null,
        depends_on: [],
        rules: ['nullable', 'integer', 'between:0,86400'],
        unit: 'seconds',
        permission: null,
        deprecated: false,
        ...overrides,
    };
}

function Harness({ initial }: { initial: number | null }) {
    const [value, setValue] = useState<unknown>(initial);

    return (
        <>
            <label htmlFor="duration">Longest video to analyse</label>
            <SettingControl
                aria-describedby={undefined}
                definition={durationDefinition()}
                disabled={false}
                id="duration"
                invalid={false}
                onChange={setValue}
                value={value}
            />
            <output data-testid="staged">{JSON.stringify(value)}</output>
        </>
    );
}

describe('durations', () => {
    it('formats seconds as HH:MM:SS, without wrapping at a day', () => {
        expect(formatDuration(0)).toBe('00:00:00');
        expect(formatDuration(180)).toBe('00:03:00');
        expect(formatDuration(300)).toBe('00:05:00');
        expect(formatDuration(3661)).toBe('01:01:01');
        expect(formatDuration(108_000)).toBe('30:00:00');
    });

    it('reads HH:MM:SS, MM:SS and plain seconds, and refuses what is not a duration', () => {
        expect(parseDuration('00:03:00')).toBe(180);
        expect(parseDuration('5:00')).toBe(300);
        expect(parseDuration('90')).toBe(90);
        expect(parseDuration(' 01:00:01 ')).toBe(3601);

        expect(parseDuration('2:75')).toBeNull();
        expect(parseDuration('1:2:3:4')).toBeNull();
        expect(parseDuration('five minutes')).toBeNull();
        expect(parseDuration('-30')).toBeNull();
        expect(parseDuration('')).toBeNull();
    });

    it('checks the declared range, and says it in durations', () => {
        expect(validate(durationDefinition(), 300)).toBeNull();
        expect(validate(durationDefinition(), 0)).toBeNull();
        expect(validate(durationDefinition(), null)).toBeNull();
        expect(validate(durationDefinition(), 90_000)).toEqual({
            key: 'settings.validation.between',
            values: { min: '00:00:00', max: '24:00:00' },
        });
    });

    it('shows a stored limit as a duration and stages seconds when one is typed', async () => {
        render(<Harness initial={300} />);

        const field = screen.getByLabelText('Longest video to analyse');
        expect(field).toHaveValue('00:05:00');

        await userEvent.clear(field);
        await userEvent.type(field, '00:03:00');

        expect(screen.getByTestId('staged')).toHaveTextContent('180');
        expect(screen.getByText('180 seconds')).toBeInTheDocument();
    });

    it('holds text that is not a duration yet, and stages nothing for it', async () => {
        render(<Harness initial={300} />);

        const field = screen.getByLabelText('Longest video to analyse');
        await userEvent.clear(field);
        await userEvent.type(field, '5:7x');

        expect(field).toHaveValue('5:7x');
        expect(screen.getByRole('alert')).toHaveTextContent('not a duration');
        // Clearing staged null on the way; the malformed text staged nothing after it.
        expect(screen.getByTestId('staged')).not.toHaveTextContent('300');
    });

    it('stages null, not zero, when the limit is emptied', async () => {
        render(<Harness initial={300} />);

        await userEvent.clear(screen.getByLabelText('Longest video to analyse'));

        expect(screen.getByTestId('staged')).toHaveTextContent('null');
    });
});
