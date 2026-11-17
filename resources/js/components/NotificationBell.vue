<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { onBeforeUnmount, onMounted, ref } from 'vue';
import { relativeTime } from '@/lib/relativeTime';
import type { AppNotification } from '@/types';

defineProps<{
    notifications: { unread: number; recent: AppNotification[] };
}>();

const open = ref(false);
const root = ref<HTMLElement | null>(null);

const icon: Record<string, string> = {
    generation_finished: '🖼',
    invitation: '✉',
    low_credits: '⚠',
};

function timeAgo(date: string | null): string {
    return date ? relativeTime(date) : '';
}

function markAllRead(): void {
    router.post(
        '/notifications/read',
        {},
        { preserveScroll: true, preserveState: true, only: ['notifications'] },
    );
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
            class="relative flex size-8 items-center justify-center rounded-full text-zinc-600 hover:bg-zinc-100 hover:text-zinc-900"
            :aria-expanded="open"
            aria-haspopup="menu"
            :aria-label="
                notifications.unread > 0
                    ? `Notifications, ${notifications.unread} unread`
                    : 'Notifications'
            "
            @click="open = !open"
        >
            <svg
                class="size-5"
                viewBox="0 0 20 20"
                fill="currentColor"
                aria-hidden="true"
            >
                <path
                    fill-rule="evenodd"
                    d="M10 2a6 6 0 0 0-6 6c0 1.887-.454 3.665-1.257 5.234a.75.75 0 0 0 .515 1.076 32.91 32.91 0 0 0 3.256.508 3.5 3.5 0 0 0 6.972 0 32.903 32.903 0 0 0 3.256-.508.75.75 0 0 0 .515-1.076A11.448 11.448 0 0 1 16 8a6 6 0 0 0-6-6ZM8.05 14.943a33.54 33.54 0 0 0 3.9 0 2 2 0 0 1-3.9 0Z"
                    clip-rule="evenodd"
                />
            </svg>
            <span
                v-if="notifications.unread > 0"
                class="absolute -top-0.5 -right-0.5 flex h-4 min-w-4 items-center justify-center rounded-full bg-red-600 px-1 text-[10px] font-semibold text-white tabular-nums"
                aria-hidden="true"
            >
                {{ notifications.unread > 9 ? '9+' : notifications.unread }}
            </span>
        </button>
        <div
            v-if="open"
            role="menu"
            class="absolute right-0 z-30 mt-2 w-80 rounded-xl border border-zinc-200 bg-white p-1 text-sm shadow-lg"
        >
            <div class="flex items-center justify-between px-3 pt-1.5 pb-1">
                <p
                    class="text-xs font-medium tracking-wide text-zinc-400 uppercase"
                >
                    Notifications
                </p>
                <button
                    v-if="notifications.unread > 0"
                    type="button"
                    class="text-xs font-medium text-zinc-600 hover:text-zinc-900"
                    @click="markAllRead"
                >
                    Mark all as read
                </button>
            </div>
            <p
                v-if="notifications.recent.length === 0"
                class="px-3 py-6 text-center text-zinc-500"
            >
                You're all caught up.
            </p>
            <Link
                v-for="notification in notifications.recent"
                :key="notification.id"
                :href="`/notifications/${notification.id}`"
                role="menuitem"
                class="flex gap-3 rounded-lg px-3 py-2 hover:bg-zinc-100"
                @click="open = false"
            >
                <span class="mt-0.5 shrink-0" aria-hidden="true">{{
                    icon[notification.kind] ?? '•'
                }}</span>
                <span class="min-w-0 flex-1">
                    <span
                        class="block"
                        :class="
                            notification.read
                                ? 'text-zinc-600'
                                : 'font-medium text-zinc-900'
                        "
                    >
                        {{ notification.title }}
                    </span>
                    <span class="block truncate text-xs text-zinc-500">{{
                        notification.body
                    }}</span>
                    <span class="block text-xs text-zinc-400">{{
                        timeAgo(notification.created_at)
                    }}</span>
                </span>
                <span
                    v-if="!notification.read"
                    class="mt-1.5 size-2 shrink-0 rounded-full bg-sky-500"
                    aria-label="Unread"
                />
            </Link>
        </div>
    </div>
</template>
