<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
</script>

<template>
    <AppLayout>
        <Head title="Create organization" />
        <div class="mx-auto max-w-sm">
            <h1 class="text-2xl font-semibold">Create your organization</h1>
            <p class="mt-1 text-sm text-zinc-600">
                Generations and their costs belong to an organization. You'll be
                its owner.
            </p>
            <Form
                action="/organizations"
                method="post"
                class="mt-6 space-y-4"
                #default="{ errors, processing }"
            >
                <div>
                    <label for="name" class="block text-sm font-medium">
                        Organization name
                    </label>
                    <input
                        id="name"
                        name="name"
                        type="text"
                        required
                        autofocus
                        class="mt-1 w-full rounded-md border border-zinc-300 bg-white px-3 py-2 text-sm"
                    />
                    <p v-if="errors.name" class="mt-1 text-xs text-red-600">
                        {{ errors.name }}
                    </p>
                </div>
                <button
                    type="submit"
                    :disabled="processing"
                    class="w-full rounded-md bg-zinc-900 px-4 py-2 text-sm font-medium text-white hover:bg-zinc-700 disabled:opacity-50"
                >
                    Create organization
                </button>
            </Form>
        </div>
    </AppLayout>
</template>
