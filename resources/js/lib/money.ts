import type { Money, Range } from '@/types';

export function formatMoneyRange(range: Range<Money>): string {
    return range.min.amount === range.max.amount
        ? range.min.formatted
        : `${range.min.formatted} – ${range.max.formatted}`;
}
