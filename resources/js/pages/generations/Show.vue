<script setup lang="ts">
import {
    Head,
    Link,
    router,
    useForm,
    useHttp,
    usePage,
    usePoll,
} from '@inertiajs/vue3';
import { computed, nextTick, onBeforeUnmount, reactive, ref, watch } from 'vue';
import PillMenu from '@/components/PillMenu.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { useCreditsDialog } from '@/lib/creditsDialog';
import { describeExpiry } from '@/lib/expiry';
import { allowedValue, restrictOptions } from '@/lib/generationOptions';
import { formatMoneyRange } from '@/lib/money';
import { useNow } from '@/lib/useNow';
import type {
    CostEstimate,
    GenerationItem,
    GenerationStatus,
    SharedProps,
    ThreadVersion,
    VersionMapEntry,
} from '@/types';

const props = defineProps<{
    generation: GenerationItem;
    thread: ThreadVersion[];
    latestFollowUpId: number | null;
    versions: VersionMapEntry[];
    branchCount: number;
}>();

const page = usePage<SharedProps>();
const creditsDialog = useCreditsDialog();
const now = useNow();

const inProgress: GenerationStatus[] = ['pending', 'queued', 'processing'];
const running = computed(() =>
    props.thread.some((version) => inProgress.includes(version.status)),
);

const { start, stop } = usePoll(
    3000,
    {
        only: [
            'generation',
            'thread',
            'latestFollowUpId',
            'versions',
            'branchCount',
            'recentGenerations',
            'notifications',
            'auth',
        ],
    },
    { autoStart: false },
);

watch(running, (active) => (active ? start() : stop()), { immediate: true });

const brokenImages = reactive(new Set<number>());

const statusDot: Record<GenerationStatus, string> = {
    pending: 'bg-zinc-400 animate-pulse',
    queued: 'bg-sky-500 animate-pulse',
    processing: 'bg-amber-500 animate-pulse',
    succeeded: 'bg-emerald-500',
    failed: 'bg-red-500',
};

const statusLabel: Record<GenerationStatus, string> = {
    pending: 'Waiting to be submitted…',
    queued: 'Queued…',
    processing: 'Generating…',
    succeeded: 'Done',
    failed: 'Failed',
};

function formatDate(date: string | null): string {
    return date ? new Date(date).toLocaleString() : '—';
}

/**
 * The version a follow-up continues from: the newest one by default, or one picked with
 * "Continue from here", which starts a new branch.
 */
const lastVersion = computed(() => props.thread[props.thread.length - 1]);
const chosenParentId = ref<number | null>(null);
const parent = computed(
    () =>
        props.thread.find((version) => version.id === chosenParentId.value) ??
        lastVersion.value,
);
const isBranching = computed(() => parent.value?.id !== lastVersion.value?.id);

watch(
    () => props.generation.id,
    () => (chosenParentId.value = null),
);

const textarea = ref<HTMLTextAreaElement | null>(null);

function continueFrom(version: ThreadVersion): void {
    chosenParentId.value = version.id;
    void nextTick(() => textarea.value?.focus());
}

const form = useForm({
    prompt: '',
    size: props.generation.size,
    quality: props.generation.quality,
    parent_id: null as number | null,
});

const sizeOptions = computed(() =>
    restrictOptions(
        [
            { value: '1024x1024', label: 'Square', hint: '1024 × 1024' },
            { value: '1536x1024', label: 'Landscape', hint: '1536 × 1024' },
            { value: '1024x1536', label: 'Portrait', hint: '1024 × 1536' },
        ],
        page.props.auth.generationOptions?.sizes,
    ),
);
const qualityOptions = computed(() =>
    restrictOptions(
        [
            { value: 'low', label: 'Low', hint: 'Fastest, cheapest' },
            { value: 'medium', label: 'Medium', hint: 'Balanced' },
            { value: 'high', label: 'High', hint: 'Most detail' },
        ],
        page.props.auth.generationOptions?.qualities,
    ),
);

const estimate = useHttp<
    {
        prompt: string;
        size: string;
        quality: string;
        parent_id: number | null;
        reference_images: string[];
    },
    CostEstimate
>({
    prompt: '',
    size: form.size,
    quality: form.quality,
    parent_id: null,
    reference_images: [],
});

let debounce: ReturnType<typeof setTimeout> | undefined;

watch(
    () => [
        form.prompt,
        form.size,
        form.quality,
        parent.value?.id,
        page.props.auth.credits?.balance.amount,
    ],
    () => {
        clearTimeout(debounce);
        debounce = setTimeout(() => {
            estimate.cancel();
            estimate.prompt = form.prompt;
            estimate.size = form.size;
            estimate.quality = form.quality;
            estimate.parent_id = parent.value?.id ?? null;
            void estimate.post('/generations/estimate');
        }, 300);
    },
    { immediate: true },
);

onBeforeUnmount(() => clearTimeout(debounce));

const errors = computed(
    () => form.errors as Record<string, string | undefined>,
);
const firstError = computed(
    () =>
        errors.value.limit ??
        errors.value.credits ??
        errors.value.parent_id ??
        errors.value.prompt ??
        errors.value.reference_images,
);

// Never leave a restricted quality or size selected, e.g. from a version made before the restriction.
watch(
    () => [page.props.auth.generationOptions, form.size, form.quality],
    () => {
        form.size = allowedValue(
            form.size,
            page.props.auth.generationOptions?.sizes,
        );
        form.quality = allowedValue(
            form.quality,
            page.props.auth.generationOptions?.qualities,
        );
    },
    { immediate: true },
);

/** Why the organization's limits would refuse this follow-up, if they would. */
const limitBlocked = computed(() =>
    estimate.hasErrors ? null : (estimate.response?.blocked ?? null),
);

const canSend = computed(
    () =>
        !limitBlocked.value &&
        form.prompt.trim() !== '' &&
        !form.processing &&
        parent.value?.can_continue === true,
);

function send(): void {
    if (!canSend.value || !parent.value) {
        return;
    }

    if (!page.props.auth.user?.email_verified) {
        router.visit('/email/verify');

        return;
    }

    if (estimate.response?.affordable === false) {
        if (page.props.auth.credits?.canBuy) {
            creditsDialog.show();
        }

        return;
    }

    form.parent_id = parent.value.id;
    form.post('/generations', {
        preserveScroll: true,
        onSuccess: () => form.reset('prompt'),
    });
}

function onKeydown(event: KeyboardEvent): void {
    if (event.key === 'Enter' && !event.shiftKey && !event.isComposing) {
        event.preventDefault();
        send();
    }
}

function versionNumber(version: ThreadVersion): number {
    return props.thread.indexOf(version) + 1;
}
</script>

<template>
    <AppLayout>
        <Head :title="(thread[0]?.prompt ?? generation.prompt).slice(0, 40)" />
        <div class="mx-auto max-w-4xl pb-48">
            <details
                v-if="versions.length > 1"
                class="mb-8 rounded-2xl border border-zinc-200 bg-white"
                :open="branchCount > 1"
            >
                <summary
                    class="cursor-pointer px-4 py-3 text-sm font-medium text-zinc-700 select-none"
                >
                    {{ versions.length }} versions<template
                        v-if="branchCount > 1"
                    >
                        · {{ branchCount }} branches</template
                    >
                </summary>
                <ol
                    class="border-t border-zinc-100 px-2 py-2"
                    aria-label="All versions in this thread"
                >
                    <li v-for="entry in versions" :key="entry.id">
                        <Link
                            :href="`/generations/${entry.id}`"
                            preserve-scroll
                            class="flex items-center gap-2 rounded-lg py-1 pr-2 text-sm hover:bg-zinc-100"
                            :class="{
                                'bg-zinc-100 font-medium text-zinc-900':
                                    entry.is_current,
                                'text-zinc-700':
                                    entry.on_path && !entry.is_current,
                                'text-zinc-500': !entry.on_path,
                            }"
                            :style="{
                                paddingLeft: `${0.5 + entry.depth * 1.25}rem`,
                            }"
                            :aria-current="
                                entry.is_current ? 'page' : undefined
                            "
                        >
                            <span
                                v-if="entry.depth > 0"
                                class="text-zinc-300"
                                aria-hidden="true"
                                >└</span
                            >
                            <img
                                v-if="
                                    entry.image_url &&
                                    !brokenImages.has(entry.id)
                                "
                                :src="entry.image_url"
                                alt=""
                                loading="lazy"
                                class="size-8 shrink-0 rounded-md bg-zinc-100 object-cover"
                                @error="brokenImages.add(entry.id)"
                            />
                            <span
                                v-else
                                class="size-8 shrink-0 rounded-md bg-zinc-100"
                                aria-hidden="true"
                            />
                            <span
                                class="size-2 shrink-0 rounded-full"
                                :class="statusDot[entry.status]"
                                :title="entry.status"
                            />
                            <span
                                class="shrink-0 text-xs text-zinc-400 tabular-nums"
                                >v{{ entry.number }}</span
                            >
                            <span class="truncate">{{ entry.prompt }}</span>
                        </Link>
                    </li>
                </ol>
            </details>

            <article v-for="version in thread" :key="version.id" class="mb-10">
                <div
                    class="ml-auto w-fit max-w-[85%] rounded-3xl bg-zinc-200/70 px-5 py-3 text-sm whitespace-pre-wrap"
                >
                    {{ version.prompt }}
                </div>
                <div
                    class="mt-1 flex items-center justify-end gap-2 text-xs text-zinc-500"
                >
                    <span>
                        {{ version.size }} · {{ version.quality }}
                        <template v-if="version.parent_id">
                            · continues the previous version</template
                        >
                    </span>
                    <span
                        v-if="version.branches.total > 1"
                        class="flex items-center gap-1"
                        aria-label="Switch branch"
                    >
                        <Link
                            v-if="version.branches.position > 1"
                            :href="`/generations/${version.branches.tips[version.branches.position - 2]}`"
                            preserve-scroll
                            class="rounded px-1 hover:bg-zinc-200"
                            aria-label="Previous branch"
                            >‹</Link
                        >
                        <span v-else class="px-1 text-zinc-300">‹</span>
                        <span class="tabular-nums"
                            >{{ version.branches.position }}/{{
                                version.branches.total
                            }}</span
                        >
                        <Link
                            v-if="
                                version.branches.position <
                                version.branches.total
                            "
                            :href="`/generations/${version.branches.tips[version.branches.position]}`"
                            preserve-scroll
                            class="rounded px-1 hover:bg-zinc-200"
                            aria-label="Next branch"
                            >›</Link
                        >
                        <span v-else class="px-1 text-zinc-300">›</span>
                    </span>
                </div>

                <div
                    class="mt-3 overflow-hidden rounded-2xl border bg-white"
                    :class="
                        parent?.id === version.id && isBranching
                            ? 'border-zinc-900'
                            : 'border-zinc-200'
                    "
                >
                    <div
                        class="flex min-h-64 items-center justify-center bg-zinc-100"
                    >
                        <img
                            v-if="
                                version.image_url &&
                                !brokenImages.has(version.id)
                            "
                            :src="version.image_url"
                            :alt="version.prompt"
                            class="max-h-[60vh] w-full object-contain"
                            @error="brokenImages.add(version.id)"
                        />
                        <p
                            v-else-if="version.status === 'succeeded'"
                            class="px-6 text-sm text-zinc-500"
                        >
                            The image link has expired.
                        </p>
                        <p
                            v-else-if="version.status === 'failed'"
                            class="px-6 text-center text-sm text-red-700"
                        >
                            {{
                                version.error_message ??
                                'This generation failed.'
                            }}
                        </p>
                        <p
                            v-else
                            class="animate-pulse text-sm text-zinc-500"
                            role="status"
                        >
                            {{ statusLabel[version.status] }}
                        </p>
                    </div>
                    <div
                        class="flex flex-wrap items-center justify-between gap-3 px-4 py-3 text-xs text-zinc-600"
                    >
                        <span class="flex flex-wrap gap-x-4 gap-y-1">
                            <span>Version {{ versionNumber(version) }}</span>
                            <span
                                >Estimated
                                {{
                                    formatMoneyRange(version.estimated_charge)
                                }}</span
                            >
                            <span
                                :title="
                                    version.charge
                                        ? `$${version.charge.amount}`
                                        : undefined
                                "
                            >
                                Charged
                                {{
                                    version.charge?.formatted ??
                                    (inProgress.includes(version.status)
                                        ? 'when finished'
                                        : '—')
                                }}
                            </span>
                            <span
                                v-if="
                                    version.status === 'succeeded' &&
                                    version.image_url_expires_at
                                "
                                :title="
                                    new Date(
                                        version.image_url_expires_at,
                                    ).toLocaleString()
                                "
                            >
                                {{
                                    describeExpiry(
                                        version.image_url_expires_at,
                                        now,
                                    )
                                }}
                            </span>
                            <span>{{ formatDate(version.created_at) }}</span>
                        </span>
                        <button
                            v-if="version.can_continue"
                            type="button"
                            class="rounded-full border border-zinc-300 px-3 py-1 font-medium text-zinc-700 hover:bg-zinc-100"
                            @click="continueFrom(version)"
                        >
                            Continue from here
                        </button>
                    </div>
                </div>
            </article>

            <p
                v-if="latestFollowUpId"
                class="rounded-xl border border-zinc-200 bg-white px-4 py-3 text-sm text-zinc-600"
            >
                This version has follow-ups.
                <Link
                    :href="`/generations/${latestFollowUpId}`"
                    class="font-medium text-zinc-900 hover:underline"
                >
                    Jump to the latest →
                </Link>
            </p>
        </div>

        <div class="fixed inset-x-0 bottom-0 z-20 lg:left-64">
            <div class="mx-auto max-w-4xl px-6 pb-6">
                <form
                    class="rounded-3xl border border-zinc-200 bg-white shadow-lg"
                    @submit.prevent="send"
                >
                    <div
                        v-if="parent"
                        class="flex items-center justify-between gap-2 px-5 pt-3 text-xs text-zinc-500"
                    >
                        <span>
                            <template v-if="!parent.can_continue">
                                Version {{ versionNumber(parent) }} can't be
                                continued
                                {{
                                    parent.status === 'succeeded'
                                        ? '(its image link expired)'
                                        : 'yet'
                                }}.
                            </template>
                            <template v-else-if="isBranching">
                                Branching from version
                                {{ versionNumber(parent) }}
                            </template>
                            <template v-else
                                >Continuing from version
                                {{ versionNumber(parent) }}</template
                            >
                        </span>
                        <button
                            v-if="isBranching"
                            type="button"
                            class="font-medium text-zinc-600 hover:text-zinc-900"
                            @click="chosenParentId = null"
                        >
                            Use the latest instead
                        </button>
                    </div>
                    <label for="follow-up" class="sr-only"
                        >Describe a change</label
                    >
                    <textarea
                        id="follow-up"
                        ref="textarea"
                        v-model="form.prompt"
                        rows="1"
                        maxlength="4000"
                        placeholder="Describe a change… e.g. make the sky stormier"
                        class="block max-h-48 min-h-12 w-full resize-none bg-transparent px-5 pt-2 pb-1 text-base placeholder:text-zinc-400 focus:outline-none"
                        :disabled="!parent?.can_continue"
                        @keydown="onKeydown"
                    />
                    <div class="flex items-center gap-2 px-3 pb-3">
                        <PillMenu
                            v-model="form.size"
                            label="Size"
                            :options="sizeOptions"
                            open-up
                        />
                        <PillMenu
                            v-model="form.quality"
                            label="Quality"
                            :options="qualityOptions"
                            open-up
                        />
                        <div class="ml-auto flex items-center gap-3">
                            <span
                                class="text-sm text-zinc-500 tabular-nums"
                                aria-live="polite"
                            >
                                <template
                                    v-if="
                                        estimate.response && !estimate.hasErrors
                                    "
                                >
                                    ≈
                                    {{
                                        formatMoneyRange(
                                            estimate.response.charge,
                                        )
                                    }}
                                </template>
                            </span>
                            <button
                                type="submit"
                                class="flex size-9 items-center justify-center rounded-full bg-zinc-900 text-white transition-colors hover:bg-zinc-700 disabled:bg-zinc-200 disabled:text-zinc-400"
                                :disabled="!canSend"
                                aria-label="Generate follow-up"
                            >
                                <svg
                                    v-if="!form.processing"
                                    class="size-4"
                                    viewBox="0 0 20 20"
                                    fill="currentColor"
                                    aria-hidden="true"
                                >
                                    <path
                                        fill-rule="evenodd"
                                        d="M10 17a.75.75 0 0 1-.75-.75V5.56l-3.72 3.72a.75.75 0 1 1-1.06-1.06l5-5a.75.75 0 0 1 1.06 0l5 5a.75.75 0 1 1-1.06 1.06l-3.72-3.72v10.69A.75.75 0 0 1 10 17Z"
                                        clip-rule="evenodd"
                                    />
                                </svg>
                                <span
                                    v-else
                                    class="size-4 animate-spin rounded-full border-2 border-white/40 border-t-white"
                                />
                            </button>
                        </div>
                    </div>
                </form>
                <p
                    v-if="firstError"
                    class="mt-2 px-2 text-sm text-red-600"
                    role="alert"
                >
                    {{ firstError }}
                </p>
                <p
                    v-else-if="limitBlocked"
                    class="mt-2 px-2 text-sm text-amber-800"
                    role="status"
                >
                    {{ limitBlocked }}
                </p>
                <p
                    v-else-if="estimate.response?.affordable === false"
                    class="mt-2 px-2 text-sm text-amber-800"
                    role="status"
                >
                    Not enough credits for this follow-up.
                    <button
                        v-if="page.props.auth.credits?.canBuy"
                        type="button"
                        class="font-medium underline"
                        @click="creditsDialog.show()"
                    >
                        Buy credits
                    </button>
                </p>
            </div>
        </div>
    </AppLayout>
</template>
