<script setup lang="ts">
import { Form, Head, Link } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
</script>

<template>
    <AppLayout>
        <Head title="Forgot password" />
        <div class="mx-auto max-w-sm">
            <h1 class="text-2xl font-semibold">Forgot your password?</h1>
            <p class="mt-1 text-sm text-zinc-600">
                Enter your email and we'll send you a link to choose a new one.
            </p>
            <Form
                action="/forgot-password"
                method="post"
                class="mt-6 space-y-4"
                #default="{ errors, processing }"
            >
                <div>
                    <label for="email" class="block text-sm font-medium"
                        >Email</label
                    >
                    <input
                        id="email"
                        name="email"
                        type="email"
                        autocomplete="email"
                        required
                        autofocus
                        class="mt-1 w-full rounded-md border border-zinc-300 bg-white px-3 py-2 text-sm"
                    />
                    <p v-if="errors.email" class="mt-1 text-xs text-red-600">
                        {{ errors.email }}
                    </p>
                </div>
                <button
                    type="submit"
                    :disabled="processing"
                    class="w-full rounded-md bg-zinc-900 px-4 py-2 text-sm font-medium text-white hover:bg-zinc-700 disabled:opacity-50"
                >
                    Email me a reset link
                </button>
                <p class="text-center text-sm text-zinc-600">
                    <Link href="/login" class="font-medium text-zinc-900"
                        >Back to log in</Link
                    >
                </p>
            </Form>
        </div>
    </AppLayout>
</template>
