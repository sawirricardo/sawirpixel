<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';

defineProps<{ email: string }>();
</script>

<template>
    <AppLayout>
        <Head title="Verify your email" />
        <div
            class="mx-auto max-w-md rounded-2xl border border-zinc-200 bg-white p-6"
        >
            <h1 class="text-xl font-semibold">Verify your email</h1>
            <p class="mt-2 text-sm text-zinc-600">
                We sent a link to <strong>{{ email }}</strong>. Open it to
                verify your address; you need a verified email to generate
                images, buy credits or join an organization.
            </p>
            <Form
                action="/email/verification-notification"
                method="post"
                class="mt-6"
                #default="{ processing }"
            >
                <button
                    type="submit"
                    :disabled="processing"
                    class="w-full rounded-full bg-zinc-900 px-4 py-2.5 text-sm font-medium text-white hover:bg-zinc-700 disabled:opacity-50"
                >
                    Send the link again
                </button>
            </Form>
            <p class="mt-3 text-center text-xs text-zinc-500">
                Wrong address? Change it in your profile.
            </p>
        </div>
    </AppLayout>
</template>
