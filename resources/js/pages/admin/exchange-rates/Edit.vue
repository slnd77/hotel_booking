<script setup lang="ts">
import AdminLayout from '@/layouts/AdminLayout.vue';
import { calendarDate } from '@/lib/usePropertyTime';
import admin from '@/routes/admin';
import { Head, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';

defineOptions({ layout: AdminLayout });

const props = defineProps<{
    property: { id: number; name: string };
    baseCurrency: string;
    today: string;
    current: { currency_code: string; rate: number; effective_date: string }[];
    history: {
        id: number;
        currency_code: string;
        rate: number;
        effective_date: string;
        set_by: string | null;
    }[];
    currencies: string[];
}>();

const form = useForm({
    currency_code: '',
    rate: '' as number | string,
    effective_date: props.today,
});
const foreignCurrencies = computed(() =>
    props.currencies.filter((code) => code !== props.baseCurrency),
);

function save(): void {
    form.post(admin.exchangeRates.store(props.property.id).url, {
        preserveScroll: true,
        onSuccess: () => form.reset('rate'),
    });
}

function edit(code: string, rate: number): void {
    form.currency_code = code;
    form.rate = rate;
    form.effective_date = props.today;
}

const cardClass =
    'rounded-lg border border-[#e3e3e0] p-4 dark:border-[#3E3E3A]';
const inputClass =
    'rounded-md border border-[#e3e3e0] bg-white px-3 py-1.5 text-sm dark:border-[#3E3E3A] dark:bg-[#161615] dark:text-[#EDEDEC]';
const labelClass =
    'flex flex-col gap-1 text-xs text-[#706f6c] dark:text-[#A1A09A]';
</script>

<template>
    <Head :title="`Exchange Rates: ${property.name}`" />

    <div class="mb-4">
        <h1
            class="font-display text-2xl font-semibold text-[#1b1b18] dark:text-[#EDEDEC]"
        >
            Exchange Rates
        </h1>
        <p class="text-sm text-[#706f6c] dark:text-[#A1A09A]">
            {{ property.name }}: what one unit of another currency is worth in
            {{ baseCurrency }}. Payments in another currency are converted at
            the rate of their date.
        </p>
    </div>

    <div class="mb-6 grid grid-cols-1 gap-4 lg:grid-cols-2">
        <form :class="[cardClass, 'space-y-3']" @submit.prevent="save">
            <h2
                class="text-sm font-semibold text-[#1b1b18] dark:text-[#EDEDEC]"
            >
                Set a rate
            </h2>
            <div class="grid grid-cols-3 gap-3">
                <label :class="labelClass">
                    Currency
                    <select
                        v-model="form.currency_code"
                        required
                        :class="inputClass"
                    >
                        <option value="" disabled>Choose</option>
                        <option
                            v-for="code in foreignCurrencies"
                            :key="code"
                            :value="code"
                        >
                            {{ code }}
                        </option>
                    </select>
                    <span
                        v-if="form.errors.currency_code"
                        class="text-red-600"
                        >{{ form.errors.currency_code }}</span
                    >
                </label>
                <label :class="labelClass">
                    1 {{ form.currency_code || '…' }} = {{ baseCurrency }}
                    <input
                        v-model="form.rate"
                        type="number"
                        min="0.000001"
                        step="0.000001"
                        required
                        :class="inputClass"
                    />
                    <span v-if="form.errors.rate" class="text-red-600">{{
                        form.errors.rate
                    }}</span>
                </label>
                <label :class="labelClass">
                    From
                    <input
                        v-model="form.effective_date"
                        type="date"
                        required
                        :class="inputClass"
                    />
                </label>
            </div>
            <button
                type="submit"
                :disabled="form.processing"
                class="rounded-md bg-[#1b1b18] px-4 py-2 text-sm font-medium text-white disabled:opacity-60 dark:bg-[#EDEDEC] dark:text-[#1b1b18]"
            >
                Save rate
            </button>
        </form>

        <div :class="cardClass">
            <h2
                class="mb-2 text-sm font-semibold text-[#1b1b18] dark:text-[#EDEDEC]"
            >
                Today's rates
            </h2>
            <p
                v-if="!current.length"
                class="text-sm text-[#706f6c] dark:text-[#A1A09A]"
            >
                No rates set yet.
            </p>
            <table v-else class="w-full text-left text-sm dark:text-[#EDEDEC]">
                <thead
                    class="text-xs text-[#706f6c] uppercase dark:text-[#A1A09A]"
                >
                    <tr>
                        <th class="py-1">Currency</th>
                        <th class="text-right">Rate ({{ baseCurrency }})</th>
                        <th class="text-right">Since</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="rate in current"
                        :key="rate.currency_code"
                        class="border-t border-[#e3e3e0] dark:border-[#3E3E3A]"
                    >
                        <td class="py-1">{{ rate.currency_code }}</td>
                        <td class="text-right">{{ rate.rate }}</td>
                        <td class="text-right">
                            {{ calendarDate(rate.effective_date) }}
                        </td>
                        <td class="text-right">
                            <button
                                type="button"
                                class="text-xs underline"
                                @click="edit(rate.currency_code, rate.rate)"
                            >
                                Update
                            </button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

    <div :class="cardClass">
        <h2
            class="mb-2 text-sm font-semibold text-[#1b1b18] dark:text-[#EDEDEC]"
        >
            History
        </h2>
        <p
            v-if="!history.length"
            class="text-sm text-[#706f6c] dark:text-[#A1A09A]"
        >
            Nothing yet.
        </p>
        <table v-else class="w-full text-left text-sm dark:text-[#EDEDEC]">
            <thead class="text-xs text-[#706f6c] uppercase dark:text-[#A1A09A]">
                <tr>
                    <th class="py-1">From</th>
                    <th>Currency</th>
                    <th class="pr-6 text-right">Rate</th>
                    <th>Set by</th>
                </tr>
            </thead>
            <tbody>
                <tr
                    v-for="rate in history"
                    :key="rate.id"
                    class="border-t border-[#e3e3e0] dark:border-[#3E3E3A]"
                >
                    <td class="py-1">
                        {{ calendarDate(rate.effective_date) }}
                    </td>
                    <td>{{ rate.currency_code }}</td>
                    <td class="pr-6 text-right">{{ rate.rate }}</td>
                    <td>{{ rate.set_by ?? '—' }}</td>
                </tr>
            </tbody>
        </table>
    </div>
</template>
