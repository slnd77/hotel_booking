<script setup lang="ts">
import admin from '@/routes/admin';
import { useForm } from '@inertiajs/vue3';

export type StayTimeChargeTier = {
    time: string;
    type: 'percent' | 'fixed';
    value: number;
};
type Side = { enabled: boolean; tiers: StayTimeChargeTier[] };

export type StayTimeChargeSettings = {
    early_check_in: Side;
    late_check_out: Side;
    grace_minutes: number;
    tax_rate: number;
    sac_hsn_code: string;
};

const props = defineProps<{
    propertyId: number;
    settings: StayTimeChargeSettings;
    standardTimes: { check_in: string; check_out: string };
}>();

const form = useForm<StayTimeChargeSettings>(
    JSON.parse(JSON.stringify(props.settings)),
);

const sides: {
    key: 'early_check_in' | 'late_check_out';
    title: string;
    help: string;
    tierLabel: string;
    example: StayTimeChargeTier;
}[] = [
    {
        key: 'early_check_in',
        title: 'Early check-in',
        help: `Guests arriving on their arrival date before the check-in time (${props.standardTimes.check_in}). The earliest tier the arrival is before applies.`,
        tierLabel: 'Arriving before',
        example: { time: '09:00', type: 'percent', value: 50 },
    },
    {
        key: 'late_check_out',
        title: 'Late check-out',
        help: `Guests leaving on their departure date after the check-out time (${props.standardTimes.check_out}). The latest tier the departure is at or after applies; leaving after the departure date takes the last tier.`,
        tierLabel: 'Leaving at or after',
        example: { time: '15:00', type: 'percent', value: 50 },
    },
];

function addTier(
    side: 'early_check_in' | 'late_check_out',
    example: StayTimeChargeTier,
): void {
    form[side].tiers.push({ ...example });
}

function removeTier(
    side: 'early_check_in' | 'late_check_out',
    index: number,
): void {
    form[side].tiers.splice(index, 1);
}

function tierError(side: string, index: number): string | undefined {
    const errors = form.errors as Record<string, string>;

    return (
        errors[`${side}.tiers.${index}.time`] ??
        errors[`${side}.tiers.${index}.value`] ??
        errors[`${side}.tiers.${index}.type`]
    );
}

function save(): void {
    form.put(admin.properties.stayTimeCharges.update(props.propertyId).url, {
        preserveScroll: true,
    });
}

const inputClass =
    'rounded-md border border-[#e3e3e0] bg-white px-2 py-1.5 text-sm dark:border-[#3E3E3A] dark:bg-[#161615] dark:text-[#EDEDEC]';
</script>

<template>
    <form
        class="flex max-w-2xl flex-col gap-6 text-sm text-[#1b1b18] dark:text-[#EDEDEC]"
        @submit.prevent="save"
    >
        <div>
            <h2 class="text-base font-semibold">
                Early check-in / late check-out charges
            </h2>
            <p class="text-[#706f6c] dark:text-[#A1A09A]">
                Charged when the front desk checks a room in or out (it may
                waive them). A percentage is of the room's night rate. The
                check-in and check-out times come from Policies.
            </p>
        </div>

        <section
            v-for="side in sides"
            :key="side.key"
            class="flex flex-col gap-3 rounded-lg border border-[#e3e3e0] p-4 dark:border-[#3E3E3A]"
        >
            <label class="flex items-center gap-2 font-medium">
                <input
                    v-model="form[side.key].enabled"
                    type="checkbox"
                    class="h-4 w-4"
                    :data-test="`${side.key}-enabled`"
                />
                Charge for {{ side.title.toLowerCase() }}
            </label>
            <p class="text-xs text-[#706f6c] dark:text-[#A1A09A]">
                {{ side.help }}
            </p>

            <fieldset
                :disabled="!form[side.key].enabled"
                class="flex flex-col gap-2 disabled:opacity-60"
            >
                <div
                    v-for="(tier, index) in form[side.key].tiers"
                    :key="index"
                    class="flex flex-wrap items-center gap-2"
                >
                    <span
                        class="w-32 text-xs text-[#706f6c] dark:text-[#A1A09A]"
                        >{{ side.tierLabel }}</span
                    >
                    <input
                        v-model="tier.time"
                        type="time"
                        required
                        :class="inputClass"
                        :data-test="`${side.key}-time-${index}`"
                    />
                    <input
                        v-model.number="tier.value"
                        type="number"
                        min="0"
                        step="0.01"
                        required
                        class="w-28"
                        :class="inputClass"
                        :data-test="`${side.key}-value-${index}`"
                    />
                    <select v-model="tier.type" :class="inputClass">
                        <option value="percent">% of night rate</option>
                        <option value="fixed">fixed amount</option>
                    </select>
                    <button
                        type="button"
                        class="text-[#706f6c] hover:text-red-600"
                        :aria-label="`Remove tier ${index + 1}`"
                        @click="removeTier(side.key, index)"
                    >
                        ✕
                    </button>
                    <span
                        v-if="tierError(side.key, index)"
                        class="w-full text-xs text-red-600"
                        >{{ tierError(side.key, index) }}</span
                    >
                </div>
                <button
                    v-if="form[side.key].tiers.length < 6"
                    type="button"
                    class="self-start rounded border border-[#e3e3e0] px-2.5 py-1 text-xs font-medium dark:border-[#3E3E3A]"
                    :data-test="`${side.key}-add-tier`"
                    @click="addTier(side.key, side.example)"
                >
                    + Add tier
                </button>
            </fieldset>
        </section>

        <div class="grid gap-4 sm:grid-cols-3">
            <label class="flex flex-col gap-1">
                Grace period (minutes)
                <input
                    v-model.number="form.grace_minutes"
                    type="number"
                    min="0"
                    max="240"
                    required
                    :class="inputClass"
                />
                <span class="text-xs text-[#706f6c] dark:text-[#A1A09A]"
                    >No charge this close to the standard time.</span
                >
                <span
                    v-if="form.errors.grace_minutes"
                    class="text-xs text-red-600"
                    >{{ form.errors.grace_minutes }}</span
                >
            </label>
            <label class="flex flex-col gap-1">
                Tax rate (%)
                <input
                    v-model.number="form.tax_rate"
                    type="number"
                    min="0"
                    max="100"
                    step="0.01"
                    required
                    :class="inputClass"
                />
                <span
                    v-if="form.errors.tax_rate"
                    class="text-xs text-red-600"
                    >{{ form.errors.tax_rate }}</span
                >
            </label>
            <label class="flex flex-col gap-1">
                SAC / HSN code
                <input
                    v-model="form.sac_hsn_code"
                    type="text"
                    maxlength="8"
                    placeholder="996311"
                    :class="inputClass"
                />
            </label>
        </div>

        <div>
            <button
                type="submit"
                :disabled="form.processing"
                data-test="stay-time-charges-save"
                class="rounded-md bg-[#1b1b18] px-4 py-2 font-medium text-white disabled:opacity-60 dark:bg-[#EDEDEC] dark:text-[#1b1b18]"
            >
                Save
            </button>
        </div>
    </form>
</template>
