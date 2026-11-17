import type { PillOption } from '@/components/PillMenu.vue';

/** Restrictions set by the organization: the qualities and sizes this user may pick. */
export type GenerationOptions =
    | { qualities: string[]; sizes: string[] }
    | null
    | undefined;

/**
 * Mark the options the organization has turned off for this user, keeping them visible so it's clear
 * they exist.
 */
export function restrictOptions(
    options: PillOption<string>[],
    allowed: string[] | undefined,
): PillOption<string>[] {
    if (!allowed) {
        return options;
    }

    return options.map((option) =>
        allowed.includes(option.value)
            ? option
            : {
                  ...option,
                  disabled: true,
                  hint: 'Turned off by your organization',
              },
    );
}

/**
 * The value itself if it's allowed, otherwise the first allowed one (e.g. a restored draft or a version
 * made before a restriction).
 */
export function allowedValue(
    value: string,
    allowed: string[] | undefined,
): string {
    return !allowed || allowed.length === 0 || allowed.includes(value)
        ? value
        : allowed[0];
}
