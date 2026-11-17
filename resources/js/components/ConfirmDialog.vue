<script setup lang="ts">
import { nextTick, ref, watch } from 'vue';

defineProps<{
    title: string;
    body?: string;
    confirmLabel: string;
    processing?: boolean;
}>();

const emit = defineEmits<{ confirm: [] }>();

const open = defineModel<boolean>('open', { required: true });
const dialog = ref<HTMLDialogElement | null>(null);
const confirmButton = ref<HTMLButtonElement | null>(null);

watch(open, async (isOpen) => {
    await nextTick();

    if (isOpen && !dialog.value?.open) {
        dialog.value?.showModal();
        confirmButton.value?.focus();
    } else if (!isOpen && dialog.value?.open) {
        dialog.value.close();
    }
});

function closeOnBackdrop(event: MouseEvent): void {
    if (event.target === dialog.value) {
        open.value = false;
    }
}
</script>

<template>
    <dialog
        ref="dialog"
        class="m-auto w-full max-w-sm rounded-3xl bg-white p-0 shadow-xl backdrop:bg-zinc-900/40 backdrop:backdrop-blur-sm"
        aria-labelledby="confirm-dialog-title"
        @close="open = false"
        @click="closeOnBackdrop"
    >
        <div class="p-6">
            <h2 id="confirm-dialog-title" class="text-lg font-semibold">
                {{ title }}
            </h2>
            <p v-if="body" class="mt-1 text-sm text-zinc-600">{{ body }}</p>
            <div class="mt-6 flex justify-end gap-2">
                <button
                    type="button"
                    class="rounded-full px-4 py-2 text-sm font-medium text-zinc-700 hover:bg-zinc-100"
                    @click="open = false"
                >
                    Cancel
                </button>
                <button
                    ref="confirmButton"
                    type="button"
                    class="rounded-full bg-zinc-900 px-4 py-2 text-sm font-medium text-white hover:bg-zinc-700 disabled:opacity-50"
                    :disabled="processing"
                    @click="emit('confirm')"
                >
                    {{ confirmLabel }}
                </button>
            </div>
        </div>
    </dialog>
</template>
