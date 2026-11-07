<script setup lang="ts">
import { onBeforeUnmount, onMounted, reactive, watch } from 'vue';

export type BookingAdvancedSearchFilters = {
    reservation_ref: string;
    guest_name: string;
    guest_email: string;
    guest_mobile: string;
    booking_source: string;
    booking_type: string;
    reservation_status: string;
    check_in_status: string;
    arrival_from: string;
    arrival_to: string;
    departure_from: string;
    departure_to: string;
    created_from: string;
    created_to: string;
    stay_from: string;
    stay_to: string;
    updated_from: string;
    updated_to: string;
    rooms_operator: string;
    rooms_count: string;
    special_instructions_has: string;
    internal_notes_has: string;
};

const props = defineProps<{
    show: boolean;
    filters: Record<string, string | number | null | undefined>;
}>();

const emit = defineEmits<{
    close: [];
    search: [filters: BookingAdvancedSearchFilters];
}>();

function emptyFilters(): BookingAdvancedSearchFilters {
    return {
        reservation_ref: '',
        guest_name: '',
        guest_email: '',
        guest_mobile: '',
        booking_source: '',
        booking_type: '',
        reservation_status: '',
        check_in_status: '',
        arrival_from: '',
        arrival_to: '',
        departure_from: '',
        departure_to: '',
        created_from: '',
        created_to: '',
        stay_from: '',
        stay_to: '',
        updated_from: '',
        updated_to: '',
        rooms_operator: '>=',
        rooms_count: '',
        special_instructions_has: '',
        internal_notes_has: '',
    };
}

const form = reactive<BookingAdvancedSearchFilters>(emptyFilters());

watch(
    () => props.show,
    (show) => {
        if (!show) {
            return;
        }

        const seeded = emptyFilters();

        (Object.keys(seeded) as (keyof BookingAdvancedSearchFilters)[]).forEach(
            (key) => {
                const value = props.filters[key];
                seeded[key] =
                    value === undefined || value === null
                        ? seeded[key]
                        : String(value);
            },
        );

        Object.assign(form, seeded);
    },
    { immediate: true },
);

const bookingSourceOptions = [
    { value: 'direct', label: 'Direct' },
    { value: 'corporate', label: 'Corporate' },
    { value: 'travel_agent', label: 'Agent' },
    { value: 'ota', label: 'OTA' },
    { value: 'meta', label: 'Meta' },
    { value: 'booking_engine', label: 'Booking Engine' },
];

const bookingTypeOptions = [
    { value: 'fit', label: 'FIT' },
    { value: 'group', label: 'Group' },
    { value: 'mice', label: 'MICE' },
    { value: 'series', label: 'SERIES' },
];

function reset(): void {
    Object.assign(form, emptyFilters());
}

function search(): void {
    emit('search', { ...form });
}

function close(): void {
    emit('close');
}

function onKeydown(event: KeyboardEvent): void {
    if (event.key === 'Escape' && props.show) {
        close();
    }
}

onMounted(() => window.addEventListener('keydown', onKeydown));
onBeforeUnmount(() => window.removeEventListener('keydown', onKeydown));

const fieldClass =
    'block rounded-md border border-[#e3e3e0] bg-white px-3 py-2 text-sm text-[#1b1b18] focus:border-[#f53003] focus:ring-1 focus:ring-[#f53003] focus:outline-none dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]';
const labelClass =
    'flex flex-col gap-1 text-sm text-[#706f6c] dark:text-[#A1A09A]';
</script>

<template>
    <div
        v-if="show"
        class="fixed inset-0 z-50 flex items-center justify-center bg-black/30 p-4"
        @click.self="close"
    >
        <div
            class="flex max-h-[90vh] w-full max-w-4xl flex-col overflow-hidden rounded-lg border border-[#e3e3e0] bg-white dark:border-[#3E3E3A] dark:bg-[#161615]"
        >
            <div
                class="flex items-center justify-between bg-[#1b1b18] px-6 py-3"
            >
                <h2 class="font-display text-base font-semibold text-white">
                    Advanced Search
                </h2>
                <div class="flex items-center gap-3">
                    <button
                        type="button"
                        class="text-sm font-medium text-white/80 hover:text-white"
                        @click="reset"
                    >
                        Reset
                    </button>
                    <button
                        type="button"
                        class="rounded-md border border-black bg-[#f53003] px-4 py-1.5 text-sm font-semibold text-white hover:bg-[#d92a02]"
                        @click="search"
                    >
                        Search
                    </button>
                    <button
                        type="button"
                        class="text-white/80 hover:text-white"
                        @click="close"
                    >
                        <svg
                            xmlns="http://www.w3.org/2000/svg"
                            class="h-5 w-5"
                            fill="none"
                            viewBox="0 0 24 24"
                            stroke="currentColor"
                        >
                            <path
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                stroke-width="2"
                                d="M6 18L18 6M6 6l12 12"
                            />
                        </svg>
                    </button>
                </div>
            </div>

            <div
                class="grid grid-cols-1 gap-4 overflow-y-auto px-6 py-5 sm:grid-cols-3"
            >
                <label :class="labelClass">
                    Reservation No.
                    <input
                        v-model="form.reservation_ref"
                        type="text"
                        :class="fieldClass"
                    />
                </label>
                <label :class="labelClass">
                    Guest name has
                    <input
                        v-model="form.guest_name"
                        type="text"
                        :class="fieldClass"
                    />
                </label>
                <label :class="labelClass">
                    Guest e-mail has
                    <input
                        v-model="form.guest_email"
                        type="text"
                        :class="fieldClass"
                    />
                </label>

                <label :class="labelClass">
                    Guest mobile has
                    <input
                        v-model="form.guest_mobile"
                        type="text"
                        :class="fieldClass"
                    />
                </label>
                <label :class="labelClass">
                    Booking source
                    <select v-model="form.booking_source" :class="fieldClass">
                        <option value="">--Select--</option>
                        <option
                            v-for="option in bookingSourceOptions"
                            :key="option.value"
                            :value="option.value"
                        >
                            {{ option.label }}
                        </option>
                    </select>
                </label>
                <label :class="labelClass">
                    Booking Type
                    <select v-model="form.booking_type" :class="fieldClass">
                        <option value="">--Select--</option>
                        <option
                            v-for="option in bookingTypeOptions"
                            :key="option.value"
                            :value="option.value"
                        >
                            {{ option.label }}
                        </option>
                    </select>
                </label>

                <label :class="labelClass">
                    Reservation status
                    <select
                        v-model="form.reservation_status"
                        :class="fieldClass"
                    >
                        <option value="">--Select--</option>
                        <option value="confirmed">Confirmed</option>
                    </select>
                </label>
                <div :class="labelClass">
                    Numbers of rooms per Reservation
                    <div class="flex gap-2">
                        <select
                            v-model="form.rooms_operator"
                            class="w-20"
                            :class="fieldClass"
                        >
                            <option value=">=">&gt;=</option>
                            <option value="<=">&lt;=</option>
                            <option value="=">=</option>
                        </select>
                        <input
                            v-model="form.rooms_count"
                            type="number"
                            min="0"
                            class="flex-1"
                            :class="fieldClass"
                        />
                    </div>
                </div>
                <label :class="labelClass">
                    Reservation Check In/Out Status:
                    <select v-model="form.check_in_status" :class="fieldClass">
                        <option value="">Both</option>
                        <option value="Check-In">Check-In</option>
                        <option value="Check-Out">Check-Out</option>
                        <option value="No Stay">No Stay</option>
                        <option value="Check-Out & Released Room">
                            Check-Out &amp; Released Room
                        </option>
                    </select>
                </label>

                <div
                    class="col-span-full grid grid-cols-1 gap-4 border-t border-[#e3e3e0] pt-4 sm:grid-cols-2 dark:border-[#3E3E3A]"
                >
                    <label :class="labelClass">
                        Date of arrival within (from)
                        <input
                            v-model="form.arrival_from"
                            type="date"
                            :class="fieldClass"
                        />
                    </label>
                    <label :class="labelClass">
                        Date of arrival within (to)
                        <input
                            v-model="form.arrival_to"
                            type="date"
                            :class="fieldClass"
                        />
                    </label>
                    <label :class="labelClass">
                        Date of departure within (from)
                        <input
                            v-model="form.departure_from"
                            type="date"
                            :class="fieldClass"
                        />
                    </label>
                    <label :class="labelClass">
                        Date of departure within (to)
                        <input
                            v-model="form.departure_to"
                            type="date"
                            :class="fieldClass"
                        />
                    </label>
                    <label :class="labelClass">
                        Date of Reservation creation (from)
                        <input
                            v-model="form.created_from"
                            type="date"
                            :class="fieldClass"
                        />
                    </label>
                    <label :class="labelClass">
                        Date of Reservation creation (to)
                        <input
                            v-model="form.created_to"
                            type="date"
                            :class="fieldClass"
                        />
                    </label>
                    <label :class="labelClass">
                        Date of Reservation Stay (from)
                        <input
                            v-model="form.stay_from"
                            type="date"
                            :class="fieldClass"
                        />
                    </label>
                    <label :class="labelClass">
                        Date of Reservation Stay (to)
                        <input
                            v-model="form.stay_to"
                            type="date"
                            :class="fieldClass"
                        />
                    </label>
                    <label :class="labelClass">
                        Date of Reservation updation (from)
                        <input
                            v-model="form.updated_from"
                            type="date"
                            :class="fieldClass"
                        />
                    </label>
                    <label :class="labelClass">
                        Date of Reservation updation (to)
                        <input
                            v-model="form.updated_to"
                            type="date"
                            :class="fieldClass"
                        />
                    </label>
                </div>

                <label class="col-span-full" :class="labelClass">
                    Special instruction/Payment instruction string has
                    <input
                        v-model="form.special_instructions_has"
                        type="text"
                        :class="fieldClass"
                    />
                </label>
                <label class="col-span-full" :class="labelClass">
                    Internal Notes string has
                    <input
                        v-model="form.internal_notes_has"
                        type="text"
                        :class="fieldClass"
                    />
                </label>
            </div>
        </div>
    </div>
</template>
