<script setup lang="ts">
import { useForm, usePage } from '@inertiajs/vue3';
import { computed, nextTick, ref, watch } from 'vue';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import { relativeTime } from '@/lib/relativeTime';

export type PersonalAccessToken = {
    id: number;
    name: string;
    abilities: string[];
    organization: string | null;
    last_used_at: string | null;
    expires_at: string | null;
    expired: boolean;
    created_at: string | null;
};

export type TokenOptions = {
    abilities: { value: string; description: string }[];
    organizations: { id: number; name: string }[];
    currentOrganizationId: number | null;
    expirationDays: number[];
};

const props = defineProps<{
    tokens: PersonalAccessToken[];
    options: TokenOptions;
}>();

const page = usePage();

/**
 * The secret of a token just created or regenerated. It arrives once, as flash data, so it's copied here
 * and kept until dismissed, even if the page reloads its props in the meantime.
 */
const revealed = ref<{ id: number; plainText: string } | null>(null);
const copied = ref(false);

watch(
    () => page.flash.newToken,
    (value) => {
        if (
            value !== null &&
            typeof value === 'object' &&
            'id' in value &&
            'plainText' in value &&
            typeof value.id === 'number' &&
            typeof value.plainText === 'string'
        ) {
            revealed.value = { id: value.id, plainText: value.plainText };
            copied.value = false;
        }
    },
    { immediate: true },
);

async function copy(): Promise<void> {
    if (!revealed.value) {
        return;
    }

    await navigator.clipboard.writeText(revealed.value.plainText);
    copied.value = true;
}

const expirationChoices = computed(() => [
    ...props.options.expirationDays.map((days) => ({
        value: String(days),
        label: `${days} days`,
    })),
    { value: 'none', label: 'No expiration' },
]);

function expiryHint(expiration: string): string {
    if (expiration === 'none') {
        return "Tokens without an expiration date are riskier: anyone who gets hold of one keeps access until it's deleted.";
    }

    const date = new Date(Date.now() + Number(expiration) * 86_400_000);

    return `The token will expire on ${date.toLocaleDateString(undefined, { dateStyle: 'long' })}.`;
}

const creating = ref(false);
const noteInput = ref<HTMLInputElement | null>(null);

const create = useForm({
    name: '',
    organization_id:
        props.options.currentOrganizationId ??
        props.options.organizations[0]?.id ??
        null,
    abilities: ['generations:read'] as string[],
    expiration: '30',
});

function startCreating(): void {
    creating.value = true;
    void nextTick(() => noteInput.value?.focus());
}

function submitCreate(): void {
    create.post('/settings/tokens', {
        preserveScroll: true,
        onSuccess: () => {
            create.reset();
            creating.value = false;
        },
    });
}

const editingId = ref<number | null>(null);
const edit = useForm({ name: '', abilities: [] as string[] });
const regenerate = useForm({ expiration: '30' });

function startEditing(token: PersonalAccessToken): void {
    editingId.value = token.id;
    edit.defaults({ name: token.name, abilities: [...token.abilities] });
    edit.reset();
    edit.clearErrors();
    regenerate.reset();
}

function submitEdit(token: PersonalAccessToken): void {
    edit.patch(`/settings/tokens/${token.id}`, {
        preserveScroll: true,
        onSuccess: () => (editingId.value = null),
    });
}

const confirmingRegenerate = ref<PersonalAccessToken | null>(null);

function submitRegenerate(): void {
    const token = confirmingRegenerate.value;

    if (!token) {
        return;
    }

    regenerate.post(`/settings/tokens/${token.id}/regenerate`, {
        preserveScroll: true,
        onSuccess: () => {
            confirmingRegenerate.value = null;
            editingId.value = null;
        },
    });
}

const confirmingDelete = ref<PersonalAccessToken | null>(null);
const deleteForm = useForm({});

function submitDelete(): void {
    const token = confirmingDelete.value;

    if (!token) {
        return;
    }

    deleteForm.delete(`/settings/tokens/${token.id}`, {
        preserveScroll: true,
        onSuccess: () => {
            confirmingDelete.value = null;

            if (revealed.value?.id === token.id) {
                revealed.value = null;
            }
        },
    });
}

function lastUsed(token: PersonalAccessToken): string {
    return token.last_used_at
        ? `Last used ${relativeTime(token.last_used_at)}`
        : 'Never used';
}

function expiry(token: PersonalAccessToken): { text: string; tone: string } {
    if (!token.expires_at) {
        return { text: 'No expiration date', tone: 'text-amber-700' };
    }

    const date = new Date(token.expires_at).toLocaleDateString(undefined, {
        dateStyle: 'medium',
    });

    return token.expired
        ? { text: `Expired on ${date}`, tone: 'text-red-700' }
        : { text: `Expires on ${date}`, tone: 'text-zinc-500' };
}

const inputClass =
    'mt-1 w-full rounded-lg border border-zinc-300 px-3 py-2 text-sm';
const errors = computed(
    () => create.errors as Record<string, string | undefined>,
);
const editErrors = computed(
    () => edit.errors as Record<string, string | undefined>,
);
</script>

<template>
    <section
        class="rounded-xl border border-zinc-200 bg-white p-5"
        aria-labelledby="tokens-heading"
    >
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h2 id="tokens-heading" class="text-sm font-semibold">
                    Personal access tokens
                </h2>
                <p class="mt-1 text-sm text-zinc-600">
                    Tokens let scripts and other apps use the
                    {{ page.props.name }} API as you, in one organization, with
                    only the scopes you choose.
                    <a
                        href="/docs/api"
                        class="font-medium text-zinc-900 underline"
                        >API docs</a
                    >
                </p>
            </div>
            <button
                v-if="!creating"
                type="button"
                class="rounded-lg bg-zinc-900 px-3 py-2 text-sm font-medium text-white hover:bg-zinc-700"
                @click="startCreating"
            >
                Generate new token
            </button>
        </div>

        <div
            v-if="revealed"
            class="mt-4 rounded-lg border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-900"
            role="status"
        >
            <p class="font-medium">
                Make sure to copy your token now. You won't be able to see it
                again.
            </p>
            <div class="mt-2 flex items-center gap-2">
                <code
                    class="min-w-0 flex-1 truncate rounded-md border border-emerald-200 bg-white px-3 py-2 font-mono text-xs text-zinc-900"
                    data-token
                    >{{ revealed.plainText }}</code
                >
                <button
                    type="button"
                    class="shrink-0 rounded-md border border-emerald-300 bg-white px-3 py-2 text-xs font-medium hover:bg-emerald-100"
                    @click="copy"
                >
                    {{ copied ? 'Copied' : 'Copy' }}
                </button>
            </div>
            <button
                type="button"
                class="mt-2 text-xs font-medium underline"
                @click="revealed = null"
            >
                I've saved it
            </button>
        </div>

        <form
            v-if="creating"
            class="mt-4 space-y-4 rounded-lg border border-zinc-200 p-4"
            @submit.prevent="submitCreate"
        >
            <h3 class="text-sm font-semibold">New personal access token</h3>
            <div>
                <label for="token-name" class="text-sm font-medium">Note</label>
                <input
                    id="token-name"
                    ref="noteInput"
                    v-model="create.name"
                    type="text"
                    maxlength="100"
                    placeholder="What's this token for?"
                    :class="inputClass"
                />
                <p v-if="errors.name" class="mt-1 text-xs text-red-600">
                    {{ errors.name }}
                </p>
            </div>
            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label for="token-organization" class="text-sm font-medium"
                        >Organization</label
                    >
                    <select
                        id="token-organization"
                        v-model="create.organization_id"
                        :class="inputClass"
                    >
                        <option
                            v-for="organization in options.organizations"
                            :key="organization.id"
                            :value="organization.id"
                        >
                            {{ organization.name }}
                        </option>
                    </select>
                    <p
                        v-if="errors.organization_id"
                        class="mt-1 text-xs text-red-600"
                    >
                        {{ errors.organization_id }}
                    </p>
                </div>
                <div>
                    <label for="token-expiration" class="text-sm font-medium"
                        >Expiration</label
                    >
                    <select
                        id="token-expiration"
                        v-model="create.expiration"
                        :class="inputClass"
                    >
                        <option
                            v-for="choice in expirationChoices"
                            :key="choice.value"
                            :value="choice.value"
                        >
                            {{ choice.label }}
                        </option>
                    </select>
                </div>
            </div>
            <p
                class="text-xs"
                :class="
                    create.expiration === 'none'
                        ? 'text-amber-700'
                        : 'text-zinc-500'
                "
            >
                {{ expiryHint(create.expiration) }}
            </p>
            <fieldset>
                <legend class="text-sm font-medium">Scopes</legend>
                <div class="mt-2 space-y-2">
                    <label
                        v-for="ability in options.abilities"
                        :key="ability.value"
                        class="flex items-start gap-2 text-sm"
                    >
                        <input
                            v-model="create.abilities"
                            type="checkbox"
                            :value="ability.value"
                            class="mt-1"
                        />
                        <span>
                            <code class="font-mono text-xs font-medium">{{
                                ability.value
                            }}</code>
                            <span class="block text-zinc-600">{{
                                ability.description
                            }}</span>
                        </span>
                    </label>
                </div>
                <p v-if="errors.abilities" class="mt-1 text-xs text-red-600">
                    {{ errors.abilities }}
                </p>
            </fieldset>
            <div class="flex gap-2">
                <button
                    type="submit"
                    :disabled="create.processing"
                    class="rounded-lg bg-zinc-900 px-4 py-2 text-sm font-medium text-white hover:bg-zinc-700 disabled:opacity-50"
                >
                    Generate token
                </button>
                <button
                    type="button"
                    class="rounded-lg px-4 py-2 text-sm font-medium text-zinc-700 hover:bg-zinc-100"
                    @click="creating = false"
                >
                    Cancel
                </button>
            </div>
        </form>

        <p
            v-if="tokens.length === 0 && !creating"
            class="mt-4 text-sm text-zinc-500"
        >
            You don't have any tokens yet.
        </p>

        <ul
            v-else
            class="mt-4 divide-y divide-zinc-100 border-t border-zinc-100"
            aria-label="Your tokens"
        >
            <li v-for="token in tokens" :key="token.id" class="py-3">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="min-w-0">
                        <p
                            class="font-medium"
                            :class="{
                                'text-zinc-400 line-through': token.expired,
                            }"
                        >
                            {{ token.name }}
                        </p>
                        <p class="mt-1 flex flex-wrap gap-1">
                            <code
                                v-for="ability in token.abilities"
                                :key="ability"
                                class="rounded bg-zinc-100 px-1.5 py-0.5 font-mono text-[11px] text-zinc-700"
                                >{{ ability }}</code
                            >
                        </p>
                        <p class="mt-1 text-xs text-zinc-500">
                            <span v-if="token.organization"
                                >{{ token.organization }} ·
                            </span>
                            <span
                                :title="
                                    token.last_used_at
                                        ? new Date(
                                              token.last_used_at,
                                          ).toLocaleString()
                                        : undefined
                                "
                                >{{ lastUsed(token) }}</span
                            >
                            ·
                            <span :class="expiry(token).tone">{{
                                expiry(token).text
                            }}</span>
                        </p>
                    </div>
                    <div
                        v-if="editingId !== token.id"
                        class="flex shrink-0 gap-2"
                    >
                        <button
                            type="button"
                            class="rounded-md border border-zinc-300 px-3 py-1 text-xs font-medium hover:bg-zinc-50"
                            @click="startEditing(token)"
                        >
                            Edit
                        </button>
                        <button
                            type="button"
                            class="rounded-md border border-red-200 px-3 py-1 text-xs font-medium text-red-700 hover:bg-red-50"
                            @click="confirmingDelete = token"
                        >
                            Delete
                        </button>
                    </div>
                </div>

                <div
                    v-if="editingId === token.id"
                    class="mt-3 space-y-4 rounded-lg border border-zinc-200 p-4"
                >
                    <form class="space-y-3" @submit.prevent="submitEdit(token)">
                        <div>
                            <label
                                :for="`token-${token.id}-name`"
                                class="text-sm font-medium"
                                >Note</label
                            >
                            <input
                                :id="`token-${token.id}-name`"
                                v-model="edit.name"
                                type="text"
                                maxlength="100"
                                :class="inputClass"
                            />
                            <p
                                v-if="editErrors.name"
                                class="mt-1 text-xs text-red-600"
                            >
                                {{ editErrors.name }}
                            </p>
                        </div>
                        <fieldset>
                            <legend class="text-sm font-medium">Scopes</legend>
                            <div class="mt-2 space-y-2">
                                <label
                                    v-for="ability in options.abilities"
                                    :key="ability.value"
                                    class="flex items-start gap-2 text-sm"
                                >
                                    <input
                                        v-model="edit.abilities"
                                        type="checkbox"
                                        :value="ability.value"
                                        class="mt-1"
                                    />
                                    <span>
                                        <code
                                            class="font-mono text-xs font-medium"
                                            >{{ ability.value }}</code
                                        >
                                        <span class="block text-zinc-600">{{
                                            ability.description
                                        }}</span>
                                    </span>
                                </label>
                            </div>
                            <p
                                v-if="editErrors.abilities"
                                class="mt-1 text-xs text-red-600"
                            >
                                {{ editErrors.abilities }}
                            </p>
                        </fieldset>
                        <div class="flex gap-2">
                            <button
                                type="submit"
                                :disabled="edit.processing || !edit.isDirty"
                                class="rounded-lg bg-zinc-900 px-4 py-2 text-sm font-medium text-white hover:bg-zinc-700 disabled:opacity-50"
                            >
                                Save changes
                            </button>
                            <button
                                type="button"
                                class="rounded-lg px-4 py-2 text-sm font-medium text-zinc-700 hover:bg-zinc-100"
                                @click="editingId = null"
                            >
                                Cancel
                            </button>
                        </div>
                    </form>
                    <div class="border-t border-zinc-100 pt-3">
                        <p class="text-sm font-medium">Regenerate token</p>
                        <p class="text-xs text-zinc-500">
                            Get a new secret if this one leaked or expired. The
                            current secret stops working immediately.
                        </p>
                        <div class="mt-2 flex flex-wrap items-center gap-2">
                            <label
                                :for="`token-${token.id}-expiration`"
                                class="sr-only"
                                >New expiration</label
                            >
                            <select
                                :id="`token-${token.id}-expiration`"
                                v-model="regenerate.expiration"
                                class="rounded-lg border border-zinc-300 px-3 py-1.5 text-sm"
                            >
                                <option
                                    v-for="choice in expirationChoices"
                                    :key="choice.value"
                                    :value="choice.value"
                                >
                                    {{ choice.label }}
                                </option>
                            </select>
                            <button
                                type="button"
                                class="rounded-lg border border-zinc-300 px-3 py-1.5 text-sm font-medium hover:bg-zinc-50"
                                @click="confirmingRegenerate = token"
                            >
                                Regenerate
                            </button>
                        </div>
                    </div>
                </div>
            </li>
        </ul>

        <ConfirmDialog
            :open="confirmingDelete !== null"
            title="Delete this token?"
            :body="`Anything using “${confirmingDelete?.name ?? ''}” will no longer be able to access the API. This can't be undone.`"
            confirm-label="Delete token"
            :processing="deleteForm.processing"
            @update:open="(open) => !open && (confirmingDelete = null)"
            @confirm="submitDelete"
        />
        <ConfirmDialog
            :open="confirmingRegenerate !== null"
            title="Regenerate this token?"
            :body="`“${confirmingRegenerate?.name ?? ''}” gets a new secret and its current one stops working immediately. Update anything that uses it.`"
            confirm-label="Regenerate token"
            :processing="regenerate.processing"
            @update:open="(open) => !open && (confirmingRegenerate = null)"
            @confirm="submitRegenerate"
        />
    </section>
</template>
