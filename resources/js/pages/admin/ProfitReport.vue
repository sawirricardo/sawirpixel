<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { computed, reactive } from 'vue';
import AppLayout from '@/layouts/AppLayout.vue';
import type { ProfitReport } from '@/types';

const props = defineProps<{
    filters: { start_date: string; end_date: string };
    report: ProfitReport;
    pixelforgeError: string | null;
}>();

const range = reactive({ ...props.filters });

function apply(): void {
    router.get('/admin/reports/profit', range, { preserveScroll: true });
}

const exportUrl = computed(
    () =>
        `/admin/reports/profit/generations.csv?${new URLSearchParams(props.filters).toString()}`,
);

const reconciliationSummary = computed(() => {
    const { available, matched_days, mismatched_days } =
        props.report.reconciliation;

    if (!available) {
        return null;
    }

    return mismatched_days === 0
        ? `All ${matched_days} days match Pixelforge's usage report.`
        : `${mismatched_days} of ${matched_days + mismatched_days} days differ from Pixelforge's usage report.`;
});
</script>

<template>
    <AppLayout>
        <Head title="Profit report" />
        <div class="flex flex-wrap items-end justify-between gap-4">
            <div>
                <h1 class="text-2xl font-semibold">Profit report</h1>
                <p class="mt-1 text-sm text-zinc-600">
                    Succeeded generations, by the UTC day they completed: the
                    same grouping as Pixelforge's usage report.
                </p>
            </div>
            <form class="flex items-end gap-2 text-sm" @submit.prevent="apply">
                <label>
                    <span class="block text-xs text-zinc-500">From</span>
                    <input
                        v-model="range.start_date"
                        type="date"
                        class="rounded-lg border border-zinc-300 px-2 py-1.5"
                    />
                </label>
                <label>
                    <span class="block text-xs text-zinc-500">To</span>
                    <input
                        v-model="range.end_date"
                        type="date"
                        class="rounded-lg border border-zinc-300 px-2 py-1.5"
                    />
                </label>
                <button
                    type="submit"
                    class="rounded-lg bg-zinc-900 px-3 py-1.5 font-medium text-white hover:bg-zinc-700"
                >
                    Apply
                </button>
                <a
                    :href="exportUrl"
                    class="rounded-lg border border-zinc-300 px-3 py-1.5 font-medium hover:bg-zinc-100"
                >
                    Export CSV
                </a>
            </form>
        </div>

        <p
            v-if="$page.props.errors.start_date || $page.props.errors.end_date"
            class="mt-4 text-sm text-red-600"
        >
            {{ $page.props.errors.start_date ?? $page.props.errors.end_date }}
        </p>

        <dl class="mt-6 grid gap-4 sm:grid-cols-4">
            <div class="rounded-xl border border-zinc-200 bg-white p-4">
                <dt class="text-xs text-zinc-500">Charged to users</dt>
                <dd
                    class="mt-1 text-2xl font-semibold tabular-nums"
                    :title="`$${report.totals.charged.amount}`"
                >
                    {{ report.totals.charged.formatted }}
                </dd>
            </div>
            <div class="rounded-xl border border-zinc-200 bg-white p-4">
                <dt class="text-xs text-zinc-500">Pixelforge cost (ours)</dt>
                <dd class="mt-1 text-2xl font-semibold tabular-nums">
                    {{ report.totals.provider_cost.formatted }}
                </dd>
                <dd
                    v-if="report.totals.pixelforge_cost"
                    class="mt-1 text-xs text-zinc-500 tabular-nums"
                >
                    Pixelforge reports
                    {{ report.totals.pixelforge_cost.formatted }}
                </dd>
            </div>
            <div class="rounded-xl border border-zinc-200 bg-white p-4">
                <dt class="text-xs text-zinc-500">Profit</dt>
                <dd
                    class="mt-1 text-2xl font-semibold tabular-nums"
                    :title="`$${report.totals.profit.amount}`"
                >
                    {{ report.totals.profit.formatted }}
                </dd>
            </div>
            <div class="rounded-xl border border-zinc-200 bg-white p-4">
                <dt class="text-xs text-zinc-500">Generations</dt>
                <dd class="mt-1 text-2xl font-semibold tabular-nums">
                    {{ report.totals.generations }}
                </dd>
            </div>
        </dl>

        <p
            v-if="pixelforgeError"
            class="mt-4 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800"
            role="alert"
        >
            Couldn't load Pixelforge's usage report, so costs aren't reconciled:
            {{ pixelforgeError }}
        </p>
        <p
            v-else-if="reconciliationSummary"
            class="mt-4 rounded-lg border px-4 py-3 text-sm"
            :class="
                report.reconciliation.mismatched_days === 0
                    ? 'border-emerald-200 bg-emerald-50 text-emerald-800'
                    : 'border-red-200 bg-red-50 text-red-800'
            "
            role="status"
        >
            {{ reconciliationSummary }}
        </p>

        <h2 class="mt-10 text-lg font-semibold">By day</h2>
        <div
            class="mt-3 overflow-x-auto rounded-xl border border-zinc-200 bg-white"
        >
            <table class="w-full text-sm tabular-nums">
                <thead class="bg-zinc-50 text-left text-xs text-zinc-500">
                    <tr>
                        <th class="px-4 py-2 font-medium">Date (UTC)</th>
                        <th class="px-4 py-2 text-right font-medium">
                            Generations
                        </th>
                        <th class="px-4 py-2 text-right font-medium">
                            Charged
                        </th>
                        <th class="px-4 py-2 text-right font-medium">
                            Pixelforge cost (ours)
                        </th>
                        <th class="px-4 py-2 text-right font-medium">
                            Pixelforge reports
                        </th>
                        <th class="px-4 py-2 text-right font-medium">
                            Difference
                        </th>
                        <th class="px-4 py-2 text-right font-medium">Profit</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-100">
                    <tr
                        v-for="day in report.days"
                        :key="day.date"
                        :class="{
                            'bg-red-50':
                                day.pixelforge && !day.pixelforge.matches,
                        }"
                    >
                        <td class="px-4 py-2">{{ day.date }}</td>
                        <td class="px-4 py-2 text-right">
                            {{ day.generations }}
                            <span
                                v-if="
                                    day.pixelforge &&
                                    day.pixelforge.generations !==
                                        day.generations
                                "
                                class="text-red-700"
                            >
                                (PF {{ day.pixelforge.generations }})
                            </span>
                        </td>
                        <td
                            class="px-4 py-2 text-right"
                            :title="`$${day.charged.amount}`"
                        >
                            {{ day.charged.formatted }}
                        </td>
                        <td class="px-4 py-2 text-right">
                            {{ day.provider_cost.formatted }}
                        </td>
                        <td class="px-4 py-2 text-right">
                            {{ day.pixelforge?.cost.formatted ?? '—' }}
                        </td>
                        <td
                            class="px-4 py-2 text-right"
                            :class="
                                day.pixelforge && !day.pixelforge.matches
                                    ? 'font-medium text-red-700'
                                    : 'text-zinc-400'
                            "
                        >
                            <template v-if="day.pixelforge">
                                {{
                                    day.pixelforge.matches
                                        ? 'Matches'
                                        : day.pixelforge.difference.formatted
                                }}
                            </template>
                            <template v-else>—</template>
                        </td>
                        <td
                            class="px-4 py-2 text-right"
                            :title="`$${day.profit.amount}`"
                        >
                            {{ day.profit.formatted }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <h2 class="mt-10 text-lg font-semibold">By user</h2>
        <p v-if="report.users.length === 0" class="mt-3 text-sm text-zinc-600">
            No billed generations in this range.
        </p>
        <div
            v-else
            class="mt-3 overflow-x-auto rounded-xl border border-zinc-200 bg-white"
        >
            <table class="w-full text-sm tabular-nums">
                <thead class="bg-zinc-50 text-left text-xs text-zinc-500">
                    <tr>
                        <th class="px-4 py-2 font-medium">User</th>
                        <th class="px-4 py-2 text-right font-medium">
                            Generations
                        </th>
                        <th class="px-4 py-2 text-right font-medium">
                            Charged
                        </th>
                        <th class="px-4 py-2 text-right font-medium">
                            Pixelforge cost
                        </th>
                        <th class="px-4 py-2 text-right font-medium">Profit</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-100">
                    <tr v-for="user in report.users" :key="user.user_id">
                        <td class="px-4 py-2">
                            <span class="block font-medium">{{
                                user.name
                            }}</span>
                            <span class="block text-xs text-zinc-500">{{
                                user.email
                            }}</span>
                        </td>
                        <td class="px-4 py-2 text-right">
                            {{ user.generations }}
                        </td>
                        <td
                            class="px-4 py-2 text-right"
                            :title="`$${user.charged.amount}`"
                        >
                            {{ user.charged.formatted }}
                        </td>
                        <td class="px-4 py-2 text-right">
                            {{ user.provider_cost.formatted }}
                        </td>
                        <td
                            class="px-4 py-2 text-right"
                            :title="`$${user.profit.amount}`"
                        >
                            {{ user.profit.formatted }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </AppLayout>
</template>
