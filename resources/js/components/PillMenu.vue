<script setup lang="ts" generic="T extends string">
import { onBeforeUnmount, onMounted, ref } from 'vue';

export type PillOption<V extends string> = {
    value: V;
    label: string;
    hint?: string;
};

const props = defineProps<{
    label: string;
    options: PillOption<T>[];
}>();

const model = defineModel<T>({ required: true });

const open = ref(false);
const root = ref<HTMLElement | null>(null);

function choose(value: T): void {
    model.value = value;
    open.value = false;
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

const selected = () =>
    props.options.find((option) => option.value === model.value);
</script>

<template>
    <div ref="root" class="relative" @keydown.escape="open = false">
        <button
            type="button"
            class="flex items-center gap-1.5 rounded-full border border-zinc-200 px-3 py-1.5 text-sm text-zinc-700 transition-colors hover:bg-zinc-100"
            :aria-expanded="open"
            aria-haspopup="listbox"
            :aria-label="`${label}: ${selected()?.label ?? model}`"
            @click="open = !open"
        >
            <slot name="icon" />
            <span>{{ selected()?.label ?? model }}</span>
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
        <ul
            v-if="open"
            role="listbox"
            :aria-label="label"
            class="absolute top-full left-0 z-20 mt-2 min-w-44 overflow-hidden rounded-xl border border-zinc-200 bg-white p-1 shadow-lg"
        >
            <li
                class="px-3 pt-1.5 pb-1 text-xs font-medium tracking-wide text-zinc-400 uppercase"
            >
                {{ label }}
            </li>
            <li v-for="option in options" :key="option.value">
                <button
                    type="button"
                    role="option"
                    :aria-selected="option.value === model"
                    class="flex w-full items-center justify-between gap-4 rounded-lg px-3 py-2 text-left text-sm hover:bg-zinc-100"
                    @click="choose(option.value)"
                >
                    <span>
                        <span class="block text-zinc-900">{{
                            option.label
                        }}</span>
                        <span
                            v-if="option.hint"
                            class="block text-xs text-zinc-500"
                        >
                            {{ option.hint }}
                        </span>
                    </span>
                    <svg
                        v-if="option.value === model"
                        class="size-4 text-zinc-900"
                        viewBox="0 0 20 20"
                        fill="currentColor"
                        aria-hidden="true"
                    >
                        <path
                            fill-rule="evenodd"
                            d="M16.7 5.3a1 1 0 0 1 0 1.4l-8 8a1 1 0 0 1-1.4 0l-4-4a1 1 0 1 1 1.4-1.4L8 12.58l7.3-7.3a1 1 0 0 1 1.4 0Z"
                            clip-rule="evenodd"
                        />
                    </svg>
                </button>
            </li>
        </ul>
    </div>
</template>
