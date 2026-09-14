/**
 * A length of time, as an operator reads and types one.
 *
 * The platform stores durations as whole seconds, and that is what is sent back. What is
 * shown is `HH:MM:SS`, because "300" in a field labelled with a video's length makes the
 * operator do arithmetic the screen could have done.
 */

/** `HH:MM:SS`. Hours are not wrapped at a day: thirty hours reads `30:00:00`. */
export function formatDuration(totalSeconds: number): string {
    const whole = Math.max(0, Math.trunc(totalSeconds));
    const hours = Math.trunc(whole / 3600);
    const minutes = Math.trunc((whole % 3600) / 60);
    const seconds = whole % 60;

    return [hours, minutes, seconds].map((part) => String(part).padStart(2, '0')).join(':');
}

/**
 * Seconds from what an operator typed, or null when it is not a duration.
 *
 * Accepts `HH:MM:SS`, `MM:SS` and plain seconds. Minutes and seconds after a colon must be
 * below sixty — `2:75` is a typing mistake, not two minutes and seventy-five seconds, and
 * guessing which was meant would write a value nobody chose.
 */
export function parseDuration(text: string): number | null {
    const trimmed = text.trim();

    if (!/^\d+(:\d{1,2}){0,2}$/.test(trimmed)) {
        return null;
    }

    const parts = trimmed.split(':').map(Number);
    const [first = 0, ...rest] = parts;

    if (rest.some((part) => part >= 60)) {
        return null;
    }

    return parts.length === 3
        ? first * 3600 + (rest[0] ?? 0) * 60 + (rest[1] ?? 0)
        : parts.length === 2
          ? first * 60 + (rest[0] ?? 0)
          : first;
}
