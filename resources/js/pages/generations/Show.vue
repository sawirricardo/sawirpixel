<script setup lang="ts">
import { Head, usePoll } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatMoneyRange } from '@/lib/money';
import type { GenerationItem, GenerationStatus } from '@/types';

const props = defineProps<{
    generation: GenerationItem;
}>();

const inProgress: GenerationStatus[] = ['pending', 'queued', 'processing'];
const running = computed(() => inProgress.includes(props.generation.status));

const { start, stop } = usePoll(
    3000,
    { only: ['generation', 'recentGenerations', 'auth'] },
    { autoStart: false },
);

watch(running, (active) => (active ? start() : stop()), { immediate: true });

const imageBroken = ref(false);

watch(
    () => props.generation.id,
    () => (imageBroken.value = false),
);

const statusLabel: Record<GenerationStatus, string> = {
    pending: 'Waiting to be submitted…',
    queued: 'Queued at Pixelforge…',
    processing: 'Generating…',
    succeeded: 'Done',
    failed: 'Failed',
};

function formatDate(date: string | null): string {
    return date ? new Date(date).toLocaleString() : '—';
}
</script>

<template>
    <AppLayout>
        <Head :title="generation.prompt.slice(0, 40)" />
        <div class="mx-auto max-w-3xl">
            <div
                class="ml-auto w-fit max-w-[85%] rounded-3xl bg-zinc-200/70 px-5 py-3 text-sm whitespace-pre-wrap"
            >
                {{ generation.prompt }}
            </div>
            <p class="mt-2 text-right text-xs text-zinc-500">
                {{ generation.size }} · {{ generation.quality }}
                <template v-if="generation.reference_images.length">
                    · {{ generation.reference_images.length }} reference
                    image(s)
                </template>
            </p>

            <div
                class="mt-6 overflow-hidden rounded-2xl border border-zinc-200 bg-white"
            >
                <div
                    class="flex min-h-80 items-center justify-center bg-zinc-100"
                >
                    <img
                        v-if="generation.image_url && !imageBroken"
                        :src="generation.image_url"
                        :alt="generation.prompt"
                        class="max-h-[70vh] w-full object-contain"
                        @error="imageBroken = true"
                    />
                    <p
                        v-else-if="generation.status === 'succeeded'"
                        class="px-6 text-sm text-zinc-500"
                    >
                        The image link has expired.
                    </p>
                    <p
                        v-else-if="generation.status === 'failed'"
                        class="px-6 text-center text-sm text-red-700"
                    >
                        {{
                            generation.error_message ??
                            'This generation failed.'
                        }}
                    </p>
                    <p
                        v-else
                        class="animate-pulse text-sm text-zinc-500"
                        role="status"
                    >
                        {{ statusLabel[generation.status] }}
                    </p>
                </div>
                <dl class="grid grid-cols-2 gap-4 p-4 text-sm sm:grid-cols-4">
                    <div>
                        <dt class="text-xs text-zinc-500">Status</dt>
                        <dd class="font-medium">
                            {{ statusLabel[generation.status] }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs text-zinc-500">Estimated</dt>
                        <dd class="font-medium tabular-nums">
                            {{ formatMoneyRange(generation.estimated_charge) }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs text-zinc-500">Charged</dt>
                        <dd
                            class="font-medium tabular-nums"
                            :title="
                                generation.charge
                                    ? `$${generation.charge.amount}`
                                    : undefined
                            "
                        >
                            {{
                                generation.charge?.formatted ??
                                (running ? 'When finished' : '—')
                            }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs text-zinc-500">Created</dt>
                        <dd class="font-medium">
                            {{ formatDate(generation.created_at) }}
                        </dd>
                    </div>
                </dl>
            </div>
        </div>
    </AppLayout>
</template>
