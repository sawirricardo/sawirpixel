import type { GenerationStatus } from '@/types';

/**
 * The generation lifecycle, mirroring App\Enums\GenerationStatus (the source of truth):
 *
 *     pending ──▶ queued ──▶ processing ──▶ succeeded
 *        └──────────┴────────────┴───────▶ failed
 *
 * succeeded and failed are final. Everything else is unfinished: credits are held and the page keeps
 * polling until the image arrives.
 */
const FINAL: readonly GenerationStatus[] = ['succeeded', 'failed'];

export function isFinal(status: GenerationStatus): boolean {
    return FINAL.includes(status);
}

export function isUnfinished(status: GenerationStatus): boolean {
    return !isFinal(status);
}
