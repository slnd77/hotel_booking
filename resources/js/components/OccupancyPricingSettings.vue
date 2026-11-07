<script setup lang="ts">
import { computed } from 'vue';

/** Rate Settings → Occupancy Pricing (stored in rate_settings_occupancy_pricing; applied on the server). */
export type OccupancyTier = {
    id: number;
    from: number;
    to: number;
    adjustment_type: 'percent' | 'amount';
    adjustment: number;
};

export type OccupancyPricingSettings = {
    enabled: boolean;
    basis: 'property' | 'category';
    channels: ('direct' | 'ota')[];
    tiers: OccupancyTier[];
};

const settings = defineModel<OccupancyPricingSettings>({ required: true });

const emit = defineEmits<{ save: [] }>();

function addTier(): void {
    const last = settings.value.tiers[settings.value.tiers.length - 1];
    const from = last ? Math.min(100, last.to + 1) : 0;

    settings.value.tiers.push({
        id: Math.max(0, ...settings.value.tiers.map((tier) => tier.id)) + 1,
        from,
        to: 100,
        adjustment_type: 'percent',
        adjustment: 0,
    });
}

function removeTier(id: number): void {
    settings.value.tiers = settings.value.tiers.filter(
        (tier) => tier.id !== id,
    );
}

function toggleChannel(channel: 'direct' | 'ota'): void {
    settings.value.channels = settings.value.channels.includes(channel)
        ? settings.value.channels.filter((item) => item !== channel)
        : [...settings.value.channels, channel];
}

/** Mirrors OccupancyPricing::adjust() so the example matches what guests are charged. */
function adjusted(rate: number, tier: OccupancyTier): number {
    const result =
        tier.adjustment_type === 'amount'
            ? rate + tier.adjustment
            : rate * (1 + tier.adjustment / 100);

    return Math.max(0, Math.round(result * 100) / 100);
}

const tierErrors = computed(() =>
    settings.value.tiers.map((tier, index) => {
        if (tier.from > tier.to) {
            return 'From must not exceed To.';
        }

        const overlapping = settings.value.tiers.find(
            (other, otherIndex) =>
                otherIndex < index &&
                tier.from <= other.to &&
                tier.to >= other.from,
        );

        return overlapping
            ? `Overlaps the ${overlapping.from}–${overlapping.to}% tier.`
            : null;
    }),
);
const hasErrors = computed(() =>
    tierErrors.value.some((error) => error !== null),
);

const labelClass = 'text-sm font-semibold text-[#1b1b18] dark:text-[#EDEDEC]';
const inputClass =
    'rounded-md border border-[#e3e3e0] bg-white px-3 py-1.5 text-sm dark:border-[#3E3E3A] dark:bg-[#161615] dark:text-[#EDEDEC]';
</script>

<template>
    <div class="flex max-w-4xl flex-col gap-5">
        <p class="text-sm text-[#706f6c] dark:text-[#A1A09A]">
            Rates from your rate plans rise or fall with how full each night
            already is. Pick occupancy bands and the change for each: a
            percentage or a fixed amount per night. The change is applied before
            rounding, on the booking engine, in the booking form's suggested
            rates and on the channel manager. Rates staff type themselves are
            kept as typed.
        </p>

        <label class="flex items-center gap-2">
            <input
                v-model="settings.enabled"
                type="checkbox"
                class="h-4 w-4"
                data-test="occupancy-enabled"
            />
            <span :class="labelClass">Price by occupancy</span>
        </label>

        <div
            class="grid gap-4 sm:grid-cols-2"
            :class="settings.enabled ? '' : 'pointer-events-none opacity-50'"
        >
            <label
                class="flex flex-col gap-1 text-xs text-[#706f6c] dark:text-[#A1A09A]"
            >
                Occupancy measured across
                <select
                    v-model="settings.basis"
                    :class="inputClass"
                    data-test="occupancy-basis"
                >
                    <option value="property">The whole property</option>
                    <option value="category">
                        The room category being priced
                    </option>
                </select>
            </label>

            <fieldset class="flex flex-col gap-2">
                <legend class="mb-1 text-xs text-[#706f6c] dark:text-[#A1A09A]">
                    Apply to
                </legend>
                <label
                    class="flex items-center gap-2 text-sm dark:text-[#EDEDEC]"
                >
                    <input
                        type="checkbox"
                        class="h-4 w-4"
                        :checked="settings.channels.includes('direct')"
                        @change="toggleChannel('direct')"
                    />
                    Booking engine and booking form (direct)
                </label>
                <label
                    class="flex items-center gap-2 text-sm dark:text-[#EDEDEC]"
                >
                    <input
                        type="checkbox"
                        class="h-4 w-4"
                        :checked="settings.channels.includes('ota')"
                        @change="toggleChannel('ota')"
                    />
                    Channel manager (OTA rates)
                </label>
            </fieldset>
        </div>

        <div :class="settings.enabled ? '' : 'pointer-events-none opacity-50'">
            <div
                class="overflow-x-auto rounded-lg border border-[#e3e3e0] dark:border-[#3E3E3A]"
            >
                <table
                    class="min-w-full divide-y divide-[#e3e3e0] text-sm dark:divide-[#3E3E3A]"
                >
                    <thead class="bg-[#f5f5f4] dark:bg-[#1D1D1B]">
                        <tr
                            class="text-left text-xs font-medium tracking-wide text-[#706f6c] uppercase dark:text-[#A1A09A]"
                        >
                            <th class="px-4 py-3">Occupancy from (%)</th>
                            <th class="px-4 py-3">To (%)</th>
                            <th class="px-4 py-3">Change</th>
                            <th class="px-4 py-3">Amount</th>
                            <th class="px-4 py-3">Example (₹2,000)</th>
                            <th class="px-4 py-3"></th>
                        </tr>
                    </thead>
                    <tbody
                        class="divide-y divide-[#e3e3e0] bg-white dark:divide-[#3E3E3A] dark:bg-[#161615]"
                    >
                        <tr
                            v-for="(tier, index) in settings.tiers"
                            :key="tier.id"
                            data-test="occupancy-tier"
                        >
                            <td class="px-4 py-2">
                                <input
                                    v-model.number="tier.from"
                                    type="number"
                                    min="0"
                                    max="100"
                                    step="1"
                                    class="w-24"
                                    :class="inputClass"
                                />
                            </td>
                            <td class="px-4 py-2">
                                <input
                                    v-model.number="tier.to"
                                    type="number"
                                    min="0"
                                    max="100"
                                    step="1"
                                    class="w-24"
                                    :class="inputClass"
                                />
                            </td>
                            <td class="px-4 py-2">
                                <select
                                    v-model="tier.adjustment_type"
                                    :class="inputClass"
                                >
                                    <option value="percent">Percent</option>
                                    <option value="amount">
                                        Fixed amount per night
                                    </option>
                                </select>
                            </td>
                            <td class="px-4 py-2">
                                <input
                                    v-model.number="tier.adjustment"
                                    type="number"
                                    step="0.01"
                                    class="w-28"
                                    :class="inputClass"
                                    placeholder="e.g. -10 or 15"
                                />
                            </td>
                            <td class="px-4 py-2 dark:text-[#EDEDEC]">
                                {{ adjusted(2000, tier).toFixed(2) }}
                            </td>
                            <td class="px-4 py-2">
                                <button
                                    type="button"
                                    class="text-sm text-[#706f6c] hover:text-red-600"
                                    :aria-label="`Remove tier ${index + 1}`"
                                    @click="removeTier(tier.id)"
                                >
                                    ✕
                                </button>
                                <p
                                    v-if="tierErrors[index]"
                                    class="mt-1 text-xs text-red-600"
                                >
                                    {{ tierErrors[index] }}
                                </p>
                            </td>
                        </tr>
                        <tr v-if="!settings.tiers.length">
                            <td
                                colspan="6"
                                class="px-4 py-6 text-center text-[#706f6c] dark:text-[#A1A09A]"
                            >
                                No tiers yet. Rates stay as set in the rate
                                plans.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <button
                type="button"
                class="mt-2 text-sm font-medium text-[#0284c7] hover:underline"
                data-test="occupancy-add-tier"
                @click="addTier"
            >
                + Add tier
            </button>
            <p class="mt-2 text-xs text-[#706f6c] dark:text-[#A1A09A]">
                A night whose occupancy falls outside every tier keeps its rate
                plan price. Blocked rooms count as sold; categories excluded
                from occupancy are left out of the property-wide figure.
            </p>
        </div>

        <div>
            <button
                type="button"
                :disabled="hasErrors"
                data-test="occupancy-save"
                class="rounded-md bg-[#1b1b18] px-5 py-2 text-sm font-medium text-white disabled:opacity-50 dark:bg-[#EDEDEC] dark:text-[#1b1b18]"
                @click="emit('save')"
            >
                Save
            </button>
        </div>
    </div>
</template>
