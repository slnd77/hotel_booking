<script setup lang="ts">
import { useCurrency } from '@/lib/useCurrency';
import admin from '@/routes/admin';
import { computed, ref, watch } from 'vue';

type Charge = {
    sub_booking_id: number;
    room: string;
    tier_time: string;
    amount: number;
    tax_amount: number;
    total: number;
};

const props = defineProps<{
    propertyId: number;
    bookingId: number;
    kind: 'early_check_in' | 'late_check_out';
    /** The reported check-in / check-out time (ISO, with its UTC offset). */
    reportedAt: string | null;
    subBookingIds: number[];
}>();

/** True when the front desk waives the charge. */
const waive = defineModel<boolean>({ default: false });

const { money } = useCurrency();
const charges = ref<Charge[]>([]);
const total = ref(0);
const standardTime = ref('');
const canWaive = ref(false);

let request = 0;

async function load(): Promise<void> {
    const current = ++request;

    if (!props.subBookingIds.length) {
        charges.value = [];
        total.value = 0;

        return;
    }

    const response = await fetch(
        admin.bookings.stayTimeCharges.preview(
            { property: props.propertyId, booking: props.bookingId },
            {
                query: {
                    kind: props.kind,
                    reported_at: props.reportedAt ?? undefined,
                    sub_booking_ids: props.subBookingIds,
                },
            },
        ).url,
        { headers: { Accept: 'application/json' }, credentials: 'same-origin' },
    );

    // A newer time or room choice was asked for meanwhile.
    if (current !== request || !response.ok) {
        return;
    }

    const data = await response.json();
    charges.value = data.charges;
    total.value = data.total;
    standardTime.value = data.standard_time;
    canWaive.value = data.can_waive;
}

watch(
    () => [props.reportedAt, props.subBookingIds.join(','), props.kind],
    () => void load(),
    { immediate: true },
);

const label = computed(() =>
    props.kind === 'early_check_in' ? 'Early check-in' : 'Late check-out',
);
const reason = computed(() =>
    props.kind === 'early_check_in'
        ? `before the ${standardTime.value} check-in time`
        : `after the ${standardTime.value} check-out time`,
);
</script>

<template>
    <div
        v-if="charges.length"
        class="rounded border border-amber-300 bg-amber-50 px-3 py-2 text-xs text-amber-900 dark:border-amber-800 dark:bg-amber-950 dark:text-amber-200"
        :data-test="`${kind}-charge`"
    >
        <p class="font-semibold">{{ label }} charge: {{ money(total) }}</p>
        <p class="mb-1">
            Checking {{ kind === 'early_check_in' ? 'in' : 'out' }}
            {{ reason }} adds this to the booking.
        </p>
        <ul class="mb-1">
            <li v-for="charge in charges" :key="charge.sub_booking_id">
                {{ charge.room }} ·
                {{ kind === 'early_check_in' ? 'before' : 'from' }}
                {{ charge.tier_time }}: {{ money(charge.amount)
                }}<template v-if="charge.tax_amount > 0">
                    + {{ money(charge.tax_amount) }} tax</template
                >
            </li>
        </ul>
        <label v-if="canWaive" class="flex items-center gap-2">
            <input
                v-model="waive"
                type="checkbox"
                class="h-4 w-4 rounded"
                :data-test="`${kind}-waive`"
            />
            <span>Waive the charge</span>
        </label>
    </div>
</template>
