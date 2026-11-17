<script setup lang="ts">
import { Head, router, useForm, useHttp, usePage } from '@inertiajs/vue3';
import {
    computed,
    nextTick,
    onBeforeUnmount,
    onMounted,
    ref,
    watch,
} from 'vue';
import PillMenu from '@/components/PillMenu.vue';
import type { PillOption } from '@/components/PillMenu.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { useAccessDialog } from '@/lib/accessDialog';
import { useCreditsDialog } from '@/lib/creditsDialog';
import { formatMoneyRange } from '@/lib/money';
import type { CostEstimate, SharedProps } from '@/types';

const props = defineProps<{
    sizes: string[];
    qualities: string[];
    maxPromptLength: number;
    maxReferenceImages: number;
    canGenerate: boolean;
}>();

const page = usePage<SharedProps>();
const auth = computed(() => page.props.auth);

const DRAFT_KEY = 'generate-draft';

const sizeOptions = computed<PillOption<string>[]>(() =>
    props.sizes.map((size) => ({
        value: size,
        label:
            {
                '1024x1024': 'Square',
                '1536x1024': 'Landscape',
                '1024x1536': 'Portrait',
            }[size] ?? size,
        hint: size.replace('x', ' × '),
    })),
);

const qualityOptions = computed<PillOption<string>[]>(() =>
    props.qualities.map((quality) => ({
        value: quality,
        label: quality.charAt(0).toUpperCase() + quality.slice(1),
        hint: {
            low: 'Fastest, cheapest',
            medium: 'Balanced',
            high: 'Most detail',
        }[quality],
    })),
);

const form = useForm({
    prompt: '',
    size: props.sizes[0] ?? '1024x1024',
    quality: 'medium',
    reference_images: [] as string[],
});

const textarea = ref<HTMLTextAreaElement | null>(null);
const showReferenceInput = ref(false);
const referenceDraft = ref('');
const referenceDraftError = ref<string | null>(null);
const access = useAccessDialog();
const creditsDialog = useCreditsDialog();

/**
 * True once the estimate says the organization's balance can't cover the most this could cost.
 */
const cannotAfford = computed(
    () =>
        props.canGenerate &&
        estimate.response?.affordable === false &&
        !estimate.hasErrors,
);
const noPermission = ref(false);
const readyMessage = ref<string | null>(null);

/**
 * When the access dialog that a blocked "send" opened closes, say whether the user can now generate.
 */
watch(access.open, (isOpen) => {
    if (isOpen || access.purpose.value !== 'generate') {
        return;
    }

    access.purpose.value = null;

    if (props.canGenerate) {
        readyMessage.value = "You're all set. Press send to generate.";
        void nextTick(() => textarea.value?.focus());
    } else if (auth.value.user && auth.value.organization) {
        noPermission.value = true;
    }
});

function autoGrow(): void {
    const element = textarea.value;

    if (element) {
        element.style.height = 'auto';
        element.style.height = `${Math.min(element.scrollHeight, 320)}px`;
    }
}

onMounted(() => {
    const saved = sessionStorage.getItem(DRAFT_KEY);

    if (saved) {
        try {
            const draft = JSON.parse(saved) as Partial<typeof form>;
            form.prompt = typeof draft.prompt === 'string' ? draft.prompt : '';
            form.size = props.sizes.includes(String(draft.size))
                ? String(draft.size)
                : form.size;
            form.quality = props.qualities.includes(String(draft.quality))
                ? String(draft.quality)
                : form.quality;
            form.reference_images = Array.isArray(draft.reference_images)
                ? draft.reference_images.filter(
                      (url): url is string => typeof url === 'string',
                  )
                : [];
        } catch {
            sessionStorage.removeItem(DRAFT_KEY);
        }
    }

    void nextTick(autoGrow);
    textarea.value?.focus();
});

watch(
    () => [form.prompt, form.size, form.quality, [...form.reference_images]],
    () =>
        sessionStorage.setItem(
            DRAFT_KEY,
            JSON.stringify({
                prompt: form.prompt,
                size: form.size,
                quality: form.quality,
                reference_images: form.reference_images,
            }),
        ),
);

const estimate = useHttp<
    {
        prompt: string;
        size: string;
        quality: string;
        reference_images: string[];
    },
    CostEstimate
>({ prompt: '', size: form.size, quality: form.quality, reference_images: [] });

let debounce: ReturnType<typeof setTimeout> | undefined;

watch(
    // The balance is watched too: affordability depends on it, e.g. right after buying credits.
    () => [
        form.prompt,
        form.size,
        form.quality,
        [...form.reference_images],
        auth.value.credits?.balance.amount,
        auth.value.organization?.id,
    ],
    () => {
        clearTimeout(debounce);
        debounce = setTimeout(() => {
            estimate.cancel();
            estimate.prompt = form.prompt;
            estimate.size = form.size;
            estimate.quality = form.quality;
            estimate.reference_images = [...form.reference_images];
            void estimate.post('/generations/estimate');
        }, 300);
    },
    { immediate: true },
);

onBeforeUnmount(() => clearTimeout(debounce));

/**
 * "/" jumps to the prompt from anywhere on the page, unless the user is already typing somewhere.
 */
function focusPromptShortcut(event: KeyboardEvent): void {
    const target = event.target as HTMLElement | null;
    const isTyping =
        target?.isContentEditable ||
        ['INPUT', 'TEXTAREA', 'SELECT'].includes(target?.tagName ?? '');

    if (
        event.key !== '/' ||
        isTyping ||
        event.metaKey ||
        event.ctrlKey ||
        access.open.value
    ) {
        return;
    }

    event.preventDefault();
    textarea.value?.focus();
}

onMounted(() => document.addEventListener('keydown', focusPromptShortcut));
onBeforeUnmount(() =>
    document.removeEventListener('keydown', focusPromptShortcut),
);

const canAddReference = computed(
    () => form.reference_images.length < props.maxReferenceImages,
);

function addReference(): void {
    const url = referenceDraft.value.trim();

    if (!/^https?:\/\/\S+$/i.test(url)) {
        referenceDraftError.value = 'Enter an http(s) image URL.';

        return;
    }

    if (form.reference_images.includes(url)) {
        referenceDraftError.value = 'That image is already attached.';

        return;
    }

    form.reference_images.push(url);
    referenceDraft.value = '';
    referenceDraftError.value = null;
    showReferenceInput.value = false;
    textarea.value?.focus();
}

/**
 * Abandon the URL being typed: close the field and forget the draft.
 */
function cancelReference(): void {
    referenceDraft.value = '';
    referenceDraftError.value = null;
    showReferenceInput.value = false;
    textarea.value?.focus();
}

const draftPreviewUrl = computed(() => {
    const url = referenceDraft.value.trim();

    return /^https?:\/\/\S+$/i.test(url) ? url : null;
});

function removeReference(index: number): void {
    form.reference_images.splice(index, 1);
}

function referenceLabel(url: string): string {
    try {
        const { hostname, pathname } = new URL(url);
        const file = pathname.split('/').filter(Boolean).pop();

        return file ? `${hostname}/…/${file}` : hostname;
    } catch {
        return url;
    }
}

const errors = computed(
    () => form.errors as Record<string, string | undefined>,
);
const firstError = computed(
    () =>
        errors.value.credits ??
        errors.value.prompt ??
        errors.value.size ??
        errors.value.quality ??
        errors.value.reference_images ??
        Object.entries(errors.value).find(([key]) =>
            key.startsWith('reference_images.'),
        )?.[1],
);

const canSubmit = computed(() => form.prompt.trim() !== '' && !form.processing);

function submit(): void {
    if (!canSubmit.value) {
        return;
    }

    if (!props.canGenerate) {
        if (auth.value.user && auth.value.organization) {
            noPermission.value = true;
        } else {
            access.show(auth.value.user ? 'organization' : 'login', {
                purpose: 'generate',
            });
        }

        return;
    }

    if (!page.props.auth.user?.email_verified) {
        router.visit('/email/verify');

        return;
    }

    if (cannotAfford.value) {
        if (page.props.auth.credits?.canBuy) {
            creditsDialog.show();
        }

        return;
    }

    readyMessage.value = null;

    form.post('/generations', {
        preserveScroll: true,
        onSuccess: () => sessionStorage.removeItem(DRAFT_KEY),
    });
}

function onKeydown(event: KeyboardEvent): void {
    if (event.key === 'Enter' && !event.shiftKey && !event.isComposing) {
        event.preventDefault();
        submit();
    }
}
</script>

<template>
    <AppLayout>
        <Head title="Generate" />
        <div
            class="mx-auto flex min-h-[70vh] max-w-3xl flex-col justify-center"
        >
            <h1 class="mb-8 text-center text-3xl font-semibold tracking-tight">
                What do you want to create?
            </h1>

            <form
                class="rounded-3xl border border-zinc-200 bg-white shadow-sm transition-shadow focus-within:shadow-md"
                @submit.prevent="submit"
            >
                <ul
                    v-if="form.reference_images.length"
                    class="flex flex-wrap gap-2 px-4 pt-4"
                    aria-label="Reference images"
                >
                    <li
                        v-for="(url, index) in form.reference_images"
                        :key="url"
                        class="flex items-center gap-2 rounded-xl border border-zinc-200 bg-zinc-50 py-1 pr-1 pl-1 text-xs text-zinc-700"
                        :class="{
                            'border-red-300 bg-red-50':
                                errors[`reference_images.${index}`],
                        }"
                    >
                        <img
                            :src="url"
                            alt=""
                            class="size-8 rounded-lg bg-zinc-200 object-cover"
                            @error="
                                (
                                    $event.target as HTMLImageElement
                                ).style.visibility = 'hidden'
                            "
                        />
                        <span class="max-w-48 truncate" :title="url">
                            {{ referenceLabel(url) }}
                        </span>
                        <button
                            type="button"
                            class="rounded-full bg-zinc-200/70 p-1 text-zinc-600 hover:bg-zinc-300 hover:text-zinc-900"
                            title="Remove this reference image"
                            :aria-label="`Remove reference image ${index + 1}`"
                            @click="removeReference(index)"
                        >
                            <svg
                                class="size-3.5"
                                viewBox="0 0 20 20"
                                fill="currentColor"
                                aria-hidden="true"
                            >
                                <path
                                    d="M6.28 5.22a.75.75 0 0 0-1.06 1.06L8.94 10l-3.72 3.72a.75.75 0 1 0 1.06 1.06L10 11.06l3.72 3.72a.75.75 0 1 0 1.06-1.06L11.06 10l3.72-3.72a.75.75 0 0 0-1.06-1.06L10 8.94 6.28 5.22Z"
                                />
                            </svg>
                        </button>
                    </li>
                </ul>

                <label for="prompt" class="sr-only">Prompt</label>
                <textarea
                    id="prompt"
                    ref="textarea"
                    v-model="form.prompt"
                    rows="1"
                    :maxlength="maxPromptLength"
                    placeholder="Describe an image… e.g. A lighthouse on a cliff at dawn, oil painting"
                    class="block max-h-80 min-h-14 w-full resize-none bg-transparent px-5 pt-4 pb-2 text-base placeholder:text-zinc-400 focus:outline-none"
                    @input="autoGrow"
                    @keydown="onKeydown"
                />

                <div v-if="showReferenceInput" class="px-4 pb-2">
                    <div class="flex items-center gap-2">
                        <img
                            v-if="draftPreviewUrl"
                            :src="draftPreviewUrl"
                            alt="Preview of the image URL"
                            class="size-9 shrink-0 rounded-lg bg-zinc-100 object-cover"
                            @error="
                                (
                                    $event.target as HTMLImageElement
                                ).style.visibility = 'hidden'
                            "
                            @load="
                                (
                                    $event.target as HTMLImageElement
                                ).style.visibility = 'visible'
                            "
                        />
                        <input
                            v-model="referenceDraft"
                            type="url"
                            autofocus
                            placeholder="Paste an image URL (https://…)"
                            class="w-full rounded-xl border border-zinc-200 px-3 py-1.5 text-sm focus:border-zinc-400 focus:outline-none"
                            @keydown.enter.prevent="addReference"
                            @keydown.escape="cancelReference"
                        />
                        <button
                            type="button"
                            class="rounded-xl bg-zinc-900 px-3 text-sm font-medium text-white hover:bg-zinc-700"
                            @click="addReference"
                        >
                            Add
                            <kbd class="ml-1 font-sans text-xs opacity-60"
                                >↵</kbd
                            >
                        </button>
                        <button
                            type="button"
                            class="rounded-xl border border-zinc-200 px-3 text-sm font-medium text-zinc-700 hover:bg-zinc-100"
                            @click="cancelReference"
                        >
                            Cancel
                            <kbd class="ml-1 font-sans text-xs opacity-60"
                                >Esc</kbd
                            >
                        </button>
                    </div>
                    <p
                        v-if="referenceDraftError"
                        class="mt-1 text-xs text-red-600"
                    >
                        {{ referenceDraftError }}
                    </p>
                </div>

                <div class="flex items-center gap-2 px-3 pb-3">
                    <button
                        type="button"
                        class="flex size-8 items-center justify-center rounded-full border border-zinc-200 text-zinc-600 transition-colors hover:bg-zinc-100 disabled:opacity-40"
                        :disabled="!canAddReference"
                        :title="
                            canAddReference
                                ? 'Add a reference image URL'
                                : `Up to ${maxReferenceImages} reference images`
                        "
                        aria-label="Add a reference image URL"
                        @click="
                            showReferenceInput
                                ? cancelReference()
                                : (showReferenceInput = true)
                        "
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
                    </button>

                    <PillMenu
                        v-model="form.size"
                        label="Size"
                        :options="sizeOptions"
                    >
                        <template #icon>
                            <span
                                class="inline-block rounded-[3px] border-[1.5px] border-current"
                                :class="{
                                    'h-3 w-3': form.size === '1024x1024',
                                    'h-2.5 w-3.5': form.size === '1536x1024',
                                    'h-3.5 w-2.5': form.size === '1024x1536',
                                }"
                                aria-hidden="true"
                            />
                        </template>
                    </PillMenu>

                    <PillMenu
                        v-model="form.quality"
                        label="Quality"
                        :options="qualityOptions"
                    >
                        <template #icon>
                            <svg
                                class="size-3.5"
                                viewBox="0 0 20 20"
                                fill="currentColor"
                                aria-hidden="true"
                            >
                                <path
                                    d="M10 1.5a.75.75 0 0 1 .7.48l1.6 4.17 4.17 1.6a.75.75 0 0 1 0 1.4l-4.17 1.6-1.6 4.17a.75.75 0 0 1-1.4 0l-1.6-4.17-4.17-1.6a.75.75 0 0 1 0-1.4l4.17-1.6 1.6-4.17A.75.75 0 0 1 10 1.5Z"
                                />
                            </svg>
                        </template>
                    </PillMenu>

                    <div class="ml-auto flex items-center gap-3">
                        <span
                            class="text-sm text-zinc-500 tabular-nums"
                            aria-live="polite"
                            :title="
                                estimate.response
                                    ? `Prompt ${estimate.response.tokens.input_text.min}–${estimate.response.tokens.input_text.max} tokens · output ${estimate.response.tokens.output.max} · reasoning ${estimate.response.tokens.reasoning.min}–${estimate.response.tokens.reasoning.max}`
                                    : undefined
                            "
                        >
                            <template
                                v-if="estimate.response && !estimate.hasErrors"
                            >
                                ≈
                                {{ formatMoneyRange(estimate.response.charge) }}
                            </template>
                            <span
                                v-else
                                class="inline-block h-4 w-20 animate-pulse rounded bg-zinc-100"
                            />
                        </span>

                        <button
                            type="submit"
                            class="flex size-9 items-center justify-center rounded-full bg-zinc-900 text-white transition-colors hover:bg-zinc-700 disabled:bg-zinc-200 disabled:text-zinc-400"
                            :disabled="!canSubmit"
                            aria-label="Generate"
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
                class="mt-3 px-2 text-sm text-red-600"
                role="alert"
            >
                {{ firstError }}
            </p>

            <div
                v-if="cannotAfford && estimate.response"
                class="mt-4 flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-amber-200 bg-amber-50 px-5 py-3 text-sm text-amber-900"
                role="status"
            >
                <span>
                    This could cost up to
                    {{ estimate.response.charge.max.formatted }}, but
                    {{ auth.organization?.name }} has
                    {{ page.props.auth.credits?.balance.formatted }} in credits.
                    <template v-if="!page.props.auth.credits?.canBuy">
                        Ask an owner or admin to buy credits.
                    </template>
                </span>
                <button
                    v-if="page.props.auth.credits?.canBuy"
                    type="button"
                    class="rounded-full bg-zinc-900 px-4 py-1.5 font-medium text-white hover:bg-zinc-700"
                    @click="creditsDialog.show()"
                >
                    Buy credits
                </button>
            </div>

            <p
                v-if="readyMessage"
                class="mt-3 px-2 text-sm text-emerald-700"
                role="status"
            >
                {{ readyMessage }}
            </p>

            <p
                v-if="noPermission"
                class="mt-4 rounded-2xl border border-amber-200 bg-amber-50 px-5 py-4 text-sm text-amber-800"
                role="status"
            >
                Your role in {{ auth.organization?.name }} doesn't allow
                generating images. Ask an admin for access.
            </p>

            <p class="mt-4 text-center text-xs text-zinc-400">
                <kbd class="font-sans text-zinc-500">↵</kbd> to generate ·
                <kbd class="font-sans text-zinc-500">Shift ↵</kbd> for a new
                line · <kbd class="font-sans text-zinc-500">/</kbd> to jump to
                the prompt
            </p>
            <p class="mt-1 text-center text-xs text-zinc-400">
                The price is an estimate: it's set once the image is finished,
                and failed generations are free.
            </p>
        </div>
    </AppLayout>
</template>
