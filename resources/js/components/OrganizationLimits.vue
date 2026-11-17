<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import type { Money } from '@/types';

export type LimitValues = {
    spend_limit_daily: string | null;
    spend_limit_monthly: string | null;
    generation_limit_daily: number | null;
    generation_limit_monthly: number | null;
    member_spend_limit_daily: string | null;
    member_spend_limit_monthly: string | null;
    member_generation_limit_daily: number | null;
    member_generation_limit_monthly: number | null;
    member_qualities: string[];
    member_sizes: string[];
};

type Usage = { spent: Money; generations: number };

const props = defineProps<{
    limits: {
        values: LimitValues;
        usage: { day: Usage; month: Usage };
        resets: { day: string; month: string };
    };
    canUpdate: boolean;
}>();

const form = useForm({
    ...props.limits.values,
    // Inputs edit text; empty means no limit.
    spend_limit_daily: props.limits.values.spend_limit_daily ?? '',
    spend_limit_monthly: props.limits.values.spend_limit_monthly ?? '',
    generation_limit_daily:
        props.limits.values.generation_limit_daily?.toString() ?? '',
    generation_limit_monthly:
        props.limits.values.generation_limit_monthly?.toString() ?? '',
    member_spend_limit_daily:
        props.limits.values.member_spend_limit_daily ?? '',
    member_spend_limit_monthly:
        props.limits.values.member_spend_limit_monthly ?? '',
    member_generation_limit_daily:
        props.limits.values.member_generation_limit_daily?.toString() ?? '',
    member_generation_limit_monthly:
        props.limits.values.member_generation_limit_monthly?.toString() ?? '',
});

const errors = computed(
    () => form.errors as Record<string, string | undefined>,
);

const rows = [
    {
        title: 'Whole organization',
        note: 'Applies to everyone, including owners and admins.',
        fields: [
            { key: 'spend_limit_daily', label: 'Spend per day', money: true },
            {
                key: 'spend_limit_monthly',
                label: 'Spend per month',
                money: true,
            },
            {
                key: 'generation_limit_daily',
                label: 'Images per day',
                money: false,
            },
            {
                key: 'generation_limit_monthly',
                label: 'Images per month',
                money: false,
            },
        ],
    },
    {
        title: 'Each member',
        note: "Applies to each member who can't change these settings.",
        fields: [
            {
                key: 'member_spend_limit_daily',
                label: 'Spend per day',
                money: true,
            },
            {
                key: 'member_spend_limit_monthly',
                label: 'Spend per month',
                money: true,
            },
            {
                key: 'member_generation_limit_daily',
                label: 'Images per day',
                money: false,
            },
            {
                key: 'member_generation_limit_monthly',
                label: 'Images per month',
                money: false,
            },
        ],
    },
] as const;

const qualities = [
    { value: 'low', label: 'Low' },
    { value: 'medium', label: 'Medium' },
    { value: 'high', label: 'High' },
];
const sizes = [
    { value: '1024x1024', label: 'Square' },
    { value: '1536x1024', label: 'Landscape' },
    { value: '1024x1536', label: 'Portrait' },
];

function display(
    key: (typeof rows)[number]['fields'][number]['key'],
    money: boolean,
): string {
    const value = props.limits.values[key];

    if (value === null) {
        return 'No limit';
    }

    return money ? `$${value}` : `${value}`;
}

const monthResets = computed(() =>
    new Date(props.limits.resets.month).toLocaleDateString(undefined, {
        day: 'numeric',
        month: 'long',
        timeZone: 'UTC',
    }),
);

function save(): void {
    form.transform((data) => {
        const blankToNull = (value: string) =>
            value.trim() === '' ? null : value.trim();

        return {
            ...data,
            spend_limit_daily: blankToNull(data.spend_limit_daily),
            spend_limit_monthly: blankToNull(data.spend_limit_monthly),
            generation_limit_daily: blankToNull(data.generation_limit_daily),
            generation_limit_monthly: blankToNull(
                data.generation_limit_monthly,
            ),
            member_spend_limit_daily: blankToNull(
                data.member_spend_limit_daily,
            ),
            member_spend_limit_monthly: blankToNull(
                data.member_spend_limit_monthly,
            ),
            member_generation_limit_daily: blankToNull(
                data.member_generation_limit_daily,
            ),
            member_generation_limit_monthly: blankToNull(
                data.member_generation_limit_monthly,
            ),
        };
    }).patch('/organization/limits', { preserveScroll: true });
}
</script>

<template>
    <section
        class="mt-6 rounded-xl border border-zinc-200 bg-white p-4"
        aria-labelledby="limits-heading"
    >
        <h2 id="limits-heading" class="text-sm font-semibold">Limits</h2>
        <p class="mt-1 text-sm text-zinc-600">
            Used so far: {{ limits.usage.day.spent.formatted }} and
            {{ limits.usage.day.generations }}
            {{ limits.usage.day.generations === 1 ? 'image' : 'images' }} today,
            {{ limits.usage.month.spent.formatted }} and
            {{ limits.usage.month.generations }}
            {{ limits.usage.month.generations === 1 ? 'image' : 'images' }} this
            month. Days reset at midnight UTC, months on {{ monthResets }}.
            Images in progress count at their maximum cost; failed ones don't
            count.
        </p>

        <form class="mt-4 space-y-5" @submit.prevent="save">
            <fieldset v-for="row in rows" :key="row.title">
                <legend class="text-sm font-medium">{{ row.title }}</legend>
                <p class="text-xs text-zinc-500">{{ row.note }}</p>
                <div class="mt-2 grid grid-cols-2 gap-3 sm:grid-cols-4">
                    <div v-for="field in row.fields" :key="field.key">
                        <label
                            :for="`limit-${field.key}`"
                            class="text-xs text-zinc-600"
                            >{{ field.label }}</label
                        >
                        <template v-if="canUpdate">
                            <div class="relative mt-1">
                                <span
                                    v-if="field.money"
                                    class="pointer-events-none absolute inset-y-0 left-3 flex items-center text-sm text-zinc-400"
                                    >$</span
                                >
                                <input
                                    :id="`limit-${field.key}`"
                                    v-model="form[field.key]"
                                    :inputmode="
                                        field.money ? 'decimal' : 'numeric'
                                    "
                                    placeholder="No limit"
                                    class="w-full rounded-lg border border-zinc-300 py-2 pr-3 text-sm tabular-nums"
                                    :class="field.money ? 'pl-6' : 'pl-3'"
                                />
                            </div>
                            <p
                                v-if="errors[field.key]"
                                class="mt-1 text-xs text-red-600"
                            >
                                {{ errors[field.key] }}
                            </p>
                        </template>
                        <p
                            v-else
                            :id="`limit-${field.key}`"
                            class="mt-1 text-sm tabular-nums"
                        >
                            {{ display(field.key, field.money) }}
                        </p>
                    </div>
                </div>
            </fieldset>

            <fieldset>
                <legend class="text-sm font-medium">
                    What members can use
                </legend>
                <p class="text-xs text-zinc-500">
                    Owners and admins can always use everything.
                </p>
                <div class="mt-2 flex flex-wrap gap-x-8 gap-y-3">
                    <div>
                        <p class="text-xs text-zinc-600">Quality</p>
                        <div class="mt-1 flex gap-3">
                            <label
                                v-for="quality in qualities"
                                :key="quality.value"
                                class="flex items-center gap-1.5 text-sm"
                            >
                                <input
                                    v-model="form.member_qualities"
                                    type="checkbox"
                                    :value="quality.value"
                                    :disabled="!canUpdate"
                                />
                                {{ quality.label }}
                            </label>
                        </div>
                        <p
                            v-if="errors.member_qualities"
                            class="mt-1 text-xs text-red-600"
                        >
                            {{ errors.member_qualities }}
                        </p>
                    </div>
                    <div>
                        <p class="text-xs text-zinc-600">Size</p>
                        <div class="mt-1 flex gap-3">
                            <label
                                v-for="size in sizes"
                                :key="size.value"
                                class="flex items-center gap-1.5 text-sm"
                            >
                                <input
                                    v-model="form.member_sizes"
                                    type="checkbox"
                                    :value="size.value"
                                    :disabled="!canUpdate"
                                />
                                {{ size.label }}
                            </label>
                        </div>
                        <p
                            v-if="errors.member_sizes"
                            class="mt-1 text-xs text-red-600"
                        >
                            {{ errors.member_sizes }}
                        </p>
                    </div>
                </div>
            </fieldset>

            <button
                v-if="canUpdate"
                type="submit"
                :disabled="form.processing || !form.isDirty"
                class="rounded-lg bg-zinc-900 px-4 py-2 text-sm font-medium text-white hover:bg-zinc-700 disabled:opacity-50"
            >
                Save limits
            </button>
        </form>
    </section>
</template>
