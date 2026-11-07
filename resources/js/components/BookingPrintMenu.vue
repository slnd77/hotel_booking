<script setup lang="ts">
import admin from '@/routes/admin';
import { onBeforeUnmount, onMounted, ref } from 'vue';

/**
 * The PRINT menu of a booking: each document opens print-ready in a new tab
 * (BookingPrintController), laid out per the property's Invoice / GRC settings.
 */
const props = defineProps<{
    propertyId: number;
    bookingId: number;
    buttonClass?: string;
}>();

const documents: { value: string | null; label: string }[] = [
    { value: 'grc', label: 'GRC (Blank)' },
    { value: 'reservation-internal', label: 'Reservation (Int.)' },
    { value: 'reservation-guest', label: 'Reservation (Guest)' },
    { value: 'bill-summary', label: 'Guest Bill Summary' },
    { value: 'room-invoice', label: 'Room Invoice' },
    { value: null, label: 'Extras Invoice' },
    { value: null, label: 'Extras Invoice Summary' },
];

const open = ref(false);
const root = ref<HTMLElement | null>(null);

function printDocument(document: string): void {
    open.value = false;
    window.open(
        admin.bookings.print({
            property: props.propertyId,
            booking: props.bookingId,
            document,
        }).url,
        '_blank',
        'noopener',
    );
}

function pdfUrl(document: string): string {
    return admin.bookings.printPdf({
        property: props.propertyId,
        booking: props.bookingId,
        document,
    }).url;
}

function closeOnOutsideClick(event: MouseEvent): void {
    if (root.value && !root.value.contains(event.target as Node)) {
        open.value = false;
    }
}

onMounted(() => document.addEventListener('mousedown', closeOnOutsideClick));
onBeforeUnmount(() =>
    document.removeEventListener('mousedown', closeOnOutsideClick),
);
</script>

<template>
    <div ref="root" class="relative">
        <button
            type="button"
            :class="
                buttonClass ??
                'inline-flex cursor-pointer items-center gap-1 rounded bg-[#ea580c] px-3.5 py-1.5 text-xs font-bold text-white uppercase shadow-xs transition-colors hover:bg-[#c2410c]'
            "
            aria-haspopup="menu"
            :aria-expanded="open"
            @click="open = !open"
        >
            Print ▴
        </button>
        <div
            v-if="open"
            role="menu"
            class="absolute bottom-full left-0 z-50 mb-1 w-60 rounded-md border border-gray-200 bg-white py-1 text-sm text-gray-700 shadow-lg dark:border-[#3E3E3A] dark:bg-[#161615] dark:text-gray-200"
        >
            <template v-for="item in documents" :key="item.label">
                <div
                    v-if="item.value"
                    class="flex items-center hover:bg-gray-100 dark:hover:bg-neutral-800"
                >
                    <button
                        type="button"
                        role="menuitem"
                        class="flex-1 cursor-pointer py-1.5 pl-4 text-left"
                        @click="printDocument(item.value)"
                    >
                        {{ item.label }}
                    </button>
                    <a
                        :href="pdfUrl(item.value)"
                        role="menuitem"
                        :title="`Download ${item.label} as PDF`"
                        class="mr-2 rounded px-1.5 py-0.5 text-[10px] font-bold text-[#ea580c] hover:bg-orange-100 dark:hover:bg-orange-950/40"
                        @click="open = false"
                    >
                        PDF ⤓
                    </a>
                </div>
                <span
                    v-else
                    role="menuitem"
                    aria-disabled="true"
                    title="No extras are posted to bookings yet"
                    class="block px-4 py-1.5 text-gray-400 dark:text-gray-500"
                >
                    {{ item.label }}
                </span>
            </template>
        </div>
    </div>
</template>
