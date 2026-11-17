<script setup lang="ts">
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, reactive, watch } from 'vue';
import AppLayout from '@/layouts/AppLayout.vue';

type RoleOption = { id: number; name: string };

const props = defineProps<{
    organization: { id: number; name: string };
    members: {
        id: number;
        name: string;
        email: string;
        role: RoleOption | null;
        is_you: boolean;
    }[];
    invitations: {
        id: number;
        email: string;
        role: RoleOption | null;
        expires_at: string;
    }[];
    roles: RoleOption[];
    can: { invite: boolean; remove: boolean; manageRoles: boolean };
}>();

const page = usePage();
const pageErrors = computed(
    () => page.props.errors as Record<string, string | undefined>,
);

const memberRole = props.roles.find((role) => role.name === 'Member');

const invite = useForm({
    email: '',
    role_id: memberRole?.id ?? props.roles[0]?.id ?? 0,
});

function sendInvite(): void {
    invite.post('/organization/invitations', {
        preserveScroll: true,
        onSuccess: () => invite.reset('email'),
    });
}

/**
 * The dropdowns follow the server: after a rejected change (e.g. demoting the last owner) they snap
 * back to the role the member actually has.
 */
const selectedRoles = reactive<Record<number, number | undefined>>({});

watch(
    () => props.members,
    (members) => {
        for (const member of members) {
            selectedRoles[member.id] = member.role?.id;
        }
    },
    { immediate: true, deep: true },
);

function changeRole(
    member: { id: number; role: RoleOption | null },
    roleId: number,
): void {
    router.patch(
        `/organization/members/${member.id}`,
        { role_id: roleId },
        {
            preserveScroll: true,
            onError: () => {
                selectedRoles[member.id] = member.role?.id;
            },
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
        <Head title="Members" />
        <h1 class="text-2xl font-semibold">{{ organization.name }} members</h1>

        <p
            v-if="pageErrors.role_id || pageErrors.member"
            class="mt-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800"
            role="alert"
        >
            {{ pageErrors.role_id ?? pageErrors.member }}
        </p>

        <form
            v-if="can.invite"
            class="mt-6 flex flex-wrap items-start gap-2 rounded-xl border border-zinc-200 bg-white p-4"
            @submit.prevent="sendInvite"
        >
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
                <p v-if="invite.errors.email" class="mt-1 text-xs text-red-600">
                    {{ invite.errors.email }}
                </p>
            </div>
            <label for="invite-role" class="sr-only">Role</label>
            <select
                id="invite-role"
                v-model="invite.role_id"
                class="rounded-lg border border-zinc-300 px-3 py-2 text-sm"
            >
                <option v-for="role in roles" :key="role.id" :value="role.id">
                    {{ role.name }}
                </option>
            </select>
            <button
                type="submit"
                :disabled="invite.processing"
                class="rounded-lg bg-zinc-900 px-4 py-2 text-sm font-medium text-white hover:bg-zinc-700 disabled:opacity-50"
            >
                Send invitation
            </button>
            <p v-if="invite.errors.role_id" class="w-full text-xs text-red-600">
                {{ invite.errors.role_id }}
            </p>
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
                </div>
                <div class="flex items-center gap-3 text-sm">
                    <select
                        v-if="can.manageRoles && member.role"
                        v-model.number="selectedRoles[member.id]"
                        :aria-label="`Role for ${member.name}`"
                        class="rounded-lg border border-zinc-300 px-2 py-1"
                        @change="
                            changeRole(member, Number(selectedRoles[member.id]))
                        "
                    >
                        <option
                            v-for="role in roles"
                            :key="role.id"
                            :value="role.id"
                        >
                            {{ role.name }}
                        </option>
                    </select>
                    <span v-else class="text-zinc-600">{{
                        member.role?.name ?? 'No role'
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
                            {{ invitation.role?.name }} ·
                            {{ expiresIn(invitation.expires_at) }}
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
    </AppLayout>
</template>
