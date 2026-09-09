import type { StateTone } from '@/ui/state';

/**
 * What a setting is doing right now, as one word.
 *
 * These are the states an operator has to tell apart while editing a group, and they
 * are not degrees of the same thing: `modified` is their own unsaved work, `conflict`
 * is somebody else's saved work, and `failed` is the platform refusing. Collapsing
 * any two of them loses the distinction that decides what to do next.
 *
 * Each carries a tone, an icon and a word. Never colour alone — a rail an operator
 * cannot distinguish is a rail that says nothing, and half the states here mean
 * "stop".
 */
export type SettingStatus =
    /** Matches what the platform holds. */
    | 'unchanged'
    /** Edited here and not yet written. */
    | 'modified'
    /** Being written now. */
    | 'pending'
    /** Someone else wrote first; this value is built on a version that has moved. */
    | 'conflict'
    /** The platform refused this value and said why. */
    | 'failed'
    /** A secret that is set but has never been proved to work. */
    | 'unverified';

export const STATUS_TONE: Record<SettingStatus, StateTone> = {
    unchanged: 'neutral',
    modified: 'pending',
    pending: 'info',
    conflict: 'warning',
    failed: 'danger',
    unverified: 'warning',
};

/**
 * The label key for a status, so the word is translated rather than a colour.
 *
 * `unchanged` has none: a row that is doing nothing should say nothing, and marking
 * every untouched setting "unchanged" would bury the four that are not.
 */
export const STATUS_LABEL: Record<SettingStatus, string | null> = {
    unchanged: null,
    modified: 'settings.status.modified',
    pending: 'settings.status.pending',
    conflict: 'settings.status.conflict',
    failed: 'settings.status.failed',
    unverified: 'settings.status.unverified',
};
