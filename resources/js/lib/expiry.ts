const relative = new Intl.RelativeTimeFormat(undefined, { numeric: 'auto' });

/**
 * "expires in 23 hours", "expires in 12 minutes" or "expired", for Pixelforge's signed image links.
 */
export function describeExpiry(
    expiresAt: string | null,
    now: number = Date.now(),
): string | null {
    if (!expiresAt) {
        return null;
    }

    const seconds = Math.round((new Date(expiresAt).getTime() - now) / 1000);

    if (seconds <= 0) {
        return 'Image link expired';
    }

    const [value, unit]: [number, Intl.RelativeTimeFormatUnit] =
        seconds >= 3600
            ? [Math.floor(seconds / 3600), 'hour']
            : seconds >= 60
              ? [Math.floor(seconds / 60), 'minute']
              : [seconds, 'second'];

    return `Image link expires ${relative.format(value, unit)}`;
}
