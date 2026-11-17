/**
 * "3 days ago", "in 2 hours", "just now": a date relative to now, in the reader's language.
 */
export function relativeTime(
    date: string | Date,
    now: number = Date.now(),
): string {
    const seconds = (new Date(date).getTime() - now) / 1000;

    if (Math.abs(seconds) < 60) {
        return 'just now';
    }

    const units: [number, Intl.RelativeTimeFormatUnit][] = [
        [31_536_000, 'year'],
        [2_592_000, 'month'],
        [86_400, 'day'],
        [3_600, 'hour'],
        [60, 'minute'],
    ];
    const [size, unit] =
        units.find(([size]) => Math.abs(seconds) >= size) ?? units[4];

    return new Intl.RelativeTimeFormat(undefined, { numeric: 'auto' }).format(
        Math.round(seconds / size),
        unit,
    );
}
