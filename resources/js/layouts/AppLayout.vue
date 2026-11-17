<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import type { SharedProps } from '@/types';

const page = usePage<SharedProps>();
const auth = computed(() => page.props.auth);
const success = computed(() => {
    const message = page.flash.success;

    return typeof message === 'string' ? message : null;
});
</script>

<template>
    <div class="min-h-screen bg-zinc-50 text-zinc-900">
        <header class="border-b border-zinc-200 bg-white">
            <nav
                class="mx-auto flex max-w-5xl items-center justify-between gap-4 px-6 py-4"
            >
                <div class="flex items-center gap-6">
                    <Link href="/" class="text-lg font-semibold">
                        {{ page.props.name }}
                    </Link>
                    <Link
                        v-if="auth.user && auth.organization"
                        href="/generations"
                        class="text-sm text-zinc-600 hover:text-zinc-900"
                    >
                        History
                    </Link>
                </div>
                <div class="flex items-center gap-4 text-sm">
                    <template v-if="auth.user">
                        <span v-if="auth.organization" class="text-zinc-500">
                            {{ auth.organization.name }}
                        </span>
                        <span class="font-medium">{{ auth.user.name }}</span>
                        <Link
                            href="/logout"
                            method="post"
                            as="button"
                            class="text-zinc-600 hover:text-zinc-900"
                        >
                            Log out
                        </Link>
                    </template>
                    <template v-else>
                        <Link
                            href="/login"
                            class="text-zinc-600 hover:text-zinc-900"
                        >
                            Log in
                        </Link>
                        <Link
                            href="/register"
                            class="rounded-md bg-zinc-900 px-3 py-1.5 font-medium text-white hover:bg-zinc-700"
                        >
                            Create account
                        </Link>
                    </template>
                </div>
            </nav>
        </header>
        <main class="mx-auto max-w-5xl px-6 py-10">
            <p
                v-if="success"
                class="mb-6 rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800"
                role="status"
            >
                {{ success }}
            </p>
            <slot />
        </main>
    </div>
</template>
