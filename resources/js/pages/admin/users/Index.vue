<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import AppLayout from '@/layouts/AppLayout.vue';
import type { Money, Paginated } from '@/types';

const props = defineProps<{
    filters: { search: string };
    users: Paginated<{
        id: number;
        name: string;
        email: string;
        is_super_admin: boolean;
        organizations: number;
        generations: number;
        charged: Money;
        current_organization: { name: string; credit_balance: Money } | null;
        joined_at: string | null;
    }>;
}>();

const search = ref(props.filters.search);
let debounce: ReturnType<typeof setTimeout> | undefined;

function onSearch(): void {
    clearTimeout(debounce);
    debounce = setTimeout(() => {
        router.get(
            '/admin/users',
            search.value ? { search: search.value } : {},
            { preserveState: true, preserveScroll: true, replace: true },
        );
    }, 300);
}

function formatDate(date: string | null): string {
    return date ? new Date(date).toLocaleDateString() : '—';
}
</script>

<template>
    <AppLayout>
        <Head title="Users" />
        <div class="flex flex-wrap items-end justify-between gap-4">
            <div>
                <h1 class="text-2xl font-semibold">Users</h1>
                <p class="mt-1 text-sm text-zinc-600">
                    {{ users.total }} account{{ users.total === 1 ? '' : 's' }}
                    <template v-if="filters.search">
                        matching “{{ filters.search }}”
                    </template>
                </p>
            </div>
            <label class="w-72">
                <span class="sr-only">Search users</span>
                <input
                    v-model="search"
                    type="search"
                    placeholder="Search by name or email"
                    class="w-full rounded-lg border border-zinc-300 px-3 py-2 text-sm"
                    @input="onSearch"
                />
            </label>
        </div>

        <div
            class="mt-6 overflow-x-auto rounded-xl border border-zinc-200 bg-white"
        >
            <table class="w-full text-sm">
                <thead class="bg-zinc-50 text-left text-xs text-zinc-500">
                    <tr>
                        <th class="px-4 py-2 font-medium">User</th>
                        <th class="px-4 py-2 font-medium">Joined</th>
                        <th class="px-4 py-2 text-right font-medium">
                            Organizations
                        </th>
                        <th class="px-4 py-2 text-right font-medium">
                            Generations
                        </th>
                        <th class="px-4 py-2 text-right font-medium">
                            Charged
                        </th>
                        <th class="px-4 py-2 text-right font-medium">
                            Credits
                        </th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-100">
                    <tr v-if="users.data.length === 0">
                        <td
                            colspan="6"
                            class="px-4 py-6 text-center text-zinc-500"
                        >
                            No users found.
                        </td>
                    </tr>
                    <tr
                        v-for="user in users.data"
                        :key="user.id"
                        class="hover:bg-zinc-50"
                    >
                        <td class="px-4 py-2">
                            <Link
                                :href="`/admin/users/${user.id}`"
                                class="font-medium hover:underline"
                            >
                                {{ user.name }}
                            </Link>
                            <span
                                v-if="user.is_super_admin"
                                class="ml-2 rounded-full bg-zinc-900 px-2 py-0.5 text-xs text-white"
                            >
                                Operator
                            </span>
                            <span class="block text-xs text-zinc-500">{{
                                user.email
                            }}</span>
                        </td>
                        <td class="px-4 py-2 text-zinc-600">
                            {{ formatDate(user.joined_at) }}
                        </td>
                        <td class="px-4 py-2 text-right tabular-nums">
                            {{ user.organizations }}
                        </td>
                        <td class="px-4 py-2 text-right tabular-nums">
                            {{ user.generations }}
                        </td>
                        <td
                            class="px-4 py-2 text-right tabular-nums"
                            :title="`$${user.charged.amount}`"
                        >
                            {{ user.charged.formatted }}
                        </td>
                        <td class="px-4 py-2 text-right tabular-nums">
                            <template v-if="user.current_organization">
                                <span
                                    :title="`$${user.current_organization.credit_balance.amount}`"
                                >
                                    {{
                                        user.current_organization.credit_balance
                                            .formatted
                                    }}
                                </span>
                                <span class="block text-xs text-zinc-500">{{
                                    user.current_organization.name
                                }}</span>
                            </template>
                            <span v-else class="text-zinc-400">—</span>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <nav
            v-if="users.last_page > 1"
            class="mt-6 flex justify-between text-sm"
        >
            <Link
                v-if="users.prev_page_url"
                :href="users.prev_page_url"
                preserve-scroll
                >← Newer</Link
            >
            <span v-else />
            <span class="text-zinc-500"
                >Page {{ users.current_page }} of {{ users.last_page }}</span
            >
            <Link
                v-if="users.next_page_url"
                :href="users.next_page_url"
                preserve-scroll
                >Older →</Link
            >
            <span v-else />
        </nav>
    </AppLayout>
</template>
