<script setup lang="ts">
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import OrganizationLimits, {
    type LimitValues,
} from '@/components/OrganizationLimits.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import type { Money } from '@/types';

type RoleOption = { id: number; name: string };

const props = defineProps<{
    organization: { id: number; name: string };
    canUpdate: boolean;
    canDelete: boolean;
    creditBalance: Money;
    hasCredits: boolean;
    limits: {
        values: LimitValues;
        usage: {
            day: { spent: Money; generations: number };
            month: { spent: Money; generations: number };
        };
        resets: { day: string; month: string };
    };
    members: {
        id: number;
        name: string;
        email: string;
        role_ids: number[];
        roles: string[];
        permissions: string[];
        is_you: boolean;
    }[];
    invitations: {
        id: number;
        email: string;
        roles: string[];
        permissions: string[];
        expires_at: string;
    }[];
    roles: RoleOption[];
    grantable: {
        roles: RoleOption[];
        permissions: { value: string; label: string }[];
    };
    can: { invite: boolean; remove: boolean; manageRoles: boolean };
}>();

const page = usePage();
const pageErrors = computed(
    () => page.props.errors as Record<string, string | undefined>,
);

const memberRole = props.grantable.roles.find((role) => role.name === 'Member');

const invite = useForm({
    email: '',
    role_ids: memberRole ? [memberRole.id] : ([] as number[]),
    permissions: [] as string[],
});

const showExtraPermissions = ref(false);

const general = useForm({ name: props.organization.name });

const removal = useForm({ confirm_name: '' });

function deleteOrganization(): void {
    removal.delete('/organization/settings', { preserveScroll: true });
}

function saveGeneral(): void {
    general.patch('/organization/settings', { preserveScroll: true });
}

function sendInvite(): void {
    invite.post('/organization/invitations', {
        preserveScroll: true,
        onSuccess: () => {
            invite.reset('email', 'permissions');
            showExtraPermissions.value = false;
        },
    });
}

const savingMemberId = ref<number | null>(null);

function canToggle(member: { role_ids: number[] }, roleId: number): boolean {
    const has = member.role_ids.includes(roleId);

    // A role can always be dropped (the server enforces the owner rules), but never the last one;
    // only grantable roles can be added.
    return has
        ? member.role_ids.length > 1
        : props.grantable.roles.some((role) => role.id === roleId);
}

/**
 * The chips are drawn from the server's data, so a rejected change (say, removing the last owner)
 * simply leaves them as they were.
 */
function toggleRole(
    member: { id: number; role_ids: number[] },
    roleId: number,
): void {
    const roleIds = member.role_ids.includes(roleId)
        ? member.role_ids.filter((id) => id !== roleId)
        : [...member.role_ids, roleId];

    savingMemberId.value = member.id;
    router.patch(
        `/organization/members/${member.id}`,
        { role_ids: roleIds },
        {
            preserveScroll: true,
            onFinish: () => (savingMemberId.value = null),
        },
    );
}

function removeMember(member: { id: number; name: string }): void {
    if (confirm(`Remove ${member.name} from ${props.organization.name}?`)) {
        router.delete(`/organization/members/${member.id}`, {
            preserveScroll: true,
        });
    }
}

function resend(invitationId: number): void {
    router.put(
        `/organization/invitations/${invitationId}`,
        {},
        { preserveScroll: true },
    );
}

function revoke(invitation: { id: number; email: string }): void {
    if (confirm(`Revoke the invitation to ${invitation.email}?`)) {
        router.delete(`/organization/invitations/${invitation.id}`, {
            preserveScroll: true,
        });
    }
}

function expiresIn(date: string): string {
    const days = Math.ceil(
        (new Date(date).getTime() - Date.now()) / 86_400_000,
    );

    return days <= 1 ? 'expires within a day' : `expires in ${days} days`;
}
</script>

<template>
    <AppLayout>
        <Head title="Organization settings" />
        <h1 class="text-2xl font-semibold">{{ organization.name }} settings</h1>

        <section
            class="mt-6 rounded-xl border border-zinc-200 bg-white p-4"
            aria-labelledby="general-heading"
        >
            <h2 id="general-heading" class="text-sm font-semibold">General</h2>
            <form
                v-if="canUpdate"
                class="mt-3 flex flex-wrap items-start gap-2"
                @submit.prevent="saveGeneral"
            >
                <div class="min-w-64 flex-1">
                    <label for="organization-name" class="sr-only"
                        >Organization name</label
                    >
                    <input
                        id="organization-name"
                        v-model="general.name"
                        type="text"
                        required
                        class="w-full rounded-lg border border-zinc-300 px-3 py-2 text-sm"
                    />
                    <p
                        v-if="general.errors.name"
                        class="mt-1 text-xs text-red-600"
                    >
                        {{ general.errors.name }}
                    </p>
                </div>
                <button
                    type="submit"
                    :disabled="general.processing || !general.isDirty"
                    class="rounded-lg bg-zinc-900 px-4 py-2 text-sm font-medium text-white hover:bg-zinc-700 disabled:opacity-50"
                >
                    Save
                </button>
            </form>
            <p v-else class="mt-2 text-sm text-zinc-600">
                {{ organization.name }}
            </p>
            <p class="mt-3 text-sm">
                <Link
                    href="/credits"
                    class="font-medium text-zinc-700 hover:text-zinc-900"
                    >Credits and history →</Link
                >
            </p>
        </section>

        <OrganizationLimits :limits="limits" :can-update="canUpdate" />

        <h2 class="mt-10 text-lg font-semibold">Members</h2>

        <p
            v-if="pageErrors.role_ids || pageErrors.member"
            class="mt-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800"
            role="alert"
        >
            {{ pageErrors.role_ids ?? pageErrors.member }}
        </p>

        <form
            v-if="can.invite"
            class="mt-6 space-y-4 rounded-xl border border-zinc-200 bg-white p-4"
            @submit.prevent="sendInvite"
        >
            <div class="flex flex-wrap items-start gap-2">
                <div class="min-w-64 flex-1">
                    <label for="invite-email" class="sr-only">Email</label>
                    <input
                        id="invite-email"
                        v-model="invite.email"
                        type="email"
                        required
                        placeholder="name@example.com"
                        class="w-full rounded-lg border border-zinc-300 px-3 py-2 text-sm"
                    />
                    <p
                        v-if="invite.errors.email"
                        class="mt-1 text-xs text-red-600"
                    >
                        {{ invite.errors.email }}
                    </p>
                </div>
                <button
                    type="submit"
                    :disabled="
                        invite.processing || invite.role_ids.length === 0
                    "
                    class="rounded-lg bg-zinc-900 px-4 py-2 text-sm font-medium text-white hover:bg-zinc-700 disabled:opacity-50"
                >
                    Send invitation
                </button>
            </div>

            <fieldset>
                <legend class="text-xs font-medium text-zinc-500">Roles</legend>
                <div class="mt-1.5 flex flex-wrap gap-2">
                    <label
                        v-for="role in grantable.roles"
                        :key="role.id"
                        class="flex cursor-pointer items-center gap-2 rounded-full border px-3 py-1 text-sm"
                        :class="
                            invite.role_ids.includes(role.id)
                                ? 'border-zinc-900 bg-zinc-900 text-white'
                                : 'border-zinc-300 hover:border-zinc-500'
                        "
                    >
                        <input
                            v-model="invite.role_ids"
                            type="checkbox"
                            :value="role.id"
                            class="sr-only"
                        />
                        {{ role.name }}
                    </label>
                </div>
                <p
                    v-if="invite.errors.role_ids"
                    class="mt-1 text-xs text-red-600"
                >
                    {{ invite.errors.role_ids }}
                </p>
            </fieldset>

            <div>
                <button
                    type="button"
                    class="text-xs font-medium text-zinc-600 hover:text-zinc-900"
                    :aria-expanded="showExtraPermissions"
                    @click="showExtraPermissions = !showExtraPermissions"
                >
                    {{ showExtraPermissions ? '− Hide' : '+ Add' }} extra
                    permissions
                </button>
                <fieldset v-if="showExtraPermissions" class="mt-2">
                    <legend class="sr-only">Extra permissions</legend>
                    <p class="text-xs text-zinc-500">
                        Granted on top of the roles. You can only grant
                        permissions you have.
                    </p>
                    <div class="mt-2 grid gap-1.5 sm:grid-cols-2">
                        <label
                            v-for="permission in grantable.permissions"
                            :key="permission.value"
                            class="flex items-center gap-2 text-sm"
                        >
                            <input
                                v-model="invite.permissions"
                                type="checkbox"
                                :value="permission.value"
                            />
                            {{ permission.label }}
                        </label>
                    </div>
                    <p
                        v-if="invite.errors.permissions"
                        class="mt-1 text-xs text-red-600"
                    >
                        {{ invite.errors.permissions }}
                    </p>
                </fieldset>
            </div>
        </form>

        <ul
            class="mt-6 divide-y divide-zinc-100 rounded-xl border border-zinc-200 bg-white"
        >
            <li
                v-for="member in members"
                :key="member.id"
                class="flex flex-wrap items-center justify-between gap-3 px-4 py-3"
            >
                <div>
                    <p class="text-sm font-medium">
                        {{ member.name }}
                        <span v-if="member.is_you" class="text-zinc-400"
                            >(you)</span
                        >
                    </p>
                    <p class="text-xs text-zinc-500">{{ member.email }}</p>
                    <p
                        v-if="member.permissions.length"
                        class="mt-0.5 text-xs text-zinc-500"
                    >
                        Also: {{ member.permissions.join(', ') }}
                    </p>
                </div>
                <div class="flex items-center gap-3 text-sm">
                    <div
                        v-if="can.manageRoles"
                        class="flex flex-wrap gap-1.5"
                        role="group"
                        :aria-label="`Roles for ${member.name}`"
                    >
                        <button
                            v-for="role in roles"
                            :key="role.id"
                            type="button"
                            :aria-pressed="member.role_ids.includes(role.id)"
                            :disabled="
                                savingMemberId === member.id ||
                                !canToggle(member, role.id)
                            "
                            class="rounded-full border px-2.5 py-0.5 text-xs disabled:cursor-not-allowed"
                            :class="
                                member.role_ids.includes(role.id)
                                    ? 'border-zinc-900 bg-zinc-900 text-white disabled:opacity-70'
                                    : 'border-zinc-300 text-zinc-600 hover:border-zinc-500 disabled:opacity-40'
                            "
                            @click="toggleRole(member, role.id)"
                        >
                            {{ role.name }}
                        </button>
                    </div>
                    <span v-else class="text-zinc-600">{{
                        member.roles.join(', ') || 'No role'
                    }}</span>
                    <button
                        v-if="can.remove && !member.is_you"
                        type="button"
                        class="text-red-600 hover:text-red-800"
                        @click="removeMember(member)"
                    >
                        Remove
                    </button>
                </div>
            </li>
        </ul>

        <template v-if="can.invite && invitations.length">
            <h2 class="mt-10 text-lg font-semibold">Pending invitations</h2>
            <ul
                class="mt-3 divide-y divide-zinc-100 rounded-xl border border-zinc-200 bg-white"
            >
                <li
                    v-for="invitation in invitations"
                    :key="invitation.id"
                    class="flex flex-wrap items-center justify-between gap-3 px-4 py-3 text-sm"
                >
                    <div>
                        <p class="font-medium">{{ invitation.email }}</p>
                        <p class="text-xs text-zinc-500">
                            {{ invitation.roles.join(', ') }}
                            <template v-if="invitation.permissions.length">
                                + {{ invitation.permissions.join(', ') }}
                            </template>
                            · {{ expiresIn(invitation.expires_at) }}
                        </p>
                    </div>
                    <div class="flex items-center gap-3">
                        <button
                            type="button"
                            class="text-zinc-700 hover:text-zinc-900"
                            @click="resend(invitation.id)"
                        >
                            Resend
                        </button>
                        <button
                            type="button"
                            class="text-red-600 hover:text-red-800"
                            @click="revoke(invitation)"
                        >
                            Revoke
                        </button>
                    </div>
                </li>
            </ul>
        </template>

        <section
            v-if="canDelete"
            class="mt-12 rounded-xl border border-red-200 bg-white p-4"
            aria-labelledby="danger-heading"
        >
            <h2 id="danger-heading" class="text-sm font-semibold text-red-700">
                Delete organization
            </h2>
            <ul class="mt-2 list-disc space-y-1 pl-5 text-sm text-zinc-600">
                <li>
                    Every member loses access, and pending invitations stop
                    working.
                </li>
                <li v-if="hasCredits">
                    The remaining {{ creditBalance.formatted }} in credits is
                    forfeited.
                </li>
                <li>
                    Images and billing records are kept for our records but are
                    no longer visible to anyone.
                </li>
            </ul>
            <form
                class="mt-4 flex flex-wrap items-start gap-2"
                @submit.prevent="deleteOrganization"
            >
                <div class="min-w-64 flex-1">
                    <label for="confirm-name" class="text-xs text-zinc-500">
                        Type
                        <strong class="text-zinc-800">{{
                            organization.name
                        }}</strong>
                        to confirm
                    </label>
                    <input
                        id="confirm-name"
                        v-model="removal.confirm_name"
                        type="text"
                        autocomplete="off"
                        class="mt-1 w-full rounded-lg border border-zinc-300 px-3 py-2 text-sm"
                    />
                    <p
                        v-if="removal.errors.confirm_name"
                        class="mt-1 text-xs text-red-600"
                    >
                        {{ removal.errors.confirm_name }}
                    </p>
                </div>
                <button
                    type="submit"
                    :disabled="
                        removal.processing ||
                        removal.confirm_name !== organization.name
                    "
                    class="mt-5 rounded-lg bg-red-600 px-4 py-2 text-sm font-medium text-white hover:bg-red-700 disabled:opacity-40"
                >
                    Delete organization
                </button>
            </form>
        </section>
    </AppLayout>
</template>
