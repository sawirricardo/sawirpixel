<script setup lang="ts">
import { Link, useForm } from '@inertiajs/vue3';
import { computed, nextTick, ref, watch } from 'vue';
import { useAccessDialog } from '@/lib/accessDialog';

export type AccessStep = 'login' | 'register' | 'organization';

const open = defineModel<boolean>('open', { required: true });
const step = defineModel<AccessStep>('step', { required: true });

const dialog = ref<HTMLDialogElement | null>(null);

const { purpose } = useAccessDialog();
const forGenerating = computed(() => purpose.value === 'generate');

const visitOptions = { preserveState: true, preserveScroll: true } as const;

const login = useForm({ email: '', password: '', remember: true });
const register = useForm({
    name: '',
    email: '',
    password: '',
    password_confirmation: '',
});
const organization = useForm({ name: '' });

watch(open, async (isOpen) => {
    await nextTick();

    if (isOpen && !dialog.value?.open) {
        dialog.value?.showModal();
    } else if (!isOpen && dialog.value?.open) {
        dialog.value.close();
    }
});

function closeOnBackdrop(event: MouseEvent): void {
    if (event.target === dialog.value) {
        open.value = false;
    }
}

function submitLogin(): void {
    login.post('/login', {
        ...visitOptions,
        onFinish: () => login.reset('password'),
    });
}

function submitRegister(): void {
    register.post('/register', {
        ...visitOptions,
        onFinish: () => register.reset('password', 'password_confirmation'),
    });
}

function submitOrganization(): void {
    organization.post('/organizations', visitOptions);
}

const inputClass =
    'mt-1 w-full rounded-xl border border-zinc-200 px-3 py-2 text-sm focus:border-zinc-400 focus:outline-none';
const primaryClass =
    'w-full rounded-full bg-zinc-900 px-4 py-2.5 text-sm font-medium text-white hover:bg-zinc-700 disabled:opacity-50';
</script>

<template>
    <dialog
        ref="dialog"
        class="m-auto w-full max-w-sm rounded-3xl bg-white p-0 shadow-xl backdrop:bg-zinc-900/40 backdrop:backdrop-blur-sm"
        aria-labelledby="access-dialog-title"
        @close="open = false"
        @click="closeOnBackdrop"
    >
        <div class="relative p-6">
            <button
                type="button"
                class="absolute top-4 right-4 rounded-full p-1.5 text-zinc-400 hover:bg-zinc-100 hover:text-zinc-700"
                aria-label="Close"
                @click="open = false"
            >
                <svg
                    class="size-4"
                    viewBox="0 0 20 20"
                    fill="currentColor"
                    aria-hidden="true"
                >
                    <path
                        d="M6.28 5.22a.75.75 0 0 0-1.06 1.06L8.94 10l-3.72 3.72a.75.75 0 1 0 1.06 1.06L10 11.06l3.72 3.72a.75.75 0 1 0 1.06-1.06L11.06 10l3.72-3.72a.75.75 0 0 0-1.06-1.06L10 8.94 6.28 5.22Z"
                    />
                </svg>
            </button>

            <template v-if="step === 'organization'">
                <h2 id="access-dialog-title" class="text-lg font-semibold">
                    Create your organization
                </h2>
                <p class="mt-1 text-sm text-zinc-600">
                    Generations and their costs belong to an organization.
                    You'll be its owner.
                </p>
                <form
                    class="mt-5 space-y-4"
                    @submit.prevent="submitOrganization"
                >
                    <div>
                        <label
                            for="organization-name"
                            class="text-sm font-medium"
                        >
                            Organization name
                        </label>
                        <input
                            id="organization-name"
                            v-model="organization.name"
                            type="text"
                            required
                            autofocus
                            :class="inputClass"
                        />
                        <p
                            v-if="organization.errors.name"
                            class="mt-1 text-xs text-red-600"
                        >
                            {{ organization.errors.name }}
                        </p>
                    </div>
                    <button
                        type="submit"
                        :disabled="organization.processing"
                        :class="primaryClass"
                    >
                        Create and continue
                    </button>
                </form>
            </template>

            <template v-else>
                <h2 id="access-dialog-title" class="text-lg font-semibold">
                    {{ step === 'login' ? 'Log in' : 'Create an account'
                    }}{{ forGenerating ? ' to generate' : '' }}
                </h2>
                <p v-if="forGenerating" class="mt-1 text-sm text-zinc-600">
                    Your prompt will be waiting when you're done.
                </p>

                <div
                    class="mt-5 grid grid-cols-2 rounded-full bg-zinc-100 p-1 text-sm"
                    role="tablist"
                >
                    <button
                        v-for="tab in ['login', 'register'] as const"
                        :key="tab"
                        type="button"
                        role="tab"
                        :aria-selected="step === tab"
                        class="rounded-full py-1.5 font-medium transition-colors"
                        :class="
                            step === tab
                                ? 'bg-white text-zinc-900 shadow-sm'
                                : 'text-zinc-500 hover:text-zinc-800'
                        "
                        @click="step = tab"
                    >
                        {{ tab === 'login' ? 'Log in' : 'Create account' }}
                    </button>
                </div>

                <form
                    v-if="step === 'login'"
                    class="mt-5 space-y-4"
                    @submit.prevent="submitLogin"
                >
                    <div>
                        <label for="login-email" class="text-sm font-medium">
                            Email
                        </label>
                        <input
                            id="login-email"
                            v-model="login.email"
                            type="email"
                            autocomplete="email"
                            required
                            autofocus
                            :class="inputClass"
                        />
                        <p
                            v-if="login.errors.email"
                            class="mt-1 text-xs text-red-600"
                        >
                            {{ login.errors.email }}
                        </p>
                    </div>
                    <div>
                        <label for="login-password" class="text-sm font-medium">
                            Password
                        </label>
                        <input
                            id="login-password"
                            v-model="login.password"
                            type="password"
                            autocomplete="current-password"
                            required
                            :class="inputClass"
                        />
                        <p
                            v-if="login.errors.password"
                            class="mt-1 text-xs text-red-600"
                        >
                            {{ login.errors.password }}
                        </p>
                        <Link
                            href="/forgot-password"
                            class="mt-1 inline-block text-xs text-zinc-500 hover:text-zinc-900"
                            @click="open = false"
                        >
                            Forgot password?
                        </Link>
                    </div>
                    <button
                        type="submit"
                        :disabled="login.processing"
                        :class="primaryClass"
                    >
                        Log in
                    </button>
                </form>

                <form
                    v-else
                    class="mt-5 space-y-4"
                    @submit.prevent="submitRegister"
                >
                    <div>
                        <label for="register-name" class="text-sm font-medium">
                            Name
                        </label>
                        <input
                            id="register-name"
                            v-model="register.name"
                            type="text"
                            autocomplete="name"
                            required
                            autofocus
                            :class="inputClass"
                        />
                        <p
                            v-if="register.errors.name"
                            class="mt-1 text-xs text-red-600"
                        >
                            {{ register.errors.name }}
                        </p>
                    </div>
                    <div>
                        <label for="register-email" class="text-sm font-medium">
                            Email
                        </label>
                        <input
                            id="register-email"
                            v-model="register.email"
                            type="email"
                            autocomplete="email"
                            required
                            :class="inputClass"
                        />
                        <p
                            v-if="register.errors.email"
                            class="mt-1 text-xs text-red-600"
                        >
                            {{ register.errors.email }}
                        </p>
                    </div>
                    <div>
                        <label
                            for="register-password"
                            class="text-sm font-medium"
                        >
                            Password
                        </label>
                        <input
                            id="register-password"
                            v-model="register.password"
                            type="password"
                            autocomplete="new-password"
                            required
                            :class="inputClass"
                        />
                        <p
                            v-if="register.errors.password"
                            class="mt-1 text-xs text-red-600"
                        >
                            {{ register.errors.password }}
                        </p>
                    </div>
                    <div>
                        <label
                            for="register-password-confirmation"
                            class="text-sm font-medium"
                        >
                            Confirm password
                        </label>
                        <input
                            id="register-password-confirmation"
                            v-model="register.password_confirmation"
                            type="password"
                            autocomplete="new-password"
                            required
                            :class="inputClass"
                        />
                    </div>
                    <button
                        type="submit"
                        :disabled="register.processing"
                        :class="primaryClass"
                    >
                        Create account
                    </button>
                </form>
            </template>
        </div>
    </dialog>
</template>
