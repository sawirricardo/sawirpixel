<script setup lang="ts">
import { Form, Head, Link } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';

const fields = [
    { name: 'name', label: 'Name', type: 'text', autocomplete: 'name' },
    { name: 'email', label: 'Email', type: 'email', autocomplete: 'email' },
    {
        name: 'password',
        label: 'Password',
        type: 'password',
        autocomplete: 'new-password',
    },
    {
        name: 'password_confirmation',
        label: 'Confirm password',
        type: 'password',
        autocomplete: 'new-password',
    },
] as const;
</script>

<template>
    <AppLayout>
        <Head title="Create account" />
        <div class="mx-auto max-w-md">
            <h1 class="text-2xl font-semibold">Create an account</h1>
            <Form
                action="/register"
                method="post"
                :reset-on-error="['password', 'password_confirmation']"
                class="mt-6 space-y-5"
                #default="{ errors, processing }"
            >
                <div v-for="field in fields" :key="field.name">
                    <label :for="field.name" class="block text-sm font-medium">
                        {{ field.label }}
                    </label>
                    <input
                        :id="field.name"
                        :name="field.name"
                        :type="field.type"
                        :autocomplete="field.autocomplete"
                        required
                        class="mt-1 w-full rounded-xl border border-zinc-300 bg-white px-3.5 py-2.5 text-sm"
                    />
                    <p
                        v-if="errors[field.name]"
                        class="mt-1 text-xs text-red-600"
                    >
                        {{ errors[field.name] }}
                    </p>
                </div>
                <button
                    type="submit"
                    :disabled="processing"
                    class="w-full rounded-full bg-zinc-900 px-4 py-2.5 text-sm font-medium text-white hover:bg-zinc-700 disabled:opacity-50"
                >
                    Create account
                </button>
                <p class="text-center text-sm text-zinc-600">
                    Already registered?
                    <Link href="/login" class="font-medium text-zinc-900">
                        Log in
                    </Link>
                </p>
            </Form>
        </div>
    </AppLayout>
</template>
