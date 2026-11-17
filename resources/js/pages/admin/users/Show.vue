<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatMoneyRange } from '@/lib/money';
import type { GenerationStatus, Money, Range, ReportAmounts } from '@/types';

defineProps<{
    user: {
        id: number;
        name: string;
        email: string;
        is_super_admin: boolean;
        email_verified_at: string | null;
        joined_at: string | null;
        current_organization_id: number | null;
    };
    totals: ReportAmounts;
    memberships: {
        organization_id: number;
        organization: string;
        roles: string[];
        permissions: string[];
        joined_at: string | null;
    }[];
    generations: {
        id: number;
        prompt: string;
        organization: string;
        status: GenerationStatus;
        charge: Money | null;
        estimated_charge: Range<Money>;
        created_at: string | null;
    }[];
}>();

function formatDate(date: string | null): string {
    return date ? new Date(date).toLocaleString() : '—';
}

const statusStyles: Record<GenerationStatus, string> = {
    pending: 'bg-zinc-100 text-zinc-700',
    queued: 'bg-sky-100 text-sky-800',
    processing: 'bg-amber-100 text-amber-800',
    succeeded: 'bg-emerald-100 text-emerald-800',
    failed: 'bg-red-100 text-red-800',
};
</script>

<template>
    <AppLayout>
        <Head :title="user.name" />
        <Link
            href="/admin/users"
            class="text-sm text-zinc-500 hover:text-zinc-900"
            >← All users</Link
        >

        <div class="mt-3 flex flex-wrap items-center gap-3">
            <h1 class="text-2xl font-semibold">{{ user.name }}</h1>
            <span
                v-if="user.is_super_admin"
                class="rounded-full bg-zinc-900 px-2 py-0.5 text-xs text-white"
            >
                Operator
            </span>
        </div>
        <p class="mt-1 text-sm text-zinc-600">
            {{ user.email }} · joined {{ formatDate(user.joined_at) }} ·
            {{
                user.email_verified_at ? 'email verified' : 'email not verified'
            }}
        </p>

        <dl class="mt-6 grid gap-4 sm:grid-cols-4">
            <div class="rounded-xl border border-zinc-200 bg-white p-4">
                <dt class="text-xs text-zinc-500">Charged (lifetime)</dt>
                <dd
                    class="mt-1 text-2xl font-semibold tabular-nums"
                    :title="`$${totals.charged.amount}`"
                >
                    {{ totals.charged.formatted }}
                </dd>
            </div>
            <div class="rounded-xl border border-zinc-200 bg-white p-4">
                <dt class="text-xs text-zinc-500">Pixelforge cost</dt>
                <dd class="mt-1 text-2xl font-semibold tabular-nums">
                    {{ totals.provider_cost.formatted }}
                </dd>
            </div>
            <div class="rounded-xl border border-zinc-200 bg-white p-4">
                <dt class="text-xs text-zinc-500">Profit</dt>
                <dd
                    class="mt-1 text-2xl font-semibold tabular-nums"
                    :title="`$${totals.profit.amount}`"
                >
                    {{ totals.profit.formatted }}
                </dd>
            </div>
            <div class="rounded-xl border border-zinc-200 bg-white p-4">
                <dt class="text-xs text-zinc-500">Billed generations</dt>
                <dd class="mt-1 text-2xl font-semibold tabular-nums">
                    {{ totals.generations }}
                </dd>
            </div>
        </dl>

        <h2 class="mt-10 text-lg font-semibold">Organizations</h2>
        <p v-if="memberships.length === 0" class="mt-3 text-sm text-zinc-600">
            Not a member of any organization.
        </p>
        <ul
            v-else
            class="mt-3 divide-y divide-zinc-100 rounded-xl border border-zinc-200 bg-white"
        >
            <li
                v-for="membership in memberships"
                :key="membership.organization_id"
                class="flex flex-wrap items-center justify-between gap-3 px-4 py-3 text-sm"
            >
                <div>
                    <p class="font-medium">
                        {{ membership.organization }}
                        <span
                            v-if="
                                membership.organization_id ===
                                user.current_organization_id
                            "
                            class="text-xs font-normal text-zinc-500"
                        >
                            (current)
                        </span>
                    </p>
                    <p
                        v-if="membership.permissions.length"
                        class="text-xs text-zinc-500"
                    >
                        Also: {{ membership.permissions.join(', ') }}
                    </p>
                </div>
                <span class="text-zinc-600">{{
                    membership.roles.join(', ') || 'No role'
                }}</span>
            </li>
        </ul>

        <h2 class="mt-10 text-lg font-semibold">Recent generations</h2>
        <p v-if="generations.length === 0" class="mt-3 text-sm text-zinc-600">
            No generations yet.
        </p>
        <div
            v-else
            class="mt-3 overflow-x-auto rounded-xl border border-zinc-200 bg-white"
        >
            <table class="w-full text-sm">
                <thead class="bg-zinc-50 text-left text-xs text-zinc-500">
                    <tr>
                        <th class="px-4 py-2 font-medium">Prompt</th>
                        <th class="px-4 py-2 font-medium">Organization</th>
                        <th class="px-4 py-2 font-medium">Status</th>
                        <th class="px-4 py-2 text-right font-medium">
                            Estimated
                        </th>
                        <th class="px-4 py-2 text-right font-medium">
                            Charged
                        </th>
                        <th class="px-4 py-2 font-medium">Created</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-100">
                    <tr v-for="generation in generations" :key="generation.id">
                        <td
                            class="max-w-72 truncate px-4 py-2"
                            :title="generation.prompt"
                        >
                            {{ generation.prompt }}
                        </td>
                        <td class="px-4 py-2 text-zinc-600">
                            {{ generation.organization }}
                        </td>
                        <td class="px-4 py-2">
                            <span
                                class="rounded-full px-2 py-0.5 text-xs font-medium capitalize"
                                :class="statusStyles[generation.status]"
                            >
                                {{ generation.status }}
                            </span>
                        </td>
                        <td class="px-4 py-2 text-right tabular-nums">
                            {{ formatMoneyRange(generation.estimated_charge) }}
                        </td>
                        <td class="px-4 py-2 text-right tabular-nums">
                            {{ generation.charge?.formatted ?? '—' }}
                        </td>
                        <td class="px-4 py-2 text-zinc-600">
                            {{ formatDate(generation.created_at) }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </AppLayout>
</template>
