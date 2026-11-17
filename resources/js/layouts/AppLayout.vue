<script setup lang="ts">
import { Link, router, usePage, usePoll } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import AccessDialog from '@/components/AccessDialog.vue';
import BuyCreditsDialog from '@/components/BuyCreditsDialog.vue';
import HistorySidebar from '@/components/HistorySidebar.vue';
import NotificationBell from '@/components/NotificationBell.vue';
import OrganizationSwitcher from '@/components/OrganizationSwitcher.vue';
import { useAccessDialog } from '@/lib/accessDialog';
import { useCreditsDialog } from '@/lib/creditsDialog';
import type { SharedProps } from '@/types';

const page = usePage<SharedProps>();
const auth = computed(() => page.props.auth);

/**
 * Picks up finished images, invitations and low-credit alerts on whatever page is open. Inertia slows
 * this down while the tab is in the background.
 */
const notificationsPoll = usePoll(
    30000,
    { only: ['notifications', 'recentGenerations'] },
    { autoStart: false },
);

watch(
    () => auth.value.user !== null,
    (loggedIn) =>
        loggedIn ? notificationsPoll.start() : notificationsPoll.stop(),
    { immediate: true },
);
const access = useAccessDialog();
const creditsDialog = useCreditsDialog();

/**
 * The history sidebar belongs to working in an organization; the operator area is global.
 */
const showSidebar = computed(
    () =>
        Boolean(auth.value.user && auth.value.organization) &&
        !page.url.startsWith('/admin'),
);
const sidebarOpen = ref(false);

watch(
    () => page.url,
    () => (sidebarOpen.value = false),
);

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
    <div class="flex min-h-screen bg-zinc-50 text-zinc-900">
        <aside
            v-if="showSidebar"
            class="fixed inset-y-0 left-0 z-40 w-64 -translate-x-full border-r border-zinc-200 bg-zinc-100 transition-transform lg:static lg:translate-x-0"
            :class="{ 'translate-x-0': sidebarOpen }"
        >
            <HistorySidebar :generations="page.props.recentGenerations" />
        </aside>
        <div
            v-if="showSidebar && sidebarOpen"
            class="fixed inset-0 z-30 bg-zinc-900/30 lg:hidden"
            aria-hidden="true"
            @click="sidebarOpen = false"
        />

        <div class="flex min-w-0 flex-1 flex-col">
            <header class="border-b border-zinc-200 bg-white">
                <nav
                    class="mx-auto flex max-w-5xl items-center justify-between gap-4 px-6 py-4"
                >
                    <div class="flex items-center gap-6">
                        <button
                            v-if="showSidebar"
                            type="button"
                            class="-ml-2 rounded-lg p-1.5 text-zinc-600 hover:bg-zinc-100 lg:hidden"
                            :aria-expanded="sidebarOpen"
                            aria-label="Show your generations"
                            @click="sidebarOpen = !sidebarOpen"
                        >
                            <svg
                                class="size-5"
                                viewBox="0 0 20 20"
                                fill="currentColor"
                                aria-hidden="true"
                            >
                                <path
                                    fill-rule="evenodd"
                                    d="M2 4.75A.75.75 0 0 1 2.75 4h14.5a.75.75 0 0 1 0 1.5H2.75A.75.75 0 0 1 2 4.75Zm0 5A.75.75 0 0 1 2.75 9h14.5a.75.75 0 0 1 0 1.5H2.75A.75.75 0 0 1 2 9.75Zm0 5a.75.75 0 0 1 .75-.75h14.5a.75.75 0 0 1 0 1.5H2.75a.75.75 0 0 1-.75-.75Z"
                                    clip-rule="evenodd"
                                />
                            </svg>
                        </button>
                        <Link href="/" class="text-lg font-semibold">
                            {{ page.props.name }}
                        </Link>
                        <Link
                            v-if="auth.user?.is_super_admin"
                            href="/admin/reports/profit"
                            class="text-sm text-zinc-600 hover:text-zinc-900"
                        >
                            Profit report
                        </Link>
                        <Link
                            v-if="auth.user?.is_super_admin"
                            href="/admin/users"
                            class="text-sm text-zinc-600 hover:text-zinc-900"
                        >
                            Users
                        </Link>
                    </div>
                    <div class="flex items-center gap-4 text-sm">
                        <template v-if="auth.user">
                            <button
                                v-if="auth.credits"
                                type="button"
                                class="flex items-center gap-1.5 rounded-full border border-zinc-200 px-3 py-1 font-medium tabular-nums hover:bg-zinc-100"
                                :title="
                                    [
                                        `${auth.organization?.name} credits`,
                                        auth.credits.reserved.amount === '0'
                                            ? ''
                                            : `${auth.credits.reserved.formatted} set aside for images in progress`,
                                        auth.credits.canBuy
                                            ? 'Buy credits'
                                            : 'View credit history',
                                    ]
                                        .filter(Boolean)
                                        .join(' · ')
                                "
                                @click="
                                    auth.credits.canBuy
                                        ? creditsDialog.show()
                                        : router.visit('/credits')
                                "
                            >
                                {{ auth.credits.balance.formatted }}
                                <span
                                    v-if="auth.credits.canBuy"
                                    class="text-zinc-400"
                                    aria-hidden="true"
                                    >+</span
                                >
                                <span class="sr-only">credits</span>
                            </button>
                            <NotificationBell
                                v-if="page.props.notifications"
                                :notifications="page.props.notifications"
                            />
                            <OrganizationSwitcher
                                v-if="auth.organization"
                                :current="auth.organization"
                                :organizations="auth.organizations"
                                :can-view-members="auth.canViewMembers"
                            />
                            <Link
                                href="/settings/profile"
                                class="font-medium hover:underline"
                                title="Your profile"
                            >
                                {{ auth.user.name }}
                            </Link>
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
            <main class="mx-auto w-full max-w-5xl px-6 py-10">
                <div
                    v-if="
                        auth.user &&
                        !auth.user.email_verified &&
                        page.component !== 'auth/VerifyEmail'
                    "
                    class="mb-6 flex flex-wrap items-center justify-between gap-3 rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900"
                    role="status"
                >
                    <span>
                        Verify your email to generate images. We sent a link to
                        {{ auth.user.email }}.
                    </span>
                    <Link
                        href="/email/verification-notification"
                        method="post"
                        as="button"
                        class="font-medium underline hover:no-underline"
                    >
                        Send it again
                    </Link>
                </div>
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

        <BuyCreditsDialog v-model:open="creditsDialog.open.value" />
        <AccessDialog
            v-model:open="access.open.value"
            v-model:step="access.step.value"
        />
    </div>
</template>
