<script setup lang="ts">
import { computed } from 'vue';

/** Rate Settings → Rounding (stored in rate_settings_rounding; applied on the server). */
export type RoundingSettings = {
    enabled: boolean;
    method: 'nearest' | 'up' | 'down';
    multiple: number;
    ending: number | null;
    channels: ('direct' | 'ota')[];
};

const settings = defineModel<RoundingSettings>({ required: true });

const emit = defineEmits<{ save: [] }>();

const multiples = [1, 5, 10, 50, 100, 500, 1000];

/** Mirrors RateRounding::round() so the examples match what guests are charged. */
function rounded(rate: number): number {
    const { method, multiple, ending } = settings.value;
    const step = multiple > 0 ? multiple : 1;
    const steps =
        method === 'up'
            ? Math.ceil(+(rate / step).toFixed(6))
            : method === 'down'
              ? Math.floor(+(rate / step).toFixed(6))
              : Math.round(rate / step);
    let result = steps * step;

    if (ending !== null && ending > 0 && ending < step && result >= step) {
        result = result - step + ending;
    }

    return Math.max(0, Math.round(result * 100) / 100);
}

const examples = computed(() =>
    [1234.5, 2040, 2960, 4999.99].map((rate) => ({
        rate,
        rounded: rounded(rate),
    })),
);
const endingError = computed(
    () =>
        settings.value.ending !== null &&
        settings.value.ending >= settings.value.multiple,
);

function toggleChannel(channel: 'direct' | 'ota'): void {
    settings.value.channels = settings.value.channels.includes(channel)
        ? settings.value.channels.filter((item) => item !== channel)
        : [...settings.value.channels, channel];
}

const labelClass = 'text-sm font-semibold text-[#1b1b18] dark:text-[#EDEDEC]';
const inputClass =
    'rounded-md border border-[#e3e3e0] bg-white px-3 py-1.5 text-sm dark:border-[#3E3E3A] dark:bg-[#161615] dark:text-[#EDEDEC]';
</script>

<template>
    <div class="flex max-w-3xl flex-col gap-5">
        <p class="text-sm text-[#706f6c] dark:text-[#A1A09A]">
            Rates from your rate plans and packages are rounded before they are
            sold: on the booking engine, in the booking form's suggested rates
            and on the channel manager. Rates staff type themselves are kept as
            typed. Taxes are added after rounding.
        </p>

        <label class="flex items-center gap-2">
            <input
                v-model="settings.enabled"
                type="checkbox"
                class="h-4 w-4"
                data-test="rounding-enabled"
            />
            <span :class="labelClass">Round rates</span>
        </label>

        <div
            class="grid gap-4 sm:grid-cols-3"
            :class="settings.enabled ? '' : 'pointer-events-none opacity-50'"
        >
            <label
                class="flex flex-col gap-1 text-xs text-[#706f6c] dark:text-[#A1A09A]"
            >
                Round
                <select
                    v-model="settings.method"
                    :class="inputClass"
                    data-test="rounding-method"
                >
                    <option value="nearest">To the nearest</option>
                    <option value="up">Up</option>
                    <option value="down">Down</option>
                </select>
            </label>
            <label
                class="flex flex-col gap-1 text-xs text-[#706f6c] dark:text-[#A1A09A]"
            >
                To a multiple of
                <select
                    v-model.number="settings.multiple"
                    :class="inputClass"
                    data-test="rounding-multiple"
                >
                    <option
                        v-for="multiple in multiples"
                        :key="multiple"
                        :value="multiple"
                    >
                        {{ multiple }}
                    </option>
                </select>
            </label>
            <label
                class="flex flex-col gap-1 text-xs text-[#706f6c] dark:text-[#A1A09A]"
            >
                Ending in (optional)
                <input
                    v-model.number="settings.ending"
                    data-test="rounding-ending"
                    type="number"
                    min="0"
                    :max="settings.multiple - 1"
                    step="1"
                    placeholder="e.g. 99"
                    :class="inputClass"
                    @change="
                        settings.ending =
                            (settings.ending as unknown) === ''
                                ? null
                                : settings.ending
                    "
                />
                <span v-if="endingError" class="text-red-600"
                    >Must be less than {{ settings.multiple }}.</span
                >
            </label>
        </div>

        <fieldset
            class="flex flex-wrap gap-4"
            :class="settings.enabled ? '' : 'pointer-events-none opacity-50'"
        >
            <legend class="mb-1 text-xs text-[#706f6c] dark:text-[#A1A09A]">
                Apply to
            </legend>
            <label class="flex items-center gap-2 text-sm dark:text-[#EDEDEC]">
                <input
                    type="checkbox"
                    class="h-4 w-4"
                    :checked="settings.channels.includes('direct')"
                    @change="toggleChannel('direct')"
                />
                Booking engine and booking form (direct)
            </label>
            <label class="flex items-center gap-2 text-sm dark:text-[#EDEDEC]">
                <input
                    type="checkbox"
                    class="h-4 w-4"
                    :checked="settings.channels.includes('ota')"
                    @change="toggleChannel('ota')"
                />
                Channel manager (OTA rates)
            </label>
        </fieldset>

        <div
            v-if="settings.enabled"
            class="rounded-lg border border-[#e3e3e0] p-3 text-sm dark:border-[#3E3E3A]"
        >
            <p
                class="mb-2 text-xs font-semibold tracking-wide text-[#706f6c] uppercase dark:text-[#A1A09A]"
            >
                Examples
            </p>
            <ul class="grid grid-cols-2 gap-1 sm:grid-cols-4">
                <li
                    v-for="example in examples"
                    :key="example.rate"
                    class="dark:text-[#EDEDEC]"
                >
                    {{ example.rate.toFixed(2) }} →
                    <strong>{{ example.rounded.toFixed(2) }}</strong>
                </li>
            </ul>
        </div>

        <div>
            <button
                type="button"
                :disabled="endingError"
                data-test="rounding-save"
                class="rounded-md bg-[#1b1b18] px-5 py-2 text-sm font-medium text-white disabled:opacity-50 dark:bg-[#EDEDEC] dark:text-[#1b1b18]"
                @click="emit('save')"
            >
                Save
            </button>
        </div>
    </div>
</template>
