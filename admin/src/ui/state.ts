/**
 * The six meanings a status can have in this product.
 *
 * `pending` is AlphaMaster's own: staged but not yet written. Without it an edited,
 * unsaved row borrows `warning`, and warning then means two different things on the
 * same screen.
 */
export type StateTone = 'success' | 'warning' | 'danger' | 'info' | 'pending' | 'neutral';

/** Tint, rail and text for a tone, resolved through the semantic layer only. */
export const TONE_CLASSES: Record<StateTone, { tint: string; rail: string; text: string }> = {
    success: {
        tint: 'bg-(--state-success-tint)',
        rail: 'bg-(--state-success-rail)',
        text: 'text-(--state-success-text)',
    },
    warning: {
        tint: 'bg-(--state-warning-tint)',
        rail: 'bg-(--state-warning-rail)',
        text: 'text-(--state-warning-text)',
    },
    danger: {
        tint: 'bg-(--state-danger-tint)',
        rail: 'bg-(--state-danger-rail)',
        text: 'text-(--state-danger-text)',
    },
    info: {
        tint: 'bg-(--state-info-tint)',
        rail: 'bg-(--state-info-rail)',
        text: 'text-(--state-info-text)',
    },
    pending: {
        tint: 'bg-(--state-pending-tint)',
        rail: 'bg-(--state-pending-rail)',
        text: 'text-(--state-pending-text)',
    },
    neutral: {
        tint: 'bg-(--state-neutral-tint)',
        rail: 'bg-(--state-neutral-rail)',
        text: 'text-(--state-neutral-text)',
    },
};
