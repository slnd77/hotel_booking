<script setup lang="ts">
import { onBeforeUnmount, onMounted, reactive, watch } from 'vue';

export type InvoiceAdvancedSearchFilters = {
    invoice_generation_date_from: string;
    invoice_generation_date_to: string;
    invoice_number: string;
    created_by: string;
    booking_source: string;
    booking_status: string;
    has_attachment: string;
    document: string;
    send_bill: string;
    soa_generated: string;
    unit_type: string;
};

const props = defineProps<{
    show: boolean;
    filters: Record<string, string | number | null | undefined>;
    staff: { id: number; name: string }[];
}>();

const emit = defineEmits<{
    close: [];
    search: [filters: InvoiceAdvancedSearchFilters];
}>();

function emptyFilters(): InvoiceAdvancedSearchFilters {
    return {
        invoice_generation_date_from: '',
        invoice_generation_date_to: '',
        invoice_number: '',
        created_by: '',
        booking_source: '',
        booking_status: '',
        has_attachment: 'please_select',
        document: '',
        send_bill: 'please_select',
        soa_generated: 'please_select',
        unit_type: '',
    };
}

const form = reactive<InvoiceAdvancedSearchFilters>(emptyFilters());

watch(
    () => props.show,
    (show) => {
        if (!show) {
            return;
        }

        const seeded = emptyFilters();
        (Object.keys(seeded) as (keyof InvoiceAdvancedSearchFilters)[]).forEach(
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

function close(): void {
    emit('close');
}

function reset(): void {
    Object.assign(form, emptyFilters());
}

function search(): void {
    emit('search', { ...form });
    close();
}

function onKeydown(event: KeyboardEvent): void {
    if (event.key === 'Escape' && props.show) {
        close();
    }
}

onMounted(() => window.addEventListener('keydown', onKeydown));
onBeforeUnmount(() => window.removeEventListener('keydown', onKeydown));

const fieldClass =
    'block w-full rounded border border-[#ccc] bg-white px-2.5 py-1.5 text-xs text-[#333] focus:border-[#0284c7] focus:ring-1 focus:ring-[#0284c7] focus:outline-none dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]';
const labelClass =
    'flex flex-col gap-1 text-xs font-semibold text-[#555] dark:text-[#A1A09A]';
</script>

<template>
    <div
        v-if="show"
        class="fixed inset-0 z-50 flex items-start justify-center bg-black/20 p-4 pt-24"
        @click.self="close"
    >
        <div
            class="flex max-h-[85vh] w-full max-w-2xl flex-col overflow-hidden rounded border border-gray-300 bg-white shadow-xl dark:border-[#3E3E3A] dark:bg-[#161615]"
        >
            <!-- Header -->
            <div
                class="flex items-center justify-between border-b border-gray-200 bg-gray-50 px-5 py-2.5 dark:border-[#2b2b28] dark:bg-[#1a1a18]"
            >
                <h2 class="text-sm font-bold text-[#222] dark:text-white">
                    Advanced Search
                </h2>
                <div class="flex items-center gap-2">
                    <button
                        type="button"
                        class="text-xs font-medium text-gray-500 hover:text-black dark:text-gray-400 dark:hover:text-white"
                        @click="reset"
                    >
                        Reset
                    </button>
                    <button
                        type="button"
                        class="rounded bg-[#0284c7] px-3 py-1 text-xs font-semibold text-white shadow-xs hover:bg-[#0369a1]"
                        @click="search"
                    >
                        Search
                    </button>
                    <button
                        type="button"
                        class="ml-1 text-base leading-none text-gray-400 hover:text-black dark:hover:text-white"
                        @click="close"
                    >
                        ✕
                    </button>
                </div>
            </div>

            <!-- Form Body -->
            <div class="overflow-y-auto p-5 text-xs">
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <!-- Invoice Generation Dates -->
                    <div class="sm:col-span-2">
                        <span
                            class="mb-1 block text-xs font-semibold text-[#555] dark:text-[#A1A09A]"
                            >Invoice Generation Dates:</span
                        >
                        <div class="grid grid-cols-2 gap-3">
                            <label :class="labelClass">
                                <span
                                    class="text-[11px] font-normal text-gray-400"
                                    >From</span
                                >
                                <input
                                    v-model="form.invoice_generation_date_from"
                                    type="date"
                                    :class="fieldClass"
                                />
                            </label>
                            <label :class="labelClass">
                                <span
                                    class="text-[11px] font-normal text-gray-400"
                                    >To</span
                                >
                                <input
                                    v-model="form.invoice_generation_date_to"
                                    type="date"
                                    :class="fieldClass"
                                />
                            </label>
                        </div>
                    </div>

                    <!-- Invoice Number -->
                    <label :class="labelClass">
                        Invoice Number:
                        <input
                            v-model="form.invoice_number"
                            type="text"
                            placeholder="Invoice Number"
                            :class="fieldClass"
                        />
                    </label>

                    <!-- Created By -->
                    <label :class="labelClass">
                        Created By:
                        <select v-model="form.created_by" :class="fieldClass">
                            <option value="">--Select--</option>
                            <option
                                v-for="user in staff"
                                :key="user.id"
                                :value="user.name"
                            >
                                {{ user.name }}
                            </option>
                        </select>
                    </label>

                    <!-- Booking source -->
                    <label :class="labelClass">
                        Booking source:
                        <select
                            v-model="form.booking_source"
                            :class="fieldClass"
                        >
                            <option value="">--Select--</option>
                            <option value="Direct">Direct</option>
                            <option value="Corporate">Corporate</option>
                            <option value="Travel Agent">Travel Agent</option>
                            <option value="OTA">OTA</option>
                            <option value="Booking Engine">
                                Booking Engine
                            </option>
                        </select>
                    </label>

                    <!-- Booking status -->
                    <label :class="labelClass">
                        Booking status:
                        <select
                            v-model="form.booking_status"
                            :class="fieldClass"
                        >
                            <option value="">--Select--</option>
                            <option value="Confirm">Confirm</option>
                            <option value="Cancel">Cancel</option>
                        </select>
                    </label>

                    <!-- Has Attachment -->
                    <label :class="labelClass">
                        Has Attachment:
                        <select
                            v-model="form.has_attachment"
                            :class="fieldClass"
                        >
                            <option value="please_select">Please select</option>
                            <option value="yes">Yes</option>
                            <option value="no">No</option>
                        </select>
                    </label>

                    <!-- Document -->
                    <label :class="labelClass">
                        Document:
                        <input
                            v-model="form.document"
                            type="text"
                            placeholder="Document"
                            :class="fieldClass"
                        />
                    </label>

                    <!-- Send Bill -->
                    <label :class="labelClass">
                        Send Bill:
                        <select v-model="form.send_bill" :class="fieldClass">
                            <option value="please_select">Please select</option>
                            <option value="yes">Yes</option>
                            <option value="no">No</option>
                        </select>
                    </label>

                    <!-- SOA Generated -->
                    <label :class="labelClass">
                        SOA Generated:
                        <select
                            v-model="form.soa_generated"
                            :class="fieldClass"
                        >
                            <option value="please_select">Please select</option>
                            <option value="yes">Yes</option>
                            <option value="no">No</option>
                        </select>
                    </label>

                    <!-- Unit Type -->
                    <label :class="labelClass">
                        Unit Type:
                        <select v-model="form.unit_type" :class="fieldClass">
                            <option value="">--Select--</option>
                            <option value="Room">Room</option>
                            <option value="Outlet">Outlet</option>
                            <option value="AddOn">AddOn</option>
                        </select>
                    </label>
                </div>
            </div>

            <!-- Footer -->
            <div
                class="flex items-center justify-end gap-2 border-t border-gray-200 bg-gray-50 px-5 py-3 dark:border-[#2b2b28] dark:bg-[#1a1a18]"
            >
                <button
                    type="button"
                    class="rounded border border-gray-300 bg-white px-3 py-1.5 text-xs font-semibold text-gray-700 hover:bg-gray-50 dark:border-[#3E3E3A] dark:bg-[#161615] dark:text-gray-200"
                    @click="close"
                >
                    Cancel
                </button>
                <button
                    type="button"
                    class="rounded bg-[#0284c7] px-4 py-1.5 text-xs font-semibold text-white shadow-xs hover:bg-[#0369a1]"
                    @click="search"
                >
                    Search
                </button>
            </div>
        </div>
    </div>
</template>
