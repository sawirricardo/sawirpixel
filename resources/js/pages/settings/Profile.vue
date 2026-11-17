<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';

const props = defineProps<{
    profile: { name: string; email: string; email_verified: boolean };
}>();

const details = useForm({
    name: props.profile.name,
    email: props.profile.email,
});
const password = useForm({
    current_password: '',
    password: '',
    password_confirmation: '',
});

function saveDetails(): void {
    details.patch('/settings/profile', { preserveScroll: true });
}

function savePassword(): void {
    password.put('/settings/password', {
        preserveScroll: true,
        onSuccess: () => password.reset(),
        onError: () =>
            password.reset(
                'current_password',
                'password',
                'password_confirmation',
            ),
    });
}

const inputClass =
    'mt-1 w-full rounded-lg border border-zinc-300 px-3 py-2 text-sm';
</script>

<template>
    <AppLayout>
        <Head title="Your profile" />
        <div class="mx-auto max-w-xl space-y-8">
            <h1 class="text-2xl font-semibold">Your profile</h1>

            <form
                class="space-y-4 rounded-xl border border-zinc-200 bg-white p-5"
                @submit.prevent="saveDetails"
            >
                <h2 class="text-sm font-semibold">Details</h2>
                <div>
                    <label for="profile-name" class="text-sm font-medium"
                        >Name</label
                    >
                    <input
                        id="profile-name"
                        v-model="details.name"
                        type="text"
                        autocomplete="name"
                        required
                        :class="inputClass"
                    />
                    <p
                        v-if="details.errors.name"
                        class="mt-1 text-xs text-red-600"
                    >
                        {{ details.errors.name }}
                    </p>
                </div>
                <div>
                    <label for="profile-email" class="text-sm font-medium"
                        >Email</label
                    >
                    <input
                        id="profile-email"
                        v-model="details.email"
                        type="email"
                        autocomplete="email"
                        required
                        :class="inputClass"
                    />
                    <p
                        v-if="details.errors.email"
                        class="mt-1 text-xs text-red-600"
                    >
                        {{ details.errors.email }}
                    </p>
                    <p
                        v-else-if="!profile.email_verified"
                        class="mt-1 text-xs text-zinc-500"
                    >
                        Not verified.
                    </p>
                </div>
                <button
                    type="submit"
                    :disabled="details.processing || !details.isDirty"
                    class="rounded-lg bg-zinc-900 px-4 py-2 text-sm font-medium text-white hover:bg-zinc-700 disabled:opacity-50"
                >
                    Save
                </button>
            </form>

            <form
                class="space-y-4 rounded-xl border border-zinc-200 bg-white p-5"
                @submit.prevent="savePassword"
            >
                <h2 class="text-sm font-semibold">Password</h2>
                <div>
                    <label for="current-password" class="text-sm font-medium"
                        >Current password</label
                    >
                    <input
                        id="current-password"
                        v-model="password.current_password"
                        type="password"
                        autocomplete="current-password"
                        required
                        :class="inputClass"
                    />
                    <p
                        v-if="password.errors.current_password"
                        class="mt-1 text-xs text-red-600"
                    >
                        {{ password.errors.current_password }}
                    </p>
                </div>
                <div>
                    <label for="new-password" class="text-sm font-medium"
                        >New password</label
                    >
                    <input
                        id="new-password"
                        v-model="password.password"
                        type="password"
                        autocomplete="new-password"
                        required
                        :class="inputClass"
                    />
                    <p
                        v-if="password.errors.password"
                        class="mt-1 text-xs text-red-600"
                    >
                        {{ password.errors.password }}
                    </p>
                </div>
                <div>
                    <label
                        for="new-password-confirmation"
                        class="text-sm font-medium"
                        >Confirm new password</label
                    >
                    <input
                        id="new-password-confirmation"
                        v-model="password.password_confirmation"
                        type="password"
                        autocomplete="new-password"
                        required
                        :class="inputClass"
                    />
                </div>
                <button
                    type="submit"
                    :disabled="password.processing"
                    class="rounded-lg bg-zinc-900 px-4 py-2 text-sm font-medium text-white hover:bg-zinc-700 disabled:opacity-50"
                >
                    Change password
                </button>
            </form>
        </div>
    </AppLayout>
</template>
