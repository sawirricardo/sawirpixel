<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';

const props = defineProps<{ token: string; email: string }>();

const form = useForm({
    token: props.token,
    email: props.email,
    password: '',
    password_confirmation: '',
});

function submit(): void {
    form.post('/reset-password', {
        onFinish: () => form.reset('password', 'password_confirmation'),
    });
}
</script>

<template>
    <AppLayout>
        <Head title="Choose a new password" />
        <div class="mx-auto max-w-md">
            <h1 class="text-2xl font-semibold">Choose a new password</h1>
            <form class="mt-6 space-y-5" @submit.prevent="submit">
                <div>
                    <label for="email" class="block text-sm font-medium"
                        >Email</label
                    >
                    <input
                        id="email"
                        v-model="form.email"
                        type="email"
                        autocomplete="email"
                        required
                        class="mt-1 w-full rounded-xl border border-zinc-300 bg-white px-3.5 py-2.5 text-sm"
                    />
                    <p
                        v-if="form.errors.email"
                        class="mt-1 text-xs text-red-600"
                    >
                        {{ form.errors.email }}
                    </p>
                </div>
                <div>
                    <label for="password" class="block text-sm font-medium"
                        >New password</label
                    >
                    <input
                        id="password"
                        v-model="form.password"
                        type="password"
                        autocomplete="new-password"
                        required
                        autofocus
                        class="mt-1 w-full rounded-xl border border-zinc-300 bg-white px-3.5 py-2.5 text-sm"
                    />
                    <p
                        v-if="form.errors.password"
                        class="mt-1 text-xs text-red-600"
                    >
                        {{ form.errors.password }}
                    </p>
                </div>
                <div>
                    <label
                        for="password_confirmation"
                        class="block text-sm font-medium"
                        >Confirm new password</label
                    >
                    <input
                        id="password_confirmation"
                        v-model="form.password_confirmation"
                        type="password"
                        autocomplete="new-password"
                        required
                        class="mt-1 w-full rounded-xl border border-zinc-300 bg-white px-3.5 py-2.5 text-sm"
                    />
                </div>
                <button
                    type="submit"
                    :disabled="form.processing"
                    class="w-full rounded-full bg-zinc-900 px-4 py-2.5 text-sm font-medium text-white hover:bg-zinc-700 disabled:opacity-50"
                >
                    Reset password
                </button>
            </form>
        </div>
    </AppLayout>
</template>
