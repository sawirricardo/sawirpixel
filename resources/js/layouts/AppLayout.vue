<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { computed, watch } from 'vue';
import AccessDialog from '@/components/AccessDialog.vue';
import OrganizationSwitcher from '@/components/OrganizationSwitcher.vue';
import { useAccessDialog } from '@/lib/accessDialog';
import type { SharedProps } from '@/types';

const page = usePage<SharedProps>();
const auth = computed(() => page.props.auth);
const access = useAccessDialog();

/**
 * The dialog's forms keep the current page mounted, so the shared auth props change underneath it:
 * once someone is logged in, continue to creating an organization if they need one, otherwise close.
 */
watch(
    () => [auth.value.user, auth.value.organization] as const,
    ([user, organization]) => {
        if (!access.open.value || !user) {
            return;
        }

        if (!organization && access.onboarding.value) {
            access.step.value = 'organization';
        } else {
            access.open.value = false;
        }
    },
);

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
                    <Link
                        v-if="auth.user?.is_super_admin"
                        href="/admin/reports/profit"
                        class="text-sm text-zinc-600 hover:text-zinc-900"
                    >
                        Profit report
                    </Link>
                </div>
                <div class="flex items-center gap-4 text-sm">
                    <template v-if="auth.user">
                        <OrganizationSwitcher
                            v-if="auth.organization"
                            :current="auth.organization"
                            :organizations="auth.organizations"
                            :can-view-members="auth.canViewMembers"
                        />
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
                        <button
                            type="button"
                            class="text-zinc-600 hover:text-zinc-900"
                            @click="access.show('login')"
                        >
                            Log in
                        </button>
                        <button
                            type="button"
                            class="rounded-md bg-zinc-900 px-3 py-1.5 font-medium text-white hover:bg-zinc-700"
                            @click="access.show('register')"
                        >
                            Create account
                        </button>
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

        <AccessDialog
            v-model:open="access.open.value"
            v-model:step="access.step.value"
        />
    </div>
</template>
