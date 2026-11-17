<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';

defineProps<{
    title: string;
    updatedAt: string;
}>();
</script>

<template>
    <article class="mx-auto max-w-3xl">
        <Head :title="title" />
        <h1 class="text-3xl font-semibold">{{ title }}</h1>
        <p class="mt-2 text-sm text-zinc-500">
            Last updated
            {{
                new Date(`${updatedAt}T00:00:00Z`).toLocaleDateString(
                    undefined,
                    { dateStyle: 'long', timeZone: 'UTC' },
                )
            }}
        </p>
        <p
            class="mt-6 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900"
            role="note"
        >
            <strong class="font-medium">This is a demo.</strong>
            {{ $page.props.name }} was built for the PromptEdit assessment.
            Credits are simulated and no real payments are taken.
        </p>
        <div
            class="mt-8 text-[15px] leading-7 text-zinc-700 [&_a]:font-medium [&_a]:text-zinc-900 [&_a]:underline [&_h2]:mt-10 [&_h2]:text-lg [&_h2]:font-semibold [&_h2]:text-zinc-900 [&_li]:mt-1.5 [&_p]:mt-3 [&_strong]:font-medium [&_strong]:text-zinc-900 [&_ul]:mt-3 [&_ul]:list-disc [&_ul]:pl-5"
        >
            <slot />
        </div>
        <p class="mt-12 border-t border-zinc-200 pt-6 text-sm text-zinc-500">
            <Link href="/terms" class="hover:text-zinc-900"
                >Terms of service</Link
            >
            ·
            <Link href="/privacy" class="hover:text-zinc-900"
                >Privacy policy</Link
            >
        </p>
    </article>
</template>
