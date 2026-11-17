<script setup lang="ts">
import LegalDocument from '@/components/LegalDocument.vue';
import AppLayout from '@/layouts/AppLayout.vue';

/**
 * The text comes from resources/markdown, rendered on the server: the same source agents get when they
 * ask for this page as Markdown.
 */
defineProps<{
    appName: string;
    operator: string;
    contactEmail: string | null;
    updatedAt: string;
    html: string;
}>();
</script>

<template>
    <AppLayout>
        <LegalDocument title="Privacy policy" :updated-at="updatedAt">
            <!-- eslint-disable-next-line vue/no-v-html -- our own Markdown, rendered with HTML escaped -->
            <div v-html="html" />
        </LegalDocument>
    </AppLayout>
</template>
