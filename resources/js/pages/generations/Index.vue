<script setup lang="ts">
import { Head, Link, usePoll } from '@inertiajs/vue3';
import { computed, reactive, watch } from 'vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { describeExpiry } from '@/lib/expiry';
import { isUnfinished } from '@/lib/generationStatus';
import { formatMoneyRange } from '@/lib/money';
import { useNow } from '@/lib/useNow';
import type { GenerationItem, GenerationStatus, Paginated } from '@/types';

const props = defineProps<{
    generations: Paginated<GenerationItem>;
}>();

const hasInProgress = computed(() =>
    props.generations.data.some((generation) =>
        isUnfinished(generation.status),
    ),
);

const { start, stop } = usePoll(
    3000,
    { only: ['generations', 'auth'] },
    { autoStart: false },
);

watch(hasInProgress, (active) => (active ? start() : stop()), {
    immediate: true,
});

const brokenImages = reactive(new Set<number>());

const now = useNow();

const statusStyles: Record<GenerationStatus, string> = {
    pending: 'bg-zinc-100 text-zinc-700',
    queued: 'bg-sky-100 text-sky-800',
    processing: 'bg-amber-100 text-amber-800',
    succeeded: 'bg-emerald-100 text-emerald-800',
    failed: 'bg-red-100 text-red-800',
};

function statusLabel(status: GenerationStatus): string {
    return status === 'pending' ? 'submitting' : status;
}
</script>

<template>
    <AppLayout>
        <Head title="History" />
        <div class="flex items-center justify-between">
            <h1 class="text-2xl font-semibold">Your generations</h1>
            <Link
                href="/"
                class="rounded-md bg-zinc-900 px-4 py-2 text-sm font-medium text-white hover:bg-zinc-700"
            >
                New generation
            </Link>
        </div>

        <p
            v-if="generations.data.length === 0"
            class="mt-10 text-center text-sm text-zinc-600"
        >
            You haven't generated anything yet.
        </p>

        <ul v-else class="mt-6 grid gap-4 sm:grid-cols-2">
            <li
                v-for="generation in generations.data"
                :key="generation.id"
                class="overflow-hidden rounded-lg border border-zinc-200 bg-white"
            >
                <div
                    class="flex aspect-[3/2] items-center justify-center bg-zinc-100"
                >
                    <img
                        v-if="
                            generation.image_url &&
                            !brokenImages.has(generation.id)
                        "
                        :src="generation.image_url"
                        :alt="generation.prompt"
                        class="h-full w-full object-contain"
                        loading="lazy"
                        @error="brokenImages.add(generation.id)"
                    />
                    <span
                        v-else-if="generation.status === 'succeeded'"
                        class="px-6 text-center text-sm text-zinc-500"
                    >
                        The image link has expired.
                    </span>
                    <span
                        v-else-if="generation.status === 'failed'"
                        class="px-6 text-center text-sm text-red-700"
                    >
                        {{ generation.error_message ?? 'Generation failed.' }}
                    </span>
                    <span v-else class="animate-pulse text-sm text-zinc-500">
                        Generating…
                    </span>
                </div>
                <div class="space-y-3 p-4">
                    <div class="flex items-start justify-between gap-3">
                        <p class="line-clamp-2 text-sm">
                            {{ generation.prompt }}
                        </p>
                        <span
                            class="shrink-0 rounded-full px-2 py-0.5 text-xs font-medium capitalize"
                            :class="statusStyles[generation.status]"
                        >
                            {{ statusLabel(generation.status) }}
                        </span>
                    </div>
                    <p class="text-xs text-zinc-500">
                        {{ generation.size }} · {{ generation.quality }}
                        <template v-if="generation.reference_images.length">
                            · {{ generation.reference_images.length }}
                            reference image(s)
                        </template>
                    </p>
                    <p
                        v-if="
                            generation.status === 'succeeded' &&
                            generation.image_url_expires_at
                        "
                        class="text-xs text-zinc-500"
                        :title="
                            new Date(
                                generation.image_url_expires_at,
                            ).toLocaleString()
                        "
                    >
                        {{
                            describeExpiry(generation.image_url_expires_at, now)
                        }}
                    </p>
                    <dl class="grid grid-cols-2 gap-2 text-xs">
                        <div>
                            <dt class="text-zinc-500">Estimated</dt>
                            <dd class="font-medium tabular-nums">
                                {{
                                    formatMoneyRange(
                                        generation.estimated_charge,
                                    )
                                }}
                            </dd>
                        </div>
                        <div>
                            <dt class="text-zinc-500">Actual</dt>
                            <dd class="font-medium tabular-nums">
                                <span
                                    v-if="generation.charge"
                                    :title="`$${generation.charge.amount}`"
                                >
                                    {{ generation.charge.formatted }}
                                </span>
                                <span v-else class="text-zinc-500"
                                    >Pending</span
                                >
                            </dd>
                        </div>
                    </dl>
                </div>
            </li>
        </ul>

        <nav
            v-if="generations.last_page > 1"
            class="mt-8 flex justify-between text-sm"
        >
            <Link
                v-if="generations.prev_page_url"
                :href="generations.prev_page_url"
                preserve-scroll
            >
                ← Newer
            </Link>
            <span v-else />
            <Link
                v-if="generations.next_page_url"
                :href="generations.next_page_url"
                preserve-scroll
            >
                Older →
            </Link>
        </nav>
    </AppLayout>
</template>
