<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import { useCreditsDialog } from '@/lib/creditsDialog';
import type { Money, Paginated } from '@/types';

type Entry = {
    id: number;
    type: 'purchase' | 'hold' | 'hold_release' | 'charge';
    label: string;
    member: string | null;
    generation: { id: number; prompt: string; is_yours: boolean } | null;
    amount: Money;
    balance_after: Money;
    created_at: string | null;
};

defineProps<{
    filters: { type: 'purchase' | 'charge' | null };
    summary: {
        balance: Money;
        reserved: Money;
        purchased: Money;
        charged: Money;
    };
    entries: Paginated<Entry>;
    canBuy: boolean;
}>();

const creditsDialog = useCreditsDialog();

const tabs = [
    { value: null, label: 'All' },
    { value: 'purchase', label: 'Purchases' },
    { value: 'charge', label: 'Charges' },
] as const;

function filter(type: 'purchase' | 'charge' | null): void {
    router.get('/credits', type ? { type } : {}, {
        preserveScroll: true,
        preserveState: true,
    });
}

function isCredit(entry: Entry): boolean {
    return !entry.amount.amount.startsWith('-');
}

function formatDate(date: string | null): string {
    return date ? new Date(date).toLocaleString() : '—';
}

const typeStyles: Record<Entry['type'], string> = {
    purchase: 'bg-emerald-100 text-emerald-800',
    hold: 'bg-amber-100 text-amber-800',
    hold_release: 'bg-sky-100 text-sky-800',
    charge: 'bg-zinc-200 text-zinc-800',
};
</script>

<template>
    <AppLayout>
        <Head title="Credits" />
        <div class="flex flex-wrap items-end justify-between gap-4">
            <div>
                <h1 class="text-2xl font-semibold">Credits</h1>
                <p class="mt-1 text-sm text-zinc-600">
                    Every purchase and every image's reserve, return and charge.
                </p>
            </div>
            <button
                v-if="canBuy"
                type="button"
                class="rounded-full bg-zinc-900 px-4 py-2 text-sm font-medium text-white hover:bg-zinc-700"
                @click="creditsDialog.show()"
            >
                Buy credits
            </button>
        </div>

        <dl class="mt-6 grid gap-4 sm:grid-cols-4">
            <div class="rounded-xl border border-zinc-200 bg-white p-4">
                <dt class="text-xs text-zinc-500">Balance</dt>
                <dd
                    class="mt-1 text-2xl font-semibold tabular-nums"
                    :title="`$${summary.balance.amount}`"
                >
                    {{ summary.balance.formatted }}
                </dd>
            </div>
            <div class="rounded-xl border border-zinc-200 bg-white p-4">
                <dt class="text-xs text-zinc-500">
                    Set aside for images in progress
                </dt>
                <dd
                    class="mt-1 text-2xl font-semibold tabular-nums"
                    :title="`$${summary.reserved.amount}`"
                >
                    {{ summary.reserved.formatted }}
                </dd>
            </div>
            <div class="rounded-xl border border-zinc-200 bg-white p-4">
                <dt class="text-xs text-zinc-500">Purchased</dt>
                <dd class="mt-1 text-2xl font-semibold tabular-nums">
                    {{ summary.purchased.formatted }}
                </dd>
            </div>
            <div class="rounded-xl border border-zinc-200 bg-white p-4">
                <dt class="text-xs text-zinc-500">Spent on images</dt>
                <dd
                    class="mt-1 text-2xl font-semibold tabular-nums"
                    :title="`$${summary.charged.amount}`"
                >
                    {{ summary.charged.formatted }}
                </dd>
            </div>
        </dl>

        <div
            class="mt-8 flex w-fit gap-1 rounded-full bg-zinc-100 p-1 text-sm"
            role="tablist"
            aria-label="Filter entries"
        >
            <button
                v-for="tab in tabs"
                :key="tab.label"
                type="button"
                role="tab"
                :aria-selected="filters.type === tab.value"
                class="rounded-full px-4 py-1.5 font-medium"
                :class="
                    filters.type === tab.value
                        ? 'bg-white text-zinc-900 shadow-sm'
                        : 'text-zinc-500 hover:text-zinc-800'
                "
                @click="filter(tab.value)"
            >
                {{ tab.label }}
            </button>
        </div>

        <div
            class="mt-4 overflow-x-auto rounded-xl border border-zinc-200 bg-white"
        >
            <table class="w-full text-sm">
                <thead class="bg-zinc-50 text-left text-xs text-zinc-500">
                    <tr>
                        <th class="px-4 py-2 font-medium">When</th>
                        <th class="px-4 py-2 font-medium">What</th>
                        <th class="px-4 py-2 font-medium">Details</th>
                        <th class="px-4 py-2 text-right font-medium">Amount</th>
                        <th class="px-4 py-2 text-right font-medium">
                            Balance
                        </th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-100">
                    <tr v-if="entries.data.length === 0">
                        <td
                            colspan="5"
                            class="px-4 py-6 text-center text-zinc-500"
                        >
                            No credit activity yet.
                        </td>
                    </tr>
                    <tr v-for="entry in entries.data" :key="entry.id">
                        <td class="px-4 py-2 whitespace-nowrap text-zinc-600">
                            {{ formatDate(entry.created_at) }}
                        </td>
                        <td class="px-4 py-2">
                            <span
                                class="rounded-full px-2 py-0.5 text-xs font-medium"
                                :class="typeStyles[entry.type]"
                            >
                                {{ entry.label }}
                            </span>
                        </td>
                        <td class="max-w-80 px-4 py-2">
                            <template v-if="entry.generation">
                                <Link
                                    v-if="entry.generation.is_yours"
                                    :href="`/generations/${entry.generation.id}`"
                                    class="block truncate hover:underline"
                                    :title="entry.generation.prompt"
                                >
                                    {{ entry.generation.prompt }}
                                </Link>
                                <span
                                    v-else
                                    class="block truncate"
                                    :title="entry.generation.prompt"
                                >
                                    {{ entry.generation.prompt }}
                                </span>
                            </template>
                            <span
                                v-else-if="entry.type === 'purchase'"
                                class="text-zinc-600"
                                >Credits added</span
                            >
                            <span
                                v-if="entry.member"
                                class="block text-xs text-zinc-500"
                                >{{ entry.member }}</span
                            >
                        </td>
                        <td
                            class="px-4 py-2 text-right font-medium whitespace-nowrap tabular-nums"
                            :class="
                                isCredit(entry)
                                    ? 'text-emerald-700'
                                    : 'text-zinc-900'
                            "
                            :title="`$${entry.amount.amount}`"
                        >
                            {{ isCredit(entry) ? '+' : ''
                            }}{{ entry.amount.formatted }}
                        </td>
                        <td
                            class="px-4 py-2 text-right text-zinc-600 tabular-nums"
                            :title="`$${entry.balance_after.amount}`"
                        >
                            {{ entry.balance_after.formatted }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <nav
            v-if="entries.last_page > 1"
            class="mt-6 flex justify-between text-sm"
        >
            <Link
                v-if="entries.prev_page_url"
                :href="entries.prev_page_url"
                preserve-scroll
                >← Newer</Link
            >
            <span v-else />
            <span class="text-zinc-500"
                >Page {{ entries.current_page }} of
                {{ entries.last_page }}</span
            >
            <Link
                v-if="entries.next_page_url"
                :href="entries.next_page_url"
                preserve-scroll
                >Older →</Link
            >
            <span v-else />
        </nav>
    </AppLayout>
</template>
