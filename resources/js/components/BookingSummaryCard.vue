<script setup lang="ts">
import { useCurrency } from '@/lib/useCurrency';
import { computed } from 'vue';

const { amount, code } = useCurrency();

/**
 * The booking summary card: reference, stay dates, rooms, guest,
 * status stamp and bill. Used beside the booking form and as the Smart Booking
 * Chart popover.
 */
const props = defineProps<{
    /** Booking number; shown as "# N.A." until the booking is saved. */
    bookingNumber?: string | null;
    refId?: string | null;
    checkIn: string;
    checkOut: string;
    nights: number;
    /** One line per room category & meal plan (see roomSummaryLines). */
    roomLines: string[];
    total: number;
    outstanding: number;
    /** Bill To Guest Company: the company's share of what is owed (not the guest's to pay). */
    companyDue?: number | null;
    source: string;
    /** Booking status value (e.g. confirmed, on_hold); colours the header and the stamp. */
    status?: string | null;
    statusLabel?: string | null;
    guest?: { name: string; phone: string | null; email: string | null } | null;
}>();

const MONTHS = [
    'JAN',
    'FEB',
    'MAR',
    'APR',
    'MAY',
    'JUN',
    'JUL',
    'AUG',
    'SEP',
    'OCT',
    'NOV',
    'DEC',
];

function dateParts(iso: string): { day: string; monthYear: string } {
    const date = new Date(`${iso}T00:00:00`);

    if (Number.isNaN(date.getTime())) {
        return { day: '--', monthYear: '' };
    }

    return {
        day: String(date.getDate()).padStart(2, '0'),
        monthYear: `${MONTHS[date.getMonth()]} ${date.getFullYear()}`,
    };
}

/** What the guest owes once the company's share is taken off. */
const guestDue = computed(() =>
    Math.max(
        0,
        Math.round((props.outstanding - (props.companyDue ?? 0)) * 100) / 100,
    ),
);

const checkInParts = computed(() => dateParts(props.checkIn));
const checkOutParts = computed(() => dateParts(props.checkOut));

const SOURCE_LABELS: Record<string, string> = {
    direct: 'Direct',
    corporate: 'Corporate',
    travel_agent: 'Agent',
    ota: 'OTA',
    meta: 'Meta',
    booking_engine: 'Booking Engine',
};

const sourceLabel = computed(() => SOURCE_LABELS[props.source] ?? props.source);

const headerClass = computed(() => {
    switch (props.status) {
        case 'on_hold':
            return 'bg-orange-500';
        case 'enquiry':
            return 'bg-amber-500';
        case 'cancelled':
            return 'bg-red-600';
        case 'checked_out':
        case 'expired_quote':
        case 'no_show':
            return 'bg-gray-500';
        default:
            return 'bg-green-600';
    }
});

const stampClass = computed(() => {
    switch (props.status) {
        case 'on_hold':
        case 'enquiry':
            return 'border-amber-500 text-amber-600';
        case 'cancelled':
            return 'border-red-500 text-red-500';
        case 'checked_out':
        case 'expired_quote':
        case 'no_show':
            return 'border-gray-500 text-gray-600';
        default:
            return 'border-emerald-500 text-emerald-600';
    }
});

function currency(value: number): string {
    return amount(value);
}
</script>

<template>
    <div
        class="flex flex-col overflow-hidden rounded-lg border border-[#e3e3e0] bg-white dark:border-[#3E3E3A] dark:bg-[#161615]"
    >
        <div class="flex text-white">
            <div
                class="flex flex-1 items-center px-4 py-3"
                :class="headerClass"
            >
                <p class="text-sm font-bold"># {{ bookingNumber || 'N.A.' }}</p>
            </div>
            <div class="flex items-start gap-3 bg-[#1b1b18] px-4 py-3">
                <div>
                    <p class="text-xs opacity-70">REF I.D.</p>
                    <p class="text-sm font-semibold">{{ refId || 'N.A.' }}</p>
                </div>
                <slot name="close" />
            </div>
        </div>

        <div v-if="$slots.actions" class="flex justify-end px-4 pt-2">
            <slot name="actions" />
        </div>

        <div class="flex items-center justify-between px-4 py-4 text-center">
            <div>
                <p
                    class="text-2xl font-bold text-[#1b1b18] dark:text-[#EDEDEC]"
                >
                    {{ checkInParts.day }}
                </p>
                <p class="text-xs text-[#706f6c] dark:text-[#A1A09A]">
                    {{ checkInParts.monthYear }}
                </p>
            </div>
            <p class="text-xs text-[#706f6c] dark:text-[#A1A09A]">
                ........ {{ nights }} ........
            </p>
            <div>
                <p
                    class="text-2xl font-bold text-[#1b1b18] dark:text-[#EDEDEC]"
                >
                    {{ checkOutParts.day }}
                </p>
                <p class="text-xs text-[#706f6c] dark:text-[#A1A09A]">
                    {{ checkOutParts.monthYear }}
                </p>
            </div>
        </div>

        <ul class="mx-4 flex flex-col gap-1">
            <li
                v-for="line in roomLines.length
                    ? roomLines
                    : ['No rooms selected']"
                :key="line"
                class="rounded bg-[#f5f5f4] px-2 py-1 text-sm font-medium text-[#1b1b18] dark:bg-[#1D1D1B] dark:text-[#EDEDEC]"
            >
                {{ line }}
            </li>
        </ul>

        <div
            v-if="guest || statusLabel"
            class="mt-3 flex items-center justify-between gap-3 border-t border-[#e3e3e0] px-4 py-3 dark:border-[#3E3E3A]"
        >
            <div v-if="guest" class="min-w-0 space-y-0.5">
                <p
                    class="truncate text-sm font-semibold text-[#1b1b18] dark:text-[#EDEDEC]"
                >
                    {{ guest.name }}
                </p>
                <p class="text-xs text-[#706f6c] dark:text-[#A1A09A]">
                    {{ guest.phone || '--' }}
                </p>
                <p class="truncate text-xs text-[#706f6c] dark:text-[#A1A09A]">
                    {{ guest.email || '--' }}
                </p>
            </div>
            <p v-else class="text-sm text-[#706f6c] dark:text-[#A1A09A]">
                No guest added
            </p>
            <div
                v-if="statusLabel"
                class="flex h-16 w-16 shrink-0 -rotate-12 items-center justify-center rounded-full border-2 border-dashed p-1 text-center select-none"
                :class="stampClass"
            >
                <span
                    class="text-[9px] leading-tight font-black tracking-tighter uppercase"
                    >{{ statusLabel }}</span
                >
            </div>
        </div>

        <div
            class="flex items-end justify-between gap-3 border-t border-[#e3e3e0] px-4 py-3 dark:border-[#3E3E3A]"
        >
            <div>
                <p class="text-xs text-[#706f6c] dark:text-[#A1A09A]">
                    TOTAL BILL
                </p>
                <p class="text-lg font-bold text-[#1b1b18] dark:text-[#EDEDEC]">
                    {{ code }} {{ currency(total) }}
                </p>
            </div>
            <div class="text-right">
                <!-- Paid beyond the bill: a credit due back to the guest, not a negative balance. -->
                <template v-if="outstanding < 0">
                    <p class="text-xs text-[#706f6c] dark:text-[#A1A09A]">
                        CREDIT (REFUND DUE)
                    </p>
                    <p
                        class="text-lg font-bold text-emerald-700 dark:text-emerald-400"
                    >
                        {{ code }} {{ currency(-outstanding) }}
                    </p>
                </template>
                <template v-else>
                    <p class="text-xs text-[#706f6c] dark:text-[#A1A09A]">
                        OUTSTANDING
                    </p>
                    <p
                        class="text-lg font-bold"
                        :class="
                            guestDue > 0
                                ? 'text-[#f53003] dark:text-[#FF4433]'
                                : 'text-[#1b1b18] dark:text-[#EDEDEC]'
                        "
                    >
                        {{ code }} {{ currency(guestDue) }}
                    </p>
                    <p
                        v-if="companyDue"
                        class="text-xs font-semibold text-emerald-700 dark:text-emerald-400"
                        data-test="summary-company-pays"
                    >
                        Company pays {{ code }} {{ currency(companyDue) }}
                    </p>
                </template>
            </div>
        </div>

        <p
            class="border-t border-[#e3e3e0] px-4 py-3 text-xs font-medium tracking-wide text-[#706f6c] uppercase dark:border-[#3E3E3A] dark:text-[#A1A09A]"
        >
            {{ sourceLabel }}
        </p>

        <div
            v-if="$slots.footer"
            class="flex justify-center gap-2 border-t border-[#e3e3e0] px-4 py-3 dark:border-[#3E3E3A]"
        >
            <slot name="footer" />
        </div>
    </div>
</template>
