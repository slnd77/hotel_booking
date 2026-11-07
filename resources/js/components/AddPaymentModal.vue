<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import { useCurrency } from '@/lib/useCurrency';
import admin from '@/routes/admin';
import { usePropertyTime } from '@/lib/usePropertyTime';
import { computed, ref, watch } from 'vue';

/** Times arrive in UTC and are shown in the property's timezone. */
const propertyTime = usePropertyTime();

const { amount, symbol } = useCurrency();

export type ReservationItem = {
    id: number;
    booking_ref_id: string;
    guest_name: string;
    guest_id?: number | null;
    total: number;
    outstanding: number;
    taxes: number;
    status: string;
    /** The booking's folio and its rooms' sub-folios — searchable as folio numbers. */
    folio_ids?: number[];
    /** Its billing windows, in the order a receipt settles them by default (rooms, then the master folio). */
    windows?: FolioWindowOption[];
};

type FolioWindowOption = {
    sub_folio_id: number;
    folio_id: number;
    label: string;
    is_room: boolean;
    balance: number;
};

const props = defineProps<{
    show: boolean;
    property?: { id: number; name: string; abbreviation: string };
    properties?: { id: number; name: string; abbreviation: string }[];
    staff?: { id: number; name: string }[];
    currentUser?: { id: number; name: string };
    reservations?: ReservationItem[];
    countries?: { id: number; name: string; iso2: string; phonecode: string }[];
    /** The property's modes for payments (Settings → Booking → Payment Settings); every mode when not given. */
    paymentModes?: string[];
    /** The booking guest's ledger: when given, the payment can come from its balance. */
    guestLedger?: { balance: number; booking_id: number } | null;
}>();

const emit = defineEmits<{
    close: [];
    saved: [];
}>();

const showAdditionalInfo = ref(false);
const showPrintInfo = ref(false);
const reservationSearchQuery = ref('');
const guestSearchQuery = ref('');
const guestDropdownOpen = ref(false);

const selectedReservationIds = ref<number[]>([]);
const tagAmounts = ref<Record<number, number>>({});

const form = useForm({
    property_id: props.property?.id ?? null,
    payment_received_at: props.property?.id ?? null,
    payment_mode: 'Cash',
    paid_by: 'Guest',
    guest_name: '',
    guest_id: null as number | null,
    total_txn_amount: '',
    // Paid in another currency: the server converts it at the rate of the transaction date.
    currency_code: '',
    currency_amount: '',
    // Entered as the property's wall-clock time; sent as a UTC instant.
    payment_transaction_date: propertyTime.toInput(),
    receipt_number: '',
    collected_by_staff: props.currentUser?.name ?? 'Staff',
    notes: '',
    block_charge: false,
    booking_id: null as number | null,
    voucher_number: '',
    // Print Information
    first_name: '',
    last_name: '',
    tin_no: '',
    address: '',
    country: 'India',
    state: '',
    city: '',
    postal_code: '',
});

watch(
    () => props.show,
    (show) => {
        if (!show) return;
        form.reset();
        form.property_id = props.property?.id ?? null;
        form.payment_received_at = props.property?.id ?? null;
        form.collected_by_staff =
            props.currentUser?.name ?? props.staff?.[0]?.name ?? 'Staff';
        form.payment_transaction_date = propertyTime.toInput();
        selectedReservationIds.value = [];
        tagAmounts.value = {};
        showAdditionalInfo.value = false;
        showPrintInfo.value = false;
        reservationSearchQuery.value = '';
        guestSearchQuery.value = '';
        void loadExchangeRates();

        if (props.reservations?.length === 1) {
            const single = props.reservations[0];
            selectedReservationIds.value = [single.id];
            tagAmounts.value[single.id] = single.outstanding;
            form.booking_id = single.id;
            form.voucher_number = single.booking_ref_id;
            form.total_txn_amount =
                single.outstanding > 0 ? String(single.outstanding) : '';
            if (single.guest_name && single.guest_name !== '—') {
                form.guest_name = single.guest_name;
                populatePrintNames(single.guest_name);
            }
        }
    },
    { immediate: true },
);

watch(
    () => form.total_txn_amount,
    (val) => {
        const amt = parseFloat(val);
        if (selectedReservationIds.value.length === 1) {
            const id = selectedReservationIds.value[0];
            tagAmounts.value[id] = isNaN(amt) ? 0 : amt;
        }
    },
);

const allPaymentModes = [
    'Cash',
    'Credit Card',
    'Debit Card',
    'Quick Pay',
    'Net Banking',
    'NEFT',
    'RTGS',
    'IMPS',
    'Cheque/DD',
    'Payment Wallet',
    'UPI',
    'USSD',
    'Others',
    'Debit Note',
    'Credit Note',
    'Bills Payment',
    'Gift Card',
    'Commission',
    'TDS',
    'Air Pay',
    'Bank Transfer',
    'AUB Paymate',
    'QR Code',
    'Bank Deposit',
    'MoMo',
    'Bad Debts',
    'Gift Certificate',
    'AmEx Card',
    'Razor Pay',
    'Loyalty Rewards',
    'HO Cash',
    'Transfer/POS',
];

/** Not a receipt: the amount moves from the guest's ledger onto the booking's folio. */
const GUEST_LEDGER = 'Guest Ledger';

const paymentModeOptions = computed(() => [
    ...(props.paymentModes?.length ? props.paymentModes : allPaymentModes),
    ...(props.guestLedger && props.guestLedger.balance > 0
        ? [GUEST_LEDGER]
        : []),
]);

const fromGuestLedger = computed(
    () => form.payment_mode === GUEST_LEDGER && !!props.guestLedger,
);

/** At most the ledger balance, and at most what the booking owes. */
const guestLedgerMax = computed(() => {
    const booking = (props.reservations ?? []).find(
        (r) => r.id === props.guestLedger?.booking_id,
    );

    return roundMoney(
        Math.min(
            props.guestLedger?.balance ?? 0,
            Math.max(0, booking?.outstanding ?? 0),
        ),
    );
});

const guestLedgerError = computed(() =>
    fromGuestLedger.value && numericAmount.value > guestLedgerMax.value
        ? `At most ${formatCurrency(guestLedgerMax.value)} can come from the guest ledger.`
        : '',
);

// Paying from the ledger: the amount starts at what it can cover, in the property's currency.
watch(fromGuestLedger, (on) => {
    if (on) {
        form.currency_code = '';
        form.total_txn_amount = String(
            Math.min(
                numericAmount.value || guestLedgerMax.value,
                guestLedgerMax.value,
            ),
        );
    }
});

// A mode switched off for the property (Cash included) isn't preselected.
watch(
    [() => props.show, paymentModeOptions],
    () => {
        if (!paymentModeOptions.value.includes(form.payment_mode)) {
            form.payment_mode = paymentModeOptions.value[0] ?? 'Cash';
        }
    },
    { immediate: true },
);

const paidByOptions = ['Guest', 'Company'];

// Other currencies the property has a rate for today.
const exchangeRates = ref<{ currency_code: string; rate: number }[]>([]);

async function loadExchangeRates(): Promise<void> {
    if (!props.property) {
        return;
    }

    try {
        const response = await fetch(
            admin.exchangeRates.current(props.property.id).url,
            { headers: { Accept: 'application/json' } },
        );
        exchangeRates.value = response.ok ? (await response.json()).rates : [];
    } catch {
        exchangeRates.value = [];
    }
}

const selectedRate = computed(
    () =>
        exchangeRates.value.find(
            (rate) => rate.currency_code === form.currency_code,
        ) ?? null,
);

// The amount in the property's currency follows the foreign amount (the server converts it again on save).
watch(
    () => [form.currency_code, form.currency_amount],
    () => {
        const foreign = parseFloat(form.currency_amount);

        if (selectedRate.value && !isNaN(foreign)) {
            form.total_txn_amount = String(
                Math.round(foreign * selectedRate.value.rate * 100) / 100,
            );
        }
    },
);

const numericAmount = computed(() => {
    const val = parseFloat(form.total_txn_amount);
    return isNaN(val) ? 0 : val;
});

const totalTagAmount = computed(() => {
    let sum = 0;
    for (const id of selectedReservationIds.value) {
        sum += tagAmounts.value[id] || 0;
    }
    return sum;
});

const untaggedAmount = computed(() => {
    return Math.max(0, numericAmount.value - totalTagAmount.value);
});

const filteredReservations = computed(() => {
    const list = props.reservations ?? [];
    const query = reservationSearchQuery.value.trim().toLowerCase();
    const guestFilter = form.guest_name.trim().toLowerCase();

    if (!query && !guestFilter) {
        return list;
    }

    // "33", "#33" or "folio 33" finds the booking whose folio or room sub-folio is #33.
    const folioNumber = query.replace(/^(folio|sub-?folio)?\s*#?\s*/, '');

    return list.filter((r) => {
        const matchesQuery =
            !query ||
            r.booking_ref_id.toLowerCase().includes(query) ||
            r.guest_name.toLowerCase().includes(query) ||
            (/^\d+$/.test(folioNumber) &&
                (r.folio_ids ?? []).includes(Number(folioNumber)));
        const matchesGuest =
            !guestFilter || r.guest_name.toLowerCase().includes(guestFilter);
        return matchesQuery && matchesGuest;
    });
});

const allChecked = computed({
    get: () =>
        filteredReservations.value.length > 0 &&
        filteredReservations.value.every((r) =>
            selectedReservationIds.value.includes(r.id),
        ),
    set: (checked: boolean) => {
        if (checked) {
            filteredReservations.value.forEach((r) => {
                if (!selectedReservationIds.value.includes(r.id)) {
                    selectedReservationIds.value.push(r.id);
                    if (!tagAmounts.value[r.id]) {
                        tagAmounts.value[r.id] = r.outstanding;
                    }
                }
            });
        } else {
            selectedReservationIds.value = [];
        }
    },
});

function toggleReservation(r: ReservationItem): void {
    const index = selectedReservationIds.value.indexOf(r.id);
    if (index >= 0) {
        selectedReservationIds.value.splice(index, 1);
        delete tagAmounts.value[r.id];
    } else {
        selectedReservationIds.value.push(r.id);
        const remainingToTag = Math.max(
            0,
            numericAmount.value - totalTagAmount.value,
        );
        tagAmounts.value[r.id] =
            remainingToTag > 0
                ? Math.min(remainingToTag, r.outstanding)
                : r.outstanding;
    }

    if (selectedReservationIds.value.length > 0) {
        const firstSelected = (props.reservations ?? []).find(
            (x) => x.id === selectedReservationIds.value[0],
        );
        if (firstSelected) {
            form.booking_id = firstSelected.id;
            form.voucher_number = firstSelected.booking_ref_id;
            if (!form.guest_name && firstSelected.guest_name !== '—') {
                form.guest_name = firstSelected.guest_name;
                populatePrintNames(firstSelected.guest_name);
            }
        }
    } else {
        form.booking_id = null;
        form.voucher_number = '';
    }
}

// ── Splitting a reservation's tagged amount over its folio and sub-folios ──
/** Amount per sub-folio id, per reservation. */
const windowAmounts = ref<Record<number, Record<number, number>>>({});
/** Reservations whose split was edited by hand (no longer re-filled automatically). */
const manualSplit = ref<Record<number, boolean>>({});

const roundMoney = (value: number): number => Math.round(value * 100) / 100;

function hasSubFolios(r: ReservationItem): boolean {
    return (r.windows?.length ?? 0) > 1;
}

/** The default split: rooms that still owe, in order, then the master folio takes the rest. */
function autoSplit(r: ReservationItem, tagged: number): Record<number, number> {
    const windows = r.windows ?? [];
    const split: Record<number, number> = {};
    let remaining = roundMoney(tagged);

    windows.forEach((window) => (split[window.sub_folio_id] = 0));

    for (const window of windows.filter((candidate) => candidate.is_room)) {
        const share = roundMoney(
            Math.min(remaining, Math.max(0, window.balance)),
        );
        split[window.sub_folio_id] = share;
        remaining = roundMoney(remaining - share);
    }

    const rest =
        windows.find((window) => !window.is_room) ??
        windows[windows.length - 1];

    if (rest && remaining > 0) {
        split[rest.sub_folio_id] = roundMoney(
            split[rest.sub_folio_id] + remaining,
        );
    }

    return split;
}

function splitTotal(r: ReservationItem): number {
    return roundMoney(
        Object.values(windowAmounts.value[r.id] ?? {}).reduce(
            (sum, value) => sum + (Number(value) || 0),
            0,
        ),
    );
}

function splitMismatch(r: ReservationItem): boolean {
    return (
        hasSubFolios(r) &&
        Math.abs(splitTotal(r) - (tagAmounts.value[r.id] || 0)) >= 0.01
    );
}

function onWindowAmountInput(
    r: ReservationItem,
    subFolioId: number,
    event: Event,
): void {
    const value = parseFloat((event.target as HTMLInputElement).value);
    windowAmounts.value[r.id] = {
        ...windowAmounts.value[r.id],
        [subFolioId]: isNaN(value) ? 0 : value,
    };
    manualSplit.value[r.id] = true;
}

function resetSplit(r: ReservationItem): void {
    manualSplit.value[r.id] = false;
    windowAmounts.value[r.id] = autoSplit(r, tagAmounts.value[r.id] || 0);
}

// Re-fill the automatic splits whenever the tagged amounts or the selection change.
watch(
    [tagAmounts, selectedReservationIds],
    () => {
        for (const r of props.reservations ?? []) {
            if (
                selectedReservationIds.value.includes(r.id) &&
                hasSubFolios(r) &&
                !manualSplit.value[r.id]
            ) {
                windowAmounts.value[r.id] = autoSplit(
                    r,
                    tagAmounts.value[r.id] || 0,
                );
            }
        }
    },
    { deep: true, immediate: true },
);

// A new payment starts from the automatic splits again.
watch(
    () => props.show,
    (show) => {
        if (show) {
            manualSplit.value = {};
            windowAmounts.value = {};
        }
    },
);

const anySplitMismatch = computed(() =>
    (props.reservations ?? []).some(
        (r) => selectedReservationIds.value.includes(r.id) && splitMismatch(r),
    ),
);

function onTagAmountInput(r: ReservationItem, event: Event): void {
    const val = parseFloat((event.target as HTMLInputElement).value);
    tagAmounts.value[r.id] = isNaN(val) ? 0 : val;
    if (!selectedReservationIds.value.includes(r.id)) {
        selectedReservationIds.value.push(r.id);
    }
}

function selectGuest(name: string): void {
    form.guest_name = name;
    populatePrintNames(name);
    guestDropdownOpen.value = false;
}

function populatePrintNames(fullName: string): void {
    const parts = fullName.trim().split(' ');
    form.first_name = parts[0] || '';
    form.last_name = parts.slice(1).join(' ') || '';
}

function formatCurrency(val: number): string {
    return amount(val);
}

// Taxes calculation for selected reservations
const calculatedTaxes = computed(() => {
    if (selectedReservationIds.value.length === 0 || numericAmount.value <= 0) {
        return null;
    }

    let taxSum = 0;
    selectedReservationIds.value.forEach((id) => {
        const res = (props.reservations ?? []).find((x) => x.id === id);
        if (res && res.total > 0) {
            const ratio = (tagAmounts.value[id] || 0) / res.total;
            taxSum += res.taxes * ratio;
        }
    });

    return {
        amount: Math.round(taxSum * 100) / 100,
        cgst: Math.round((taxSum / 2) * 100) / 100,
        sgst: Math.round((taxSum / 2) * 100) / 100,
    };
});

function submit(): void {
    if (fromGuestLedger.value && props.guestLedger && props.property) {
        form.processing = true;
        router.post(
            admin.bookings.guestLedger.apply({
                property: props.property.id,
                booking: props.guestLedger.booking_id,
            }).url,
            { amount: numericAmount.value },
            {
                preserveScroll: true,
                onSuccess: () => {
                    emit('saved');
                    emit('close');
                },
                onFinish: () => (form.processing = false),
            },
        );

        return;
    }

    const allocations = selectedReservationIds.value
        .map((id) => {
            const reservation = (props.reservations ?? []).find(
                (r) => r.id === id,
            );
            const split =
                reservation && hasSubFolios(reservation)
                    ? windowAmounts.value[id]
                    : null;

            return {
                booking_id: id,
                tagged_amount: tagAmounts.value[id] || 0,
                // Where on the booking's folio / sub-folios the amount goes.
                ...(split
                    ? {
                          windows: Object.entries(split).map(
                              ([subFolioId, value]) => ({
                                  sub_folio_id: Number(subFolioId),
                                  amount: roundMoney(Number(value) || 0),
                              }),
                          ),
                      }
                    : {}),
            };
        })
        .filter((a) => a.tagged_amount > 0);

    form.transform((data) => ({
        ...data,
        currency_code: data.currency_code || null,
        currency_amount: data.currency_code ? data.currency_amount : null,
        payment_transaction_date: propertyTime.fromInput(
            data.payment_transaction_date,
        ),
        tagged_amount: totalTagAmount.value,
        untagged_amount: untaggedAmount.value,
        allocations,
    })).post('/admin/databases/payments', {
        preserveScroll: true,
        onSuccess: () => {
            emit('saved');
            emit('close');
        },
    });
}

function close(): void {
    emit('close');
}

const fieldClass =
    'block w-full rounded border border-[#ccc] bg-white px-2.5 py-1.5 text-xs text-[#1b1b18] focus:border-[#0284c7] focus:ring-1 focus:ring-[#0284c7] focus:outline-none dark:border-[#444] dark:bg-[#111] dark:text-[#EDEDEC]';
const labelClass = 'text-xs text-[#555] dark:text-[#aaa] font-medium';
</script>

<template>
    <div
        v-if="show"
        class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4"
        @click.self="close"
    >
        <div
            class="flex max-h-[92vh] w-full max-w-5xl flex-col overflow-hidden rounded bg-white shadow-2xl dark:bg-[#1a1a1a]"
        >
            <!-- Dark Header -->
            <div
                class="flex items-center justify-between bg-[#263238] px-4 py-2.5 text-white"
            >
                <h2 class="text-sm font-semibold tracking-wide uppercase">
                    Add New Payment
                </h2>
                <button
                    type="button"
                    class="text-base leading-none text-white/80 hover:text-white"
                    @click="close"
                >
                    ✕
                </button>
            </div>

            <!-- Green Summary Banner -->
            <div
                class="flex flex-wrap items-center justify-between bg-[#2e7d32] px-6 py-2.5 text-white"
            >
                <div class="flex items-center gap-8">
                    <div>
                        <div class="text-xl font-bold tracking-tight">
                            {{ symbol }} {{ formatCurrency(numericAmount) }}
                        </div>
                        <div class="text-[11px] font-medium text-emerald-100">
                            Total Amount
                        </div>
                    </div>
                    <div>
                        <div class="text-xl font-bold tracking-tight">
                            {{ symbol }} {{ formatCurrency(untaggedAmount) }}
                        </div>
                        <div class="text-[11px] font-medium text-emerald-100">
                            Untagged Amt
                        </div>
                    </div>
                </div>

                <div class="text-right text-xs">
                    <div>
                        Created By:
                        <strong class="font-semibold">{{
                            currentUser?.name || staff?.[0]?.name || 'Admin'
                        }}</strong>
                    </div>
                    <div class="text-[11px] text-emerald-100">
                        Last Printed By: —
                    </div>
                </div>
            </div>

            <!-- Body Grid: Left Form (50%) | Right Tag Transactions (50%) -->
            <div class="grid grid-cols-1 overflow-y-auto lg:grid-cols-2">
                <!-- Left Column -->
                <div
                    class="space-y-3 border-b border-[#e0e0e0] p-5 lg:border-r lg:border-b-0 dark:border-[#333]"
                >
                    <!-- Row 1: Payment Mode & Paid By -->
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label :class="labelClass">Payment Mode</label>
                            <select
                                v-model="form.payment_mode"
                                :class="fieldClass"
                                class="mt-1"
                                data-test="payment-mode"
                            >
                                <option
                                    v-for="mode in paymentModeOptions"
                                    :key="mode"
                                    :value="mode"
                                >
                                    {{
                                        mode === GUEST_LEDGER
                                            ? `Guest Ledger (balance ${formatCurrency(guestLedger?.balance ?? 0)})`
                                            : mode
                                    }}
                                </option>
                            </select>
                        </div>
                        <div>
                            <label :class="labelClass">Paid By</label>
                            <select
                                v-model="form.paid_by"
                                :class="fieldClass"
                                class="mt-1"
                            >
                                <option
                                    v-for="pb in paidByOptions"
                                    :key="pb"
                                    :value="pb"
                                >
                                    {{ pb }}
                                </option>
                            </select>
                        </div>
                    </div>

                    <!-- Row 2: Guest Name -->
                    <div class="relative">
                        <label :class="labelClass">Guest Name :</label>
                        <div class="relative mt-1">
                            <input
                                v-model="form.guest_name"
                                type="text"
                                required
                                placeholder="Search by name, Contact No., Email, Guest ID"
                                :class="fieldClass"
                                @focus="guestDropdownOpen = true"
                                @input="populatePrintNames(form.guest_name)"
                            />
                            <button
                                type="button"
                                class="absolute top-2 right-2 text-[#777]"
                                @click="guestDropdownOpen = !guestDropdownOpen"
                            >
                                ▾
                            </button>
                        </div>

                        <!-- Dropdown suggestions from reservations -->
                        <div
                            v-if="
                                guestDropdownOpen &&
                                reservations &&
                                reservations.length
                            "
                            class="absolute z-20 mt-1 max-h-40 w-full overflow-y-auto rounded border border-[#ccc] bg-white shadow-lg dark:border-[#444] dark:bg-[#1a1a1a]"
                        >
                            <div
                                v-for="r in reservations.filter(
                                    (x) => x.guest_name !== '—',
                                )"
                                :key="r.id"
                                class="cursor-pointer px-3 py-1.5 text-xs hover:bg-[#f0f9ff] dark:hover:bg-[#252525]"
                                @click="selectGuest(r.guest_name)"
                            >
                                <span
                                    class="font-medium text-[#111] dark:text-[#eee]"
                                    >{{ r.guest_name }}</span
                                >
                                <span class="ml-2 text-[10px] text-[#777]"
                                    >({{ r.booking_ref_id }})</span
                                >
                            </div>
                        </div>
                    </div>

                    <!-- Row 3: Amount (or paid in another currency) -->
                    <p
                        v-if="fromGuestLedger"
                        class="rounded bg-emerald-50 px-2.5 py-2 text-[11px] text-emerald-800 dark:bg-emerald-950/30 dark:text-emerald-300"
                    >
                        Moves up to {{ symbol }}
                        {{ formatCurrency(guestLedgerMax) }} from the guest's
                        ledger onto this booking. No receipt is issued: the
                        money was received earlier.
                    </p>
                    <div
                        v-if="exchangeRates.length && !fromGuestLedger"
                        class="grid grid-cols-2 gap-3"
                    >
                        <div>
                            <label :class="labelClass">Paid in</label>
                            <select
                                v-model="form.currency_code"
                                :class="fieldClass"
                                class="mt-1"
                            >
                                <option value="">
                                    {{ symbol }} (property currency)
                                </option>
                                <option
                                    v-for="rate in exchangeRates"
                                    :key="rate.currency_code"
                                    :value="rate.currency_code"
                                >
                                    {{ rate.currency_code }} (1 =
                                    {{ rate.rate }})
                                </option>
                            </select>
                        </div>
                        <div v-if="form.currency_code">
                            <label :class="labelClass"
                                >Amount in {{ form.currency_code }}</label
                            >
                            <input
                                v-model="form.currency_amount"
                                type="number"
                                step="0.01"
                                min="0.01"
                                required
                                placeholder="0.00"
                                :class="fieldClass"
                                class="mt-1"
                            />
                            <p
                                v-if="form.errors.currency_code"
                                class="mt-1 text-[11px] text-red-600"
                            >
                                {{ form.errors.currency_code }}
                            </p>
                        </div>
                    </div>
                    <div>
                        <label :class="labelClass"
                            >Amount<span v-if="form.currency_code">
                                ({{ symbol }}, converted)</span
                            ></label
                        >
                        <input
                            v-model="form.total_txn_amount"
                            type="number"
                            step="0.01"
                            required
                            placeholder="0.00"
                            :readonly="!!form.currency_code"
                            :max="fromGuestLedger ? guestLedgerMax : undefined"
                            :class="fieldClass"
                            class="mt-1"
                        />
                        <p
                            v-if="guestLedgerError"
                            class="mt-1 text-[11px] text-red-600"
                        >
                            {{ guestLedgerError }}
                        </p>
                    </div>

                    <!-- Row 4: Transaction Date ⓘ -->
                    <div>
                        <div class="flex items-center gap-1">
                            <label :class="labelClass">Transaction Date</label>
                            <span
                                class="cursor-help text-[10px] text-[#0284c7]"
                                title="Transaction date and time"
                                >ⓘ</span
                            >
                        </div>
                        <input
                            v-model="form.payment_transaction_date"
                            type="datetime-local"
                            :class="fieldClass"
                            class="mt-1"
                        />
                    </div>

                    <!-- Row 5: Receipt Number ⓘ -->
                    <div>
                        <div class="flex items-center gap-1">
                            <label :class="labelClass">Receipt Number</label>
                            <span
                                class="cursor-help text-[10px] text-[#0284c7]"
                                title="Value in the field, if provided, overrides the auto-increment Receipt Series number generated by the system. This should be used only for Admin purposes as can lead to potential conflicts in data."
                            >
                                ⓘ
                            </span>
                        </div>
                        <input
                            v-model="form.receipt_number"
                            type="text"
                            placeholder="Auto-generated if left blank"
                            :class="fieldClass"
                            class="mt-1"
                        />
                    </div>

                    <!-- Collapsible: Additional Information -->
                    <div class="border-t border-[#eee] pt-2 dark:border-[#333]">
                        <button
                            type="button"
                            class="flex w-full items-center justify-between text-xs font-semibold text-[#1b1b18] hover:text-[#0284c7] dark:text-[#EDEDEC]"
                            @click="showAdditionalInfo = !showAdditionalInfo"
                        >
                            <span>Additional Information</span>
                            <span>{{ showAdditionalInfo ? '−' : '+' }}</span>
                        </button>

                        <div v-if="showAdditionalInfo" class="mt-3 space-y-3">
                            <div class="grid grid-cols-2 gap-3">
                                <div>
                                    <div class="flex items-center gap-1">
                                        <label :class="labelClass"
                                            >Payment Received At</label
                                        >
                                        <span
                                            class="text-[10px] text-[#0284c7]"
                                            title="Property where payment is received"
                                            >ⓘ</span
                                        >
                                    </div>
                                    <select
                                        v-model="form.payment_received_at"
                                        :class="fieldClass"
                                        class="mt-1"
                                    >
                                        <option
                                            v-for="p in properties"
                                            :key="p.id"
                                            :value="p.id"
                                        >
                                            {{ p.name }}
                                        </option>
                                    </select>
                                </div>
                                <div>
                                    <label :class="labelClass"
                                        >Payment Received By</label
                                    >
                                    <select
                                        v-model="form.collected_by_staff"
                                        :class="fieldClass"
                                        class="mt-1"
                                    >
                                        <option
                                            v-for="s in staff"
                                            :key="s.id"
                                            :value="s.name"
                                        >
                                            {{ s.name }}
                                        </option>
                                    </select>
                                </div>
                            </div>

                            <div>
                                <label :class="labelClass">Payment Notes</label>
                                <textarea
                                    v-model="form.notes"
                                    rows="2"
                                    :class="fieldClass"
                                    class="mt-1"
                                    placeholder="Enter any notes or remarks here..."
                                ></textarea>
                            </div>

                            <label
                                class="flex items-center gap-2 text-xs text-[#555] dark:text-[#aaa]"
                            >
                                <input
                                    v-model="form.block_charge"
                                    type="checkbox"
                                    class="rounded border-[#ccc]"
                                />
                                Block Charge
                            </label>
                        </div>
                    </div>

                    <!-- Collapsible: Print Information -->
                    <div class="border-t border-[#eee] pt-2 dark:border-[#333]">
                        <button
                            type="button"
                            class="flex w-full items-center justify-between text-xs font-semibold text-[#1b1b18] hover:text-[#0284c7] dark:text-[#EDEDEC]"
                            @click="showPrintInfo = !showPrintInfo"
                        >
                            <span>Print Information</span>
                            <span>{{ showPrintInfo ? '−' : '+' }}</span>
                        </button>

                        <div v-if="showPrintInfo" class="mt-3 space-y-3">
                            <div class="grid grid-cols-2 gap-3">
                                <div>
                                    <label :class="labelClass"
                                        >First Name</label
                                    >
                                    <input
                                        v-model="form.first_name"
                                        type="text"
                                        :class="fieldClass"
                                        class="mt-1"
                                    />
                                </div>
                                <div>
                                    <label :class="labelClass">Last Name</label>
                                    <input
                                        v-model="form.last_name"
                                        type="text"
                                        :class="fieldClass"
                                        class="mt-1"
                                    />
                                </div>
                            </div>

                            <div>
                                <label :class="labelClass">TIN No.</label>
                                <input
                                    v-model="form.tin_no"
                                    type="text"
                                    :class="fieldClass"
                                    class="mt-1"
                                />
                            </div>

                            <div>
                                <div class="flex items-center gap-1">
                                    <label :class="labelClass">Address</label>
                                    <span class="text-[10px] text-[#0284c7]"
                                        >ⓘ</span
                                    >
                                </div>
                                <input
                                    v-model="form.address"
                                    type="text"
                                    :class="fieldClass"
                                    class="mt-1"
                                />
                            </div>

                            <div>
                                <label :class="labelClass">Country*</label>
                                <select
                                    v-model="form.country"
                                    :class="fieldClass"
                                    class="mt-1"
                                >
                                    <option
                                        v-for="c in countries"
                                        :key="c.id"
                                        :value="c.name"
                                    >
                                        {{ c.name }}
                                    </option>
                                    <option
                                        v-if="!countries || !countries.length"
                                        value="India"
                                    >
                                        India
                                    </option>
                                </select>
                            </div>

                            <div class="grid grid-cols-2 gap-3">
                                <div>
                                    <label :class="labelClass">State*</label>
                                    <input
                                        v-model="form.state"
                                        type="text"
                                        placeholder="--Select State--"
                                        :class="fieldClass"
                                        class="mt-1"
                                    />
                                </div>
                                <div>
                                    <label :class="labelClass">City*</label>
                                    <input
                                        v-model="form.city"
                                        type="text"
                                        placeholder="--Select City--"
                                        :class="fieldClass"
                                        class="mt-1"
                                    />
                                </div>
                            </div>

                            <div>
                                <label :class="labelClass">Postal Code*</label>
                                <input
                                    v-model="form.postal_code"
                                    type="text"
                                    :class="fieldClass"
                                    class="mt-1"
                                />
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Right Column: Tag Transactions & Taxes -->
                <div
                    class="flex flex-col justify-between p-5 dark:bg-[#1f1f1f]"
                >
                    <div class="space-y-4">
                        <!-- Top Header -->
                        <div
                            class="flex items-center justify-between border-b border-[#e0e0e0] pb-2 dark:border-[#333]"
                        >
                            <h3
                                class="text-xs font-bold tracking-wider text-[#333] uppercase dark:text-[#ddd]"
                            >
                                Tag Transactions
                            </h3>
                            <div
                                class="text-xs font-semibold text-[#333] dark:text-[#ddd]"
                            >
                                TOTAL TAG AMT.
                                <span
                                    class="ml-1 font-bold text-[#2e7d32] dark:text-emerald-400"
                                    >{{ symbol }}
                                    {{ formatCurrency(totalTagAmount) }}</span
                                >
                            </div>
                        </div>

                        <!-- Search Folio / Guest -->
                        <div class="relative">
                            <input
                                v-model="reservationSearchQuery"
                                type="text"
                                placeholder="Search by Folio Number, Guest Name"
                                :class="fieldClass"
                            />
                            <span class="absolute top-2 right-2.5 text-[#777]"
                                >▾</span
                            >
                        </div>

                        <!-- Tag Transactions Table -->
                        <div
                            class="max-h-60 overflow-y-auto rounded border border-[#e0e0e0] dark:border-[#333]"
                        >
                            <table
                                class="min-w-full divide-y divide-[#e0e0e0] text-xs dark:divide-[#333]"
                            >
                                <thead
                                    class="bg-[#f5f5f5] text-[#555] dark:bg-[#252525] dark:text-[#aaa]"
                                >
                                    <tr>
                                        <th class="w-8 px-2 py-2 text-center">
                                            <input
                                                v-model="allChecked"
                                                type="checkbox"
                                                class="rounded border-[#ccc]"
                                            />
                                        </th>
                                        <th
                                            class="px-2 py-2 text-left font-semibold"
                                        >
                                            Reservation ID
                                        </th>
                                        <th
                                            class="px-2 py-2 text-right font-semibold"
                                        >
                                            Total Amt.
                                        </th>
                                        <th
                                            class="px-2 py-2 text-right font-semibold"
                                        >
                                            Outstd. Amt.
                                        </th>
                                        <th
                                            class="px-2 py-2 text-right font-semibold"
                                        >
                                            Tag. Amt.
                                        </th>
                                    </tr>
                                </thead>
                                <tbody
                                    class="divide-y divide-[#e0e0e0] bg-white text-[#333] dark:divide-[#333] dark:bg-[#1a1a1a] dark:text-[#ddd]"
                                >
                                    <template
                                        v-for="r in filteredReservations"
                                        :key="r.id"
                                    >
                                        <tr
                                            class="hover:bg-blue-50/50 dark:hover:bg-neutral-800/60"
                                            :class="{
                                                'bg-emerald-50/40 dark:bg-emerald-950/20':
                                                    selectedReservationIds.includes(
                                                        r.id,
                                                    ),
                                            }"
                                        >
                                            <td class="px-2 py-2 text-center">
                                                <input
                                                    type="checkbox"
                                                    :checked="
                                                        selectedReservationIds.includes(
                                                            r.id,
                                                        )
                                                    "
                                                    class="rounded border-[#ccc]"
                                                    @change="
                                                        toggleReservation(r)
                                                    "
                                                />
                                            </td>
                                            <td
                                                class="cursor-pointer px-2 py-2 font-medium text-[#0284c7]"
                                                @click="toggleReservation(r)"
                                            >
                                                <div>
                                                    {{ r.booking_ref_id }}
                                                </div>
                                                <div
                                                    class="text-[10px] text-[#777]"
                                                >
                                                    {{ r.guest_name }}
                                                </div>
                                                <div
                                                    v-if="r.folio_ids?.length"
                                                    class="font-mono text-[10px] text-[#999]"
                                                >
                                                    Folio #{{
                                                        r.folio_ids.join(', #')
                                                    }}
                                                </div>
                                            </td>
                                            <td class="px-2 py-2 text-right">
                                                {{ symbol }}
                                                {{ formatCurrency(r.total) }}
                                            </td>
                                            <td
                                                class="px-2 py-2 text-right font-semibold text-rose-600"
                                            >
                                                {{ symbol }}
                                                {{
                                                    formatCurrency(
                                                        r.outstanding,
                                                    )
                                                }}
                                            </td>
                                            <td class="px-2 py-2 text-right">
                                                <input
                                                    type="number"
                                                    step="0.01"
                                                    :value="
                                                        tagAmounts[r.id] ?? ''
                                                    "
                                                    class="w-24 rounded border border-[#ccc] px-1.5 py-0.5 text-right text-xs focus:border-[#0284c7] focus:outline-none dark:border-[#444] dark:bg-[#111]"
                                                    placeholder="0.00"
                                                    @input="
                                                        onTagAmountInput(
                                                            r,
                                                            $event,
                                                        )
                                                    "
                                                />
                                            </td>
                                        </tr>
                                        <!-- The tagged amount, split over the booking's sub-folios and folio. -->
                                        <tr
                                            v-if="
                                                selectedReservationIds.includes(
                                                    r.id,
                                                ) && hasSubFolios(r)
                                            "
                                            class="bg-[#fafafa] dark:bg-[#141414]"
                                        >
                                            <td></td>
                                            <td colspan="4" class="px-2 pb-2">
                                                <div
                                                    class="mb-1 flex items-center justify-between text-[10px] font-semibold tracking-wide text-[#777] uppercase"
                                                >
                                                    <span
                                                        >Allocate to
                                                        folios</span
                                                    >
                                                    <button
                                                        v-if="manualSplit[r.id]"
                                                        type="button"
                                                        class="text-[#0284c7] normal-case hover:underline"
                                                        @click="resetSplit(r)"
                                                    >
                                                        Auto allocate
                                                    </button>
                                                    <span
                                                        v-else
                                                        class="font-normal normal-case"
                                                        >Auto-allocated in
                                                        order</span
                                                    >
                                                </div>
                                                <div
                                                    v-for="window in r.windows"
                                                    :key="window.sub_folio_id"
                                                    class="flex items-center justify-between gap-2 py-0.5 text-xs"
                                                >
                                                    <span
                                                        class="min-w-0 truncate"
                                                    >
                                                        {{ window.label }}
                                                        <span
                                                            class="font-mono text-[10px] text-[#999]"
                                                            >{{
                                                                window.is_room
                                                                    ? 'Sub-folio'
                                                                    : 'Folio'
                                                            }}
                                                            #{{
                                                                window.folio_id
                                                            }}</span
                                                        >
                                                        <span
                                                            class="text-[10px]"
                                                            :class="
                                                                window.balance >
                                                                0
                                                                    ? 'text-rose-600'
                                                                    : 'text-[#999]'
                                                            "
                                                        >
                                                            ·
                                                            {{
                                                                window.balance >
                                                                0
                                                                    ? 'Due'
                                                                    : window.balance <
                                                                        0
                                                                      ? 'Credit'
                                                                      : 'Settled'
                                                            }}
                                                            {{
                                                                formatCurrency(
                                                                    Math.abs(
                                                                        window.balance,
                                                                    ),
                                                                )
                                                            }}</span
                                                        >
                                                    </span>
                                                    <input
                                                        type="number"
                                                        min="0"
                                                        step="0.01"
                                                        :value="
                                                            windowAmounts[
                                                                r.id
                                                            ]?.[
                                                                window
                                                                    .sub_folio_id
                                                            ] ?? 0
                                                        "
                                                        :aria-label="`Amount for ${window.label}`"
                                                        class="w-24 shrink-0 rounded border border-[#ccc] px-1.5 py-0.5 text-right text-xs focus:border-[#0284c7] focus:outline-none dark:border-[#444] dark:bg-[#111]"
                                                        @input="
                                                            onWindowAmountInput(
                                                                r,
                                                                window.sub_folio_id,
                                                                $event,
                                                            )
                                                        "
                                                    />
                                                </div>
                                                <p
                                                    v-if="splitMismatch(r)"
                                                    class="mt-1 text-[11px] text-rose-600"
                                                >
                                                    The folio amounts add up to
                                                    {{
                                                        formatCurrency(
                                                            splitTotal(r),
                                                        )
                                                    }}, not the tagged
                                                    {{
                                                        formatCurrency(
                                                            tagAmounts[r.id] ||
                                                                0,
                                                        )
                                                    }}.
                                                </p>
                                            </td>
                                        </tr>
                                    </template>

                                    <tr
                                        v-if="filteredReservations.length === 0"
                                    >
                                        <td
                                            colspan="5"
                                            class="py-12 text-center text-xs text-[#888] dark:text-[#666]"
                                        >
                                            There is no item to list.
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        <!-- Taxes Section -->
                        <div
                            class="border-t border-[#e0e0e0] pt-3 dark:border-[#333]"
                        >
                            <h4
                                class="text-xs font-bold tracking-wider text-[#333] uppercase dark:text-[#ddd]"
                            >
                                Taxes
                            </h4>

                            <div
                                v-if="!calculatedTaxes"
                                class="mt-2 text-xs text-rose-500 italic"
                            >
                                Please enter payment amount and select the
                                reservation[s] to calculate taxes.
                            </div>

                            <div
                                v-else
                                class="mt-2 space-y-1 rounded bg-slate-50 p-2.5 text-xs dark:bg-[#252525]"
                            >
                                <div class="flex justify-between font-semibold">
                                    <span>Total Tax Calculated:</span>
                                    <span
                                        >{{ symbol }}
                                        {{
                                            formatCurrency(
                                                calculatedTaxes.amount,
                                            )
                                        }}</span
                                    >
                                </div>
                                <div
                                    class="flex justify-between text-[11px] text-[#666] dark:text-[#aaa]"
                                >
                                    <span>CGST:</span>
                                    <span
                                        >{{ symbol }}
                                        {{
                                            formatCurrency(calculatedTaxes.cgst)
                                        }}</span
                                    >
                                </div>
                                <div
                                    class="flex justify-between text-[11px] text-[#666] dark:text-[#aaa]"
                                >
                                    <span>SGST:</span>
                                    <span
                                        >{{ symbol }}
                                        {{
                                            formatCurrency(calculatedTaxes.sgst)
                                        }}</span
                                    >
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Footer Action Buttons -->
                    <div
                        class="mt-6 flex items-center justify-between border-t border-[#e0e0e0] pt-4 dark:border-[#333]"
                    >
                        <button
                            type="button"
                            class="text-xs font-medium text-[#2e7d32] hover:underline dark:text-emerald-400"
                            @click="close"
                        >
                            Back To Dashboard
                        </button>

                        <div class="flex items-center gap-3">
                            <button
                                type="button"
                                class="rounded border border-[#ccc] px-3 py-1.5 text-xs font-medium text-[#555] hover:bg-[#f5f5f5] dark:border-[#555] dark:text-[#ccc]"
                                @click="close"
                            >
                                Cancel
                            </button>
                            <button
                                type="button"
                                :disabled="
                                    form.processing ||
                                    !form.guest_name ||
                                    numericAmount <= 0 ||
                                    (anySplitMismatch && !fromGuestLedger) ||
                                    !!guestLedgerError
                                "
                                class="rounded bg-[#2e7d32] px-4 py-1.5 text-xs font-semibold text-white shadow hover:bg-[#1b5e20] disabled:opacity-50"
                                @click="submit"
                            >
                                {{
                                    form.processing
                                        ? 'Saving...'
                                        : 'Add Payment'
                                }}
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
