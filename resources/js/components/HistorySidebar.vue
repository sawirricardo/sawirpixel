<script setup lang="ts">
import { Link, usePage, usePoll } from '@inertiajs/vue3';
import { computed, watch } from 'vue';
import type { GenerationStatus, RecentGeneration } from '@/types';

const props = defineProps<{
    generations: RecentGeneration[];
}>();

const page = usePage();

const inProgress: GenerationStatus[] = ['pending', 'queued', 'processing'];

/**
 * Keep the status dots live while anything is still running, refreshing only the sidebar's data.
 */
const { start, stop } = usePoll(
    3000,
    { only: ['recentGenerations', 'auth'] },
    { autoStart: false },
);

watch(
    () =>
        props.generations.some((generation) =>
            inProgress.includes(generation.status),
        ),
    (active) => (active ? start() : stop()),
    { immediate: true },
);

const groups = computed(() => {
    const startOfToday = new Date();
    startOfToday.setHours(0, 0, 0, 0);
    const day = 86_400_000;
    const buckets: { label: string; items: RecentGeneration[] }[] = [
        { label: 'Today', items: [] },
        { label: 'Yesterday', items: [] },
        { label: 'Previous 7 days', items: [] },
        { label: 'Older', items: [] },
    ];

    for (const generation of props.generations) {
        const created = generation.created_at
            ? new Date(generation.created_at).getTime()
            : 0;
        const index =
            created >= startOfToday.getTime()
                ? 0
                : created >= startOfToday.getTime() - day
                  ? 1
                  : created >= startOfToday.getTime() - 7 * day
                    ? 2
                    : 3;

        buckets[index]?.items.push(generation);
    }

    return buckets.filter((bucket) => bucket.items.length > 0);
});

const statusDot: Record<GenerationStatus, string> = {
    pending: 'bg-zinc-400 animate-pulse',
    queued: 'bg-sky-500 animate-pulse',
    processing: 'bg-amber-500 animate-pulse',
    succeeded: 'bg-emerald-500',
    failed: 'bg-red-500',
};

function isActive(id: number): boolean {
    return page.url === `/generations/${id}`;
}
</script>

<template>
    <nav class="flex h-full flex-col" aria-label="Your generations">
        <div class="p-3">
            <Link
                href="/"
                class="flex items-center gap-2 rounded-lg border border-zinc-200 bg-white px-3 py-2 text-sm font-medium hover:bg-zinc-100"
            >
                <svg
                    class="size-4"
                    viewBox="0 0 20 20"
                    fill="currentColor"
                    aria-hidden="true"
                >
                    <path
                        d="M10.75 4.75a.75.75 0 0 0-1.5 0v4.5h-4.5a.75.75 0 0 0 0 1.5h4.5v4.5a.75.75 0 0 0 1.5 0v-4.5h4.5a.75.75 0 0 0 0-1.5h-4.5v-4.5Z"
                    />
                </svg>
                New image
            </Link>
        </div>

        <div class="flex-1 overflow-y-auto px-3 pb-3">
            <p
                v-if="generations.length === 0"
                class="px-2 py-4 text-sm text-zinc-500"
            >
                Your generations will appear here.
            </p>
            <section
                v-for="group in groups"
                :key="group.label"
                class="mt-3 first:mt-0"
            >
                <h2 class="px-2 pb-1 text-xs font-medium text-zinc-500">
                    {{ group.label }}
                </h2>
                <ul>
                    <li v-for="generation in group.items" :key="generation.id">
                        <Link
                            :href="`/generations/${generation.id}`"
                            class="flex items-center gap-2 rounded-lg px-2 py-1.5 text-sm text-zinc-700 hover:bg-zinc-200/60"
                            :class="{
                                'bg-zinc-200/80 text-zinc-900': isActive(
                                    generation.id,
                                ),
                            }"
                            :aria-current="
                                isActive(generation.id) ? 'page' : undefined
                            "
                            preserve-scroll
                        >
                            <span
                                class="size-2 shrink-0 rounded-full"
                                :class="statusDot[generation.status]"
                                :title="generation.status"
                            />
                            <span class="truncate">{{
                                generation.prompt
                            }}</span>
                        </Link>
                    </li>
                </ul>
            </section>
        </div>

        <div class="border-t border-zinc-200 p-3">
            <Link
                href="/generations"
                class="block rounded-lg px-2 py-1.5 text-sm text-zinc-600 hover:bg-zinc-200/60 hover:text-zinc-900"
            >
                View all generations
            </Link>
        </div>
    </nav>
</template>
