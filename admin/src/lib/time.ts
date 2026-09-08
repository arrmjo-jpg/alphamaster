const MINUTE = 60;
const HOUR = 60 * MINUTE;
const DAY = 24 * HOUR;

/**
 * "3 minutes ago", in the reader's language.
 *
 * `Intl.RelativeTimeFormat` is in every browser this application supports, so a
 * date library would be weight for something the platform already does — and does
 * with correct Arabic plurals, which a hand-rolled version would not.
 *
 * Returns null for an unparseable value rather than "Invalid Date": a missing
 * timestamp should read as missing, not as a broken one.
 */
export function relativeTime(iso: string | null | undefined, locale: string): string | null {
    if (iso === null || iso === undefined) {
        return null;
    }

    const then = Date.parse(iso);

    if (Number.isNaN(then)) {
        return null;
    }

    const seconds = Math.round((then - Date.now()) / 1000);
    const magnitude = Math.abs(seconds);
    const format = new Intl.RelativeTimeFormat(locale, { numeric: 'auto' });

    if (magnitude < MINUTE) {
        return format.format(Math.round(seconds), 'second');
    }

    if (magnitude < HOUR) {
        return format.format(Math.round(seconds / MINUTE), 'minute');
    }

    if (magnitude < DAY) {
        return format.format(Math.round(seconds / HOUR), 'hour');
    }

    return format.format(Math.round(seconds / DAY), 'day');
}

/** An absolute timestamp, for the title attribute behind a relative one. */
export function absoluteTime(iso: string | null | undefined, locale: string): string | null {
    if (iso === null || iso === undefined) {
        return null;
    }

    const value = Date.parse(iso);

    if (Number.isNaN(value)) {
        return null;
    }

    return new Intl.DateTimeFormat(locale, { dateStyle: 'medium', timeStyle: 'medium' }).format(
        value,
    );
}
