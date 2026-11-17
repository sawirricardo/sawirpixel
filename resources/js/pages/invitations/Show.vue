<script setup lang="ts">
import { Head, useForm, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { useAccessDialog } from '@/lib/accessDialog';
import type { SharedProps } from '@/types';

const props = defineProps<{
    acceptUrl: string;
    invitation: {
        email: string;
        organization: string;
        roles: string[];
        permissions: string[];
        inviter: string | null;
        is_pending: boolean;
    } | null;
    emailMatches: boolean;
}>();

const page = usePage<SharedProps>();
const access = useAccessDialog();
const user = computed(() => page.props.auth.user);

const form = useForm({});

function accept(): void {
    form.post(props.acceptUrl);
}

const errors = computed(
    () => page.props.errors as Record<string, string | undefined>,
);
</script>

<template>
    <AppLayout>
        <Head title="Invitation" />
        <div
            class="mx-auto max-w-md rounded-2xl border border-zinc-200 bg-white p-6"
        >
            <template v-if="!invitation || !invitation.is_pending">
                <h1 class="text-xl font-semibold">
                    This invitation isn't valid
                </h1>
                <p class="mt-2 text-sm text-zinc-600">
                    It may have expired, been revoked, or already been used. Ask
                    whoever invited you to send a new one.
                </p>
            </template>

            <template v-else>
                <h1 class="text-xl font-semibold">
                    Join {{ invitation.organization }}
                </h1>
                <p class="mt-2 text-sm text-zinc-600">
                    <template v-if="invitation.inviter">
                        {{ invitation.inviter }} invited
                    </template>
                    <template v-else>You've been invited</template>
                    <strong>{{ invitation.email }}</strong> to join as
                    <strong>{{ invitation.roles.join(', ') }}</strong>.
                </p>
                <p
                    v-if="invitation.permissions.length"
                    class="mt-1 text-sm text-zinc-600"
                >
                    Also: {{ invitation.permissions.join(', ') }}.
                </p>

                <p
                    v-if="errors.invitation"
                    class="mt-4 rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-800"
                    role="alert"
                >
                    {{ errors.invitation }}
                </p>

                <div v-if="!user" class="mt-6 flex gap-3">
                    <button
                        type="button"
                        class="flex-1 rounded-full bg-zinc-900 px-4 py-2 text-center text-sm font-medium text-white hover:bg-zinc-700"
                        @click="access.show('login', { onboarding: false })"
                    >
                        Log in to accept
                    </button>
                    <button
                        type="button"
                        class="flex-1 rounded-full border border-zinc-300 px-4 py-2 text-center text-sm font-medium hover:bg-zinc-50"
                        @click="access.show('register', { onboarding: false })"
                    >
                        Create account
                    </button>
                </div>

                <p
                    v-else-if="!emailMatches"
                    class="mt-6 text-sm text-amber-800"
                >
                    You're logged in as {{ user.email }}. Log in as
                    {{ invitation.email }} to accept this invitation.
                </p>

                <button
                    v-else
                    type="button"
                    :disabled="form.processing"
                    class="mt-6 w-full rounded-full bg-zinc-900 px-4 py-2.5 text-sm font-medium text-white hover:bg-zinc-700 disabled:opacity-50"
                    @click="accept"
                >
                    Accept invitation
                </button>
            </template>
        </div>
    </AppLayout>
</template>
