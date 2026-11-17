<script setup lang="ts">
import { Form, Head, Link } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
</script>

<template>
    <AppLayout>
        <Head title="Log in" />
        <div class="mx-auto max-w-sm">
            <h1 class="text-2xl font-semibold">Log in</h1>
            <Form
                action="/login"
                method="post"
                :reset-on-error="['password']"
                class="mt-6 space-y-4"
                #default="{ errors, processing }"
            >
                <div>
                    <label for="email" class="block text-sm font-medium">
                        Email
                    </label>
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
                <div>
                    <label for="password" class="block text-sm font-medium">
                        Password
                    </label>
                    <input
                        id="password"
                        name="password"
                        type="password"
                        autocomplete="current-password"
                        required
                        class="mt-1 w-full rounded-md border border-zinc-300 bg-white px-3 py-2 text-sm"
                    />
                    <p v-if="errors.password" class="mt-1 text-xs text-red-600">
                        {{ errors.password }}
                    </p>
                </div>
                <div class="flex justify-end text-sm">
                    <Link
                        href="/forgot-password"
                        class="text-zinc-600 hover:text-zinc-900"
                        >Forgot password?</Link
                    >
                </div>
                <label class="flex items-center gap-2 text-sm">
                    <input type="checkbox" name="remember" value="1" />
                    Remember me
                </label>
                <button
                    type="submit"
                    :disabled="processing"
                    class="w-full rounded-md bg-zinc-900 px-4 py-2 text-sm font-medium text-white hover:bg-zinc-700 disabled:opacity-50"
                >
                    Log in
                </button>
                <p class="text-center text-sm text-zinc-600">
                    No account?
                    <Link href="/register" class="font-medium text-zinc-900">
                        Create one
                    </Link>
                </p>
            </Form>
        </div>
    </AppLayout>
</template>
