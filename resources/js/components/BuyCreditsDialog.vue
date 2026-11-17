<script setup lang="ts">
import { useForm, usePage } from '@inertiajs/vue3';
import { computed, nextTick, ref, watch } from 'vue';
import type { SharedProps } from '@/types';

const open = defineModel<boolean>('open', { required: true });

const page = usePage<SharedProps>();
const credits = computed(() => page.props.auth.credits);
const dialog = ref<HTMLDialogElement | null>(null);

const packs = [5, 10, 25, 50];

const form = useForm({ pack: 10, purchase_key: '' });

watch(open, async (isOpen) => {
    await nextTick();

    if (isOpen && !dialog.value?.open) {
        // A fresh key per attempt: replays of this attempt can't buy twice.
        form.purchase_key = crypto.randomUUID();
        form.clearErrors();
        dialog.value?.showModal();
    } else if (!isOpen && dialog.value?.open) {
        dialog.value.close();
    }
});

function buy(): void {
    form.post('/credits/purchases', {
        preserveState: true,
        preserveScroll: true,
        onSuccess: () => (open.value = false),
    });
}

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
        aria-labelledby="buy-credits-title"
        @close="open = false"
        @click="closeOnBackdrop"
    >
        <form class="p-6" @submit.prevent="buy">
            <h2 id="buy-credits-title" class="text-lg font-semibold">
                Buy credits
            </h2>
            <p v-if="credits" class="mt-1 text-sm text-zinc-600">
                {{ page.props.auth.organization?.name }} has
                {{ credits.balance.formatted }}
                <template v-if="credits.reserved.amount !== '0'">
                    · {{ credits.reserved.formatted }} set aside for images in
                    progress
                </template>
            </p>

            <fieldset class="mt-5">
                <legend class="sr-only">Amount</legend>
                <div class="grid grid-cols-2 gap-2">
                    <label
                        v-for="pack in packs"
                        :key="pack"
                        class="cursor-pointer rounded-2xl border px-4 py-3 text-center text-lg font-semibold tabular-nums"
                        :class="
                            form.pack === pack
                                ? 'border-zinc-900 bg-zinc-900 text-white'
                                : 'border-zinc-200 hover:border-zinc-400'
                        "
                    >
                        <input
                            v-model="form.pack"
                            type="radio"
                            name="pack"
                            :value="pack"
                            class="sr-only"
                        />
                        ${{ pack }}
                    </label>
                </div>
                <p v-if="form.errors.pack" class="mt-1 text-xs text-red-600">
                    {{ form.errors.pack }}
                </p>
            </fieldset>

            <p class="mt-4 text-xs text-zinc-500">
                This is a demo checkout: no payment is taken, the credits are
                simply added.
            </p>

            <div class="mt-5 flex gap-2">
                <button
                    type="button"
                    class="flex-1 rounded-full border border-zinc-300 px-4 py-2.5 text-sm font-medium hover:bg-zinc-50"
                    @click="open = false"
                >
                    Cancel
                </button>
                <button
                    type="submit"
                    :disabled="form.processing"
                    class="flex-1 rounded-full bg-zinc-900 px-4 py-2.5 text-sm font-medium text-white hover:bg-zinc-700 disabled:opacity-50"
                >
                    Add ${{ form.pack }}
                </button>
            </div>
        </form>
    </dialog>
</template>
