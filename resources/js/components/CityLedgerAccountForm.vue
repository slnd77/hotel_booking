<script setup lang="ts">
import type { InertiaForm } from '@inertiajs/vue3';

export type CityLedgerAccountFields = {
    name: string;
    code: string;
    account_type: string;
    agency_id: number | null;
    tax_number: string;
    contact_name: string;
    email: string;
    phone: string;
    address: string;
    credit_limit: number | string | null;
    payment_terms_days: number;
    is_active: boolean;
    notes: string;
};

defineProps<{
    form: InertiaForm<CityLedgerAccountFields>;
    types: Record<string, string>;
    agencies: { id: number; name: string }[];
}>();

const emit = defineEmits<{ submit: []; cancel: [] }>();

const inputClass =
    'rounded-md border border-[#e3e3e0] bg-white px-3 py-1.5 text-sm dark:border-[#3E3E3A] dark:bg-[#161615] dark:text-[#EDEDEC]';
const labelClass =
    'flex flex-col gap-1 text-xs text-[#706f6c] dark:text-[#A1A09A]';
</script>

<template>
    <form class="space-y-3" @submit.prevent="emit('submit')">
        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
            <label :class="[labelClass, 'sm:col-span-2']">
                Name
                <input
                    v-model="form.name"
                    type="text"
                    required
                    maxlength="255"
                    :class="inputClass"
                />
                <span v-if="form.errors.name" class="text-red-600">{{
                    form.errors.name
                }}</span>
            </label>
            <label :class="labelClass">
                Type
                <select v-model="form.account_type" :class="inputClass">
                    <option
                        v-for="(label, value) in types"
                        :key="value"
                        :value="value"
                    >
                        {{ label }}
                    </option>
                </select>
            </label>
            <label :class="labelClass">
                Account code
                <input
                    v-model="form.code"
                    type="text"
                    maxlength="40"
                    :class="inputClass"
                />
            </label>
            <label
                v-if="form.account_type === 'agency'"
                :class="[labelClass, 'sm:col-span-2']"
            >
                Agency
                <select v-model="form.agency_id" :class="inputClass">
                    <option :value="null">—</option>
                    <option
                        v-for="agency in agencies"
                        :key="agency.id"
                        :value="agency.id"
                    >
                        {{ agency.name }}
                    </option>
                </select>
            </label>
            <label :class="labelClass">
                Credit limit
                <input
                    v-model="form.credit_limit"
                    type="number"
                    min="0"
                    step="0.01"
                    placeholder="No limit"
                    :class="inputClass"
                />
                <span v-if="form.errors.credit_limit" class="text-red-600">{{
                    form.errors.credit_limit
                }}</span>
            </label>
            <label :class="labelClass">
                Payment terms (days)
                <input
                    v-model.number="form.payment_terms_days"
                    type="number"
                    min="0"
                    max="365"
                    required
                    :class="inputClass"
                />
            </label>
            <label :class="labelClass">
                GSTIN / tax number
                <input
                    v-model="form.tax_number"
                    type="text"
                    maxlength="40"
                    :class="inputClass"
                />
            </label>
            <label :class="labelClass">
                Contact person
                <input
                    v-model="form.contact_name"
                    type="text"
                    maxlength="255"
                    :class="inputClass"
                />
            </label>
            <label :class="labelClass">
                E-mail
                <input
                    v-model="form.email"
                    type="email"
                    maxlength="255"
                    :class="inputClass"
                />
                <span v-if="form.errors.email" class="text-red-600">{{
                    form.errors.email
                }}</span>
            </label>
            <label :class="labelClass">
                Phone
                <input
                    v-model="form.phone"
                    type="text"
                    maxlength="30"
                    :class="inputClass"
                />
            </label>
            <label :class="[labelClass, 'sm:col-span-2']">
                Billing address
                <textarea
                    v-model="form.address"
                    rows="2"
                    maxlength="1000"
                    :class="inputClass"
                />
            </label>
            <label :class="[labelClass, 'sm:col-span-2']">
                Notes
                <textarea
                    v-model="form.notes"
                    rows="2"
                    maxlength="2000"
                    :class="inputClass"
                />
            </label>
            <label
                class="flex items-center gap-2 text-sm text-[#1b1b18] dark:text-[#EDEDEC]"
            >
                <input v-model="form.is_active" type="checkbox" />
                Active (bills can be moved to it)
            </label>
        </div>
        <div class="flex justify-end gap-2">
            <button
                type="button"
                class="rounded-md border border-[#e3e3e0] px-4 py-2 text-sm dark:border-[#3E3E3A] dark:text-[#EDEDEC]"
                @click="emit('cancel')"
            >
                Cancel
            </button>
            <button
                type="submit"
                :disabled="form.processing"
                class="rounded-md bg-[#1b1b18] px-4 py-2 text-sm font-medium text-white disabled:opacity-60 dark:bg-[#EDEDEC] dark:text-[#1b1b18]"
            >
                Save
            </button>
        </div>
    </form>
</template>
