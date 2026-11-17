<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { onBeforeUnmount, onMounted, ref } from 'vue';

const props = defineProps<{
    current: { id: number; name: string };
    organizations: { id: number; name: string }[];
    canViewMembers: boolean;
}>();

const open = ref(false);
const root = ref<HTMLElement | null>(null);

function switchTo(id: number): void {
    open.value = false;

    if (id !== props.current.id) {
        router.put(`/organizations/${id}/current`);
    }
}

function closeOnOutsideClick(event: MouseEvent): void {
    if (root.value && !root.value.contains(event.target as Node)) {
        open.value = false;
    }
}

onMounted(() => document.addEventListener('mousedown', closeOnOutsideClick));
onBeforeUnmount(() =>
    document.removeEventListener('mousedown', closeOnOutsideClick),
);
</script>

<template>
    <div ref="root" class="relative" @keydown.escape="open = false">
        <button
            type="button"
            class="flex items-center gap-1.5 rounded-full border border-zinc-200 px-3 py-1 text-zinc-700 hover:bg-zinc-100"
            :aria-expanded="open"
            aria-haspopup="menu"
            @click="open = !open"
        >
            {{ current.name }}
            <svg
                class="size-3.5 text-zinc-400"
                viewBox="0 0 20 20"
                fill="currentColor"
                aria-hidden="true"
            >
                <path
                    fill-rule="evenodd"
                    d="M5.23 7.21a.75.75 0 0 1 1.06.02L10 11.17l3.71-3.94a.75.75 0 1 1 1.08 1.04l-4.25 4.5a.75.75 0 0 1-1.08 0l-4.25-4.5a.75.75 0 0 1 .02-1.06Z"
                    clip-rule="evenodd"
                />
            </svg>
        </button>
        <div
            v-if="open"
            role="menu"
            class="absolute right-0 z-30 mt-2 w-60 rounded-xl border border-zinc-200 bg-white p-1 text-sm shadow-lg"
        >
            <p
                class="px-3 pt-1.5 pb-1 text-xs font-medium tracking-wide text-zinc-400 uppercase"
            >
                Organizations
            </p>
            <button
                v-for="organization in organizations"
                :key="organization.id"
                type="button"
                role="menuitemradio"
                :aria-checked="organization.id === current.id"
                class="flex w-full items-center justify-between rounded-lg px-3 py-2 text-left hover:bg-zinc-100"
                @click="switchTo(organization.id)"
            >
                {{ organization.name }}
                <span v-if="organization.id === current.id" aria-hidden="true"
                    >✓</span
                >
            </button>
            <div class="my-1 border-t border-zinc-100" />
            <Link
                v-if="canViewMembers"
                href="/organization/members"
                role="menuitem"
                class="block rounded-lg px-3 py-2 hover:bg-zinc-100"
                @click="open = false"
            >
                Members
            </Link>
            <Link
                href="/credits"
                role="menuitem"
                class="block rounded-lg px-3 py-2 hover:bg-zinc-100"
                @click="open = false"
            >
                Credits
            </Link>
            <Link
                href="/organizations/create"
                role="menuitem"
                class="block rounded-lg px-3 py-2 hover:bg-zinc-100"
                @click="open = false"
            >
                New organization
            </Link>
        </div>
    </div>
</template>
