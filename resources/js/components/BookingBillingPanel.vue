<script setup lang="ts">
import AddPaymentModal from '@/components/AddPaymentModal.vue';
import CollectOnlineModal from '@/components/CollectOnlineModal.vue';
import Modal from '@/components/Modal.vue';
import OutletBillModal from '@/components/OutletBillModal.vue';
import ReceiptDetailsModal from '@/components/ReceiptDetailsModal.vue';
import admin from '@/routes/admin';
import { router, useForm } from '@inertiajs/vue3';
import { useCurrency } from '@/lib/useCurrency';
import { calendarDate, usePropertyTime } from '@/lib/usePropertyTime';
import { computed, ref } from 'vue';

/** Times arrive in UTC and are shown in the property's timezone. */
const propertyTime = usePropertyTime();

const { amount } = useCurrency();

export type PaymentItem = {
    id: number;
    payment_mode: string;
    receipt_number: string;
    voucher_number: string;
    booking_id?: number;
    property_name?: string;
    guest_name?: string;
    payment_creation_date: string;
    payment_transaction_date: string;
    paid_by: string;
    total_txn_amount: number;
    tagged_amount?: number;
    untagged_amount?: number;
    status: string;
    booking_source?: string;
    collected_by_staff?: string;
    payment_ref_id?: string | null;
    pg_txn_id?: string | null;
    bank?: string | null;
    card_name?: string | null;
    card_number?: string | null;
    cancellation_reason?: string | null;
    notes?: string | null;
};

export type ActivityLogItem = {
    id: number;
    user_name: string;
    action: string;
    date: string;
};

export type PaymentLinkItem = {
    id: number;
    url: string;
    amount: number;
    currency: string;
    collect_for: string;
    email: string | null;
    mobile: string | null;
    status: 'pending' | 'paid' | 'expired' | 'cancelled';
    status_label: string;
    unpayable_reason: string | null;
    created_by: string | null;
    created_at: string | null;
    sent_at: string | null;
    expires_at: string | null;
    paid_at: string | null;
    receipt_number: string | null;
};

export type FolioPayer = {
    type: 'guest' | 'company_profile';
    id: number;
    name: string;
};

/** Who a folio window can be billed to (BookingController::folioPayers). */
export type FolioPayers = {
    guests: { id: number; name: string }[];
    companies: { id: number; name: string }[];
};

export type FolioWindow = {
    id: number;
    name: string;
    /** Who settles this window; null = the folio owner. */
    payer: FolioPayer | null;
    balance: number;
    entries: {
        id: number;
        date: string | null;
        code: string | null;
        description: string | null;
        outlet_bill_id: number | null;
        type: 'debit' | 'credit';
        amount: number;
    }[];
};

export type FolioItem = {
    id: number;
    /** "Room 2 | Double Room" — numbered like the booking page's room details. */
    label: string;
    /** The physical room (e.g. "Room 1"); rooms of different categories can share a number. */
    room_number: string | null;
    is_room: boolean;
    stay: {
        sub_booking_id: number;
        status: string;
        check_in_date: string;
        check_out_date: string;
    } | null;
    status: string;
    currency: string;
    windows: FolioWindow[];
};

/** What the billing panel shows for one booking (BookingController::billingProps). */
export type BillingBooking = {
    id: number;
    booking_ref_id: string;
    status: string;
    check_out: string | null;
    rooms_split?: boolean;
    rooms_count: number;
    outstanding: number;
    room_bill_to?: string;
    payments: PaymentItem[];
    payment_links: PaymentLinkItem[];
};

export type BillingProps = {
    property: {
        id: number;
        name: string;
        abbreviation: string;
        country_id?: number | null;
    };
    booking: BillingBooking;
    guest: {
        name?: string;
        email?: string | null;
        mobile?: string | null;
    } | null;
    folios: FolioItem[];
    folioPayers?: FolioPayers;
    splitPaymentModes: string[];
    /** Settings → Booking → Payment Settings: the modes offered on Add Payment. */
    paymentModes?: string[];
    /** The booking guest's ledger balance: paying from it is offered on Add Payment when above 0. */
    guestLedgerBalance?: number;
    properties: { id: number; name: string; abbreviation: string }[];
    staff: { id: number; name: string }[];
    currentUser?: { id: number; name: string };
    reservations: Array<{
        id: number;
        booking_ref_id: string;
        guest_name: string;
        guest_id?: number | null;
        total: number;
        outstanding: number;
        taxes: number;
        status: string;
    }>;
    countries: Array<{
        id: number;
        name: string;
        iso2: string;
        phonecode: string;
    }>;
    today?: string;
};

/**
 * Payments, payment links and per-room folios of a booking, with their modals. Used on the
 * booking page and in the edit form (where the form owns the dates, so `manageStays` is off).
 */
const props = withDefaults(
    defineProps<
        BillingProps & {
            manageStays?: boolean;
        }
    >(),
    { manageStays: true },
);

const addPaymentModalOpen = ref(false);
/** The outlet (POS) bill opened from a folio entry. */
const openOutletBillId = ref<number | null>(null);
const collectOnlineModalOpen = ref(false);
const selectedReceipt = ref<PaymentItem | null>(null);

const totalPaid = computed(() =>
    props.booking.payments.reduce(
        (acc, p) => acc + (p.tagged_amount ?? p.total_txn_amount ?? 0),
        0,
    ),
);

function formatCurrency(value: number): string {
    return amount(value);
}

function openReceiptModal(payment: PaymentItem): void {
    selectedReceipt.value = payment;
}

function onPaymentSaved(): void {
    addPaymentModalOpen.value = false;
    router.reload();
}

defineExpose({
    openAddPayment: (): void => {
        addPaymentModalOpen.value = true;
    },
    openCollectOnline: (): void => {
        collectOnlineModalOpen.value = true;
    },
});

// ── Folios (one invoice per room) & split payment ──
const expandedWindows = ref<Record<number, boolean>>({});
const splitPaymentOpen = ref(false);

/** Every billing window, labelled with its folio, for the split payment form. */
const folioWindows = computed(() =>
    props.folios.flatMap((folio) =>
        folio.windows.map((window) => ({
            ...window,
            folioId: folio.id,
            isRoom: folio.is_room,
            label:
                folio.windows.length > 1
                    ? `${folio.label} — ${window.name}`
                    : folio.label,
        })),
    ),
);

/** The rooms' billing windows — the master folio's sub-folios. */
const roomWindows = computed(() =>
    folioWindows.value.filter((window) => window.isRoom),
);

/** The booking's own (master) folio — "the folio"; each room's folio is one of its sub-folios. */
const masterFolioId = computed(
    () => props.folios.find((folio) => !folio.is_room)?.id ?? null,
);

/** "Folio #35 · Sub-folio #33" for a room, "Folio #35" for the master folio. */
function folioNumberLabel(folio: FolioItem): string {
    if (!folio.is_room) {
        return `Folio #${folio.id}`;
    }

    return masterFolioId.value
        ? `Folio #${masterFolioId.value} · Sub-folio #${folio.id}`
        : `Sub-folio #${folio.id}`;
}

/** The booking's own (master) folio window — the first one that isn't a room's. */
const masterWindowId = computed(
    () => props.folios.find((folio) => !folio.is_room)?.windows[0]?.id ?? null,
);

function isMasterWindow(folio: FolioItem, window: FolioWindow): boolean {
    return !folio.is_room && window.id === masterWindowId.value;
}

/**
 * What a window's header shows. The master folio stands for the whole booking: its own
 * entries plus what every room's sub-folio still owes (or is owed).
 */
function windowBalance(folio: FolioItem, window: FolioWindow): number {
    if (!isMasterWindow(folio, window)) {
        return window.balance;
    }

    return (
        Math.round(
            (window.balance +
                roomWindows.value.reduce(
                    (sum, roomWindow) => sum + roomWindow.balance,
                    0,
                )) *
                100,
        ) / 100
    );
}

function balanceLabel(balance: number): string {
    return `${balance < 0 ? 'Credit ' : balance > 0 ? 'Due ' : 'Settled '}${formatCurrency(Math.abs(balance))}`;
}

function balanceClass(balance: number): string {
    return balance > 0
        ? 'text-orange-600'
        : balance < 0
          ? 'text-emerald-600'
          : 'text-gray-500';
}

// ── Transfer a folio's balance (due or credit) to another folio of the booking ──
const transferFrom = ref<{ id: number; label: string; balance: number } | null>(
    null,
);
const transferForm = useForm({
    from_sub_folio_id: 0,
    to_sub_folio_id: 0,
    amount: 0,
});

/** Every other folio window the balance can go to: the other rooms and the master folio. */
const transferTargets = computed(() =>
    folioWindows.value.filter((window) => window.id !== transferFrom.value?.id),
);

function openTransfer(folio: FolioItem, window: FolioWindow): void {
    transferForm.clearErrors();
    transferFrom.value = {
        id: window.id,
        label:
            folio.windows.length > 1
                ? `${folio.label} — ${window.name}`
                : folio.label,
        balance: window.balance,
    };
    transferForm.from_sub_folio_id = window.id;
    // Default to the master folio; from the master itself, to the first room.
    transferForm.to_sub_folio_id =
        masterWindowId.value && masterWindowId.value !== window.id
            ? masterWindowId.value
            : (transferTargets.value[0]?.id ?? 0);
    transferForm.amount = Math.abs(window.balance);
}

function submitTransfer(): void {
    transferForm.post(
        admin.bookings.folioTransfers.store({
            property: props.property.id,
            booking: props.booking.id,
        }).url,
        {
            preserveScroll: true,
            onSuccess: (page) => {
                if (!page.props.flash?.error) {
                    transferFrom.value = null;
                }
            },
        },
    );
}

// ── Add a folio (another bill on the booking) or a window (sub-folio) to a folio ──
/**
 * null = closed. folio null = a new folio on the booking; window set = edit that window
 * (name, who pays); otherwise a new window on the folio.
 */
const addingFolio = ref<{
    folio: FolioItem | null;
    window?: FolioWindow;
} | null>(null);
/** payer: '' = the folio owner, 'guest:12', 'company_profile:3', or 'company_profile:new' (company_name). */
const addFolioForm = useForm({ window_name: '', payer: '', company_name: '' });

function openAddFolio(folio: FolioItem | null, window?: FolioWindow): void {
    addFolioForm.reset();
    addFolioForm.clearErrors();
    addFolioForm.window_name = window?.name ?? '';
    addFolioForm.payer = window?.payer
        ? `${window.payer.type}:${window.payer.id}`
        : '';
    addingFolio.value = { folio, window };
}

function submitAddFolio(): void {
    const folio = addingFolio.value?.folio;
    const window = addingFolio.value?.window;
    const route = { property: props.property.id, booking: props.booking.id };
    const form = addFolioForm.transform((data) => {
        const [payerType, payerId] = data.payer
            ? data.payer.split(':')
            : [null, null];

        return {
            window_name: data.window_name || null,
            payer_type: payerType,
            payer_id: payerId && payerId !== 'new' ? Number(payerId) : null,
            company_name: payerId === 'new' ? data.company_name : null,
        };
    });
    const options = {
        preserveScroll: true,
        onSuccess: (page: { props: { flash?: { error?: string | null } } }) => {
            if (!page.props.flash?.error) {
                addingFolio.value = null;
            }
        },
    };

    if (window) {
        form.patch(
            admin.bookings.folios.windows.update({
                ...route,
                subFolio: window.id,
            }).url,
            options,
        );

        return;
    }

    form.post(
        folio
            ? admin.bookings.folios.windows.store({ ...route, folio: folio.id })
                  .url
            : admin.bookings.folios.store(route).url,
        options,
    );
}

const splitForm = useForm({
    payment_mode: 'Credit Card',
    gateway_reference: '',
    shares: {} as Record<number, { selected: boolean; amount: number }>,
});

function openSplitPayment(): void {
    splitForm.clearErrors();
    splitForm.payment_mode = props.splitPaymentModes.includes('Credit Card')
        ? 'Credit Card'
        : props.splitPaymentModes[0];
    splitForm.gateway_reference = '';
    splitForm.shares = Object.fromEntries(
        folioWindows.value.map((window) => [
            window.id,
            {
                selected: window.balance > 0,
                amount: Math.max(0, window.balance),
            },
        ]),
    );
    splitPaymentOpen.value = true;
}

const splitTotal = computed(
    () =>
        Math.round(
            Object.values(splitForm.shares).reduce(
                (sum, share) =>
                    sum + (share.selected ? Number(share.amount) || 0 : 0),
                0,
            ) * 100,
        ) / 100,
);

function submitSplitPayment(): void {
    splitForm
        .transform((data) => ({
            payment_mode: data.payment_mode,
            gateway_reference: data.gateway_reference || null,
            distributions: Object.entries(data.shares)
                .filter(
                    ([, share]) => share.selected && Number(share.amount) > 0,
                )
                .map(([subFolioId, share]) => ({
                    sub_folio_id: Number(subFolioId),
                    amount: Number(share.amount),
                })),
        }))
        .post(
            admin.bookings.splitPayments.store({
                property: props.property.id,
                booking: props.booking.id,
            }).url,
            {
                preserveScroll: true,
                onSuccess: () => (splitPaymentOpen.value = false),
            },
        );
}

/** Check one room in or out on its own (e.g. an early departure); undo moves need no time. */
function changeRoomStay(
    folio: FolioItem,
    status: 'booked' | 'checked_in' | 'checked_out',
    reportedAt: string | null = null,
    onDone?: () => void,
    overrideHousekeeping = false,
): void {
    if (!folio.stay) {
        return;
    }

    router.patch(
        admin.bookings.rooms.stay({
            property: props.property.id,
            booking: props.booking.id,
            subBooking: folio.stay.sub_booking_id,
        }).url,
        {
            status,
            reported_at: reportedAt,
            override_housekeeping: overrideHousekeeping,
        },
        { preserveScroll: true, onFinish: () => onDone?.() },
    );
}

/** Check in / check out of one room: first confirm when it happened (defaults to now). */
const roomStayTime = ref<{
    folio: FolioItem;
    status: 'checked_in' | 'checked_out';
    at: string;
    saving: boolean;
    checkInAnyway: boolean;
} | null>(null);

function askRoomStayTime(
    folio: FolioItem,
    status: 'checked_in' | 'checked_out',
): void {
    roomStayTime.value = {
        folio,
        status,
        at: propertyTime.toInput(),
        saving: false,
        checkInAnyway: false,
    };
}

function saveRoomStayTime(): void {
    const pending = roomStayTime.value;

    if (!pending) {
        return;
    }

    pending.saving = true;
    changeRoomStay(
        pending.folio,
        pending.status,
        propertyTime.fromInput(pending.at),
        () => (roomStayTime.value = null),
        pending.checkInAnyway,
    );
}

// ── Split booking: one room with its own dates ──
const canSplit = computed(
    () =>
        props.booking.rooms_count > 1 &&
        !props.booking.rooms_split &&
        ['confirmed', 'pending', 'on_hold', 'checked_in'].includes(
            props.booking.status,
        ),
);

function splitBooking(): void {
    router.post(
        admin.bookings.split({
            property: props.property.id,
            booking: props.booking.id,
        }).url,
        {},
        { preserveScroll: true },
    );
}

type RoomDatesEdit = {
    folio: FolioItem;
    checkIn: string;
    checkOut: string;
    price: {
        current_total: number;
        new_total: number;
        difference: number;
    } | null;
    checking: boolean;
};

const roomDatesEdit = ref<RoomDatesEdit | null>(null);
const roomDatesForm = useForm({ check_in: '', check_out: '' });

function canChangeRoomDates(folio: FolioItem): boolean {
    return (
        !!folio.stay &&
        props.booking.rooms_count > 1 &&
        ['booked', 'hold', 'checked_in'].includes(folio.stay.status)
    );
}

function openRoomDates(folio: FolioItem): void {
    if (!folio.stay) {
        return;
    }

    roomDatesForm.clearErrors();
    roomDatesEdit.value = {
        folio,
        checkIn: folio.stay.check_in_date,
        checkOut: folio.stay.check_out_date,
        price: null,
        checking: false,
    };
}

/** Shows what the booking total becomes before the change is saved. */
async function previewRoomDates(): Promise<void> {
    const edit = roomDatesEdit.value;

    if (
        !edit?.folio.stay ||
        !edit.checkIn ||
        !edit.checkOut ||
        edit.checkOut <= edit.checkIn
    ) {
        if (edit) {
            edit.price = null;
        }

        return;
    }

    edit.checking = true;

    try {
        const response = await fetch(
            admin.bookings.rooms.datesPreview({
                property: props.property.id,
                booking: props.booking.id,
                subBooking: edit.folio.stay.sub_booking_id,
            }).url,
            {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-XSRF-TOKEN': decodeURIComponent(
                        document.cookie.match(
                            /(?:^|; )XSRF-TOKEN=([^;]*)/,
                        )?.[1] ?? '',
                    ),
                },
                credentials: 'same-origin',
                body: JSON.stringify({
                    check_in: edit.checkIn,
                    check_out: edit.checkOut,
                }),
            },
        );

        edit.price = response.ok ? await response.json() : null;
    } finally {
        edit.checking = false;
    }
}

function saveRoomDates(): void {
    const edit = roomDatesEdit.value;

    if (!edit?.folio.stay) {
        return;
    }

    roomDatesForm.check_in = edit.checkIn;
    roomDatesForm.check_out = edit.checkOut;
    roomDatesForm.patch(
        admin.bookings.rooms.dates({
            property: props.property.id,
            booking: props.booking.id,
            subBooking: edit.folio.stay.sub_booking_id,
        }).url,
        {
            preserveScroll: true,
            onSuccess: () => (roomDatesEdit.value = null),
        },
    );
}

const stayLabels: Record<string, string> = {
    booked: 'Booked',
    checked_in: 'In house',
    checked_out: 'Checked out',
    hold: 'On hold',
    cancelled: 'Cancelled',
    no_show: 'No show',
    quote: 'Quote',
};

// ── Payment links (Collect Online) ──
const copiedLinkId = ref<number | null>(null);
const cancellingLink = ref<PaymentLinkItem | null>(null);

async function copyPaymentLink(link: PaymentLinkItem): Promise<void> {
    try {
        await navigator.clipboard.writeText(link.url);
    } catch {
        window.prompt('Copy the payment link:', link.url);
    }

    copiedLinkId.value = link.id;
    setTimeout(
        () =>
            (copiedLinkId.value =
                copiedLinkId.value === link.id ? null : copiedLinkId.value),
        2500,
    );
}

function resendPaymentLink(link: PaymentLinkItem): void {
    router.post(
        admin.bookings.paymentLinks.resend({
            property: props.property.id,
            booking: props.booking.id,
            paymentLink: link.id,
        }).url,
        {},
        { preserveScroll: true },
    );
}

function confirmCancelPaymentLink(): void {
    if (!cancellingLink.value) {
        return;
    }

    router.patch(
        admin.bookings.paymentLinks.cancel({
            property: props.property.id,
            booking: props.booking.id,
            paymentLink: cancellingLink.value.id,
        }).url,
        {},
        { preserveScroll: true, onFinish: () => (cancellingLink.value = null) },
    );
}

function paymentLinkBadgeClass(link: PaymentLinkItem): string {
    if (link.status === 'paid') {
        return 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300';
    }

    if (link.status === 'pending') {
        return link.unpayable_reason
            ? 'bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-300'
            : 'bg-sky-100 text-sky-800 dark:bg-sky-950 dark:text-sky-300';
    }

    return 'bg-gray-100 text-gray-600 dark:bg-[#262625] dark:text-gray-400';
}
</script>

<template>
    <div class="divide-y divide-gray-200 dark:divide-[#2b2b28]">
        <!-- SECTION 6: Payments -->
        <div class="p-4">
            <div class="mb-3 flex items-center justify-between">
                <h2
                    class="text-sm font-bold text-[#1b1b18] dark:text-[#EDEDEC]"
                >
                    Payments
                </h2>
                <div class="flex items-center gap-2">
                    <button
                        v-if="booking.outstanding > 0"
                        type="button"
                        class="cursor-pointer rounded border border-orange-500 px-2 py-0.5 text-xs font-bold text-orange-600 hover:bg-orange-50 dark:hover:bg-orange-950/20"
                        @click="collectOnlineModalOpen = true"
                    >
                        Collect Online
                    </button>
                    <button
                        type="button"
                        class="rounded border border-gray-300 bg-white px-2.5 py-0.5 text-xs font-bold text-gray-800 shadow-xs hover:bg-gray-50 dark:border-[#3E3E3A] dark:bg-[#161615] dark:text-gray-200"
                        @click="addPaymentModalOpen = true"
                    >
                        ADD
                    </button>
                </div>
            </div>

            <!-- Payments Table -->
            <div class="overflow-x-auto">
                <table
                    class="min-w-full divide-y divide-gray-200 text-xs dark:divide-[#2b2b28]"
                >
                    <thead>
                        <tr
                            class="text-left font-semibold text-gray-500 dark:text-gray-400"
                        >
                            <th class="py-1.5 pr-2">Mode</th>
                            <th class="px-2 py-1.5">Receipt No.</th>
                            <th class="px-2 py-1.5">Creation Date</th>
                            <th class="px-2 py-1.5">Paid By</th>
                            <th class="py-1.5 pl-2 text-right">Amount</th>
                        </tr>
                    </thead>
                    <tbody
                        class="divide-y divide-gray-100 dark:divide-[#20201e]"
                    >
                        <tr
                            v-for="payment in booking.payments"
                            :key="payment.id"
                        >
                            <td
                                class="py-2 pr-2 font-medium text-gray-800 dark:text-gray-200"
                            >
                                {{ payment.payment_mode }}
                            </td>
                            <td
                                class="cursor-pointer px-2 py-2 text-[#0284c7] hover:underline"
                                @click="openReceiptModal(payment)"
                            >
                                {{ payment.receipt_number }}
                            </td>
                            <td
                                class="px-2 py-2 text-gray-600 dark:text-gray-400"
                            >
                                {{
                                    propertyTime.date(
                                        payment.payment_creation_date,
                                    )
                                }}
                            </td>
                            <td
                                class="px-2 py-2 text-gray-600 dark:text-gray-400"
                            >
                                {{ payment.paid_by }}
                            </td>
                            <td class="py-2 pl-2 text-right">
                                <div
                                    class="font-bold text-gray-800 dark:text-gray-200"
                                >
                                    {{
                                        formatCurrency(
                                            payment.tagged_amount ??
                                                payment.total_txn_amount,
                                        )
                                    }}
                                </div>
                            </td>
                        </tr>
                        <tr v-if="!booking.payments.length">
                            <td
                                colspan="5"
                                class="py-3 text-center text-gray-500 italic"
                            >
                                No payments recorded.
                            </td>
                        </tr>
                    </tbody>
                    <tfoot>
                        <tr
                            class="border-t border-dashed border-gray-300 font-bold dark:border-[#3E3E3A]"
                        >
                            <td
                                colspan="4"
                                class="py-2 text-right text-gray-800 dark:text-gray-200"
                            >
                                Total
                            </td>
                            <td
                                class="py-2 pl-2 text-right text-gray-900 dark:text-white"
                            >
                                {{ formatCurrency(totalPaid) }}
                            </td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>

        <!-- SECTION 6b: Payment Links -->
        <div class="p-4">
            <div class="mb-3 flex items-center justify-between">
                <h2
                    class="text-sm font-bold text-[#1b1b18] dark:text-[#EDEDEC]"
                >
                    Payment Links
                </h2>
                <button
                    v-if="booking.outstanding > 0"
                    type="button"
                    class="cursor-pointer rounded border border-orange-500 px-2 py-0.5 text-xs font-bold text-orange-600 hover:bg-orange-50 dark:hover:bg-orange-950/20"
                    @click="collectOnlineModalOpen = true"
                >
                    New Link
                </button>
            </div>

            <div class="overflow-x-auto">
                <table
                    class="min-w-full divide-y divide-gray-200 text-xs dark:divide-[#2b2b28]"
                >
                    <thead>
                        <tr
                            class="text-left font-semibold text-gray-500 dark:text-gray-400"
                        >
                            <th class="py-1.5 pr-2">Created</th>
                            <th class="px-2 py-1.5">Sent To</th>
                            <th class="px-2 py-1.5">Status</th>
                            <th class="px-2 py-1.5">Valid Till / Paid</th>
                            <th class="px-2 py-1.5 text-right">Amount</th>
                            <th class="py-1.5 pl-2 text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody
                        class="divide-y divide-gray-100 dark:divide-[#20201e]"
                    >
                        <tr
                            v-for="link in booking.payment_links"
                            :key="link.id"
                            class="align-top"
                        >
                            <td
                                class="py-2 pr-2 text-gray-600 dark:text-gray-400"
                            >
                                {{ propertyTime.dateTime(link.created_at) }}
                                <div
                                    v-if="link.created_by"
                                    class="text-[10px] text-gray-400"
                                >
                                    by {{ link.created_by }}
                                </div>
                            </td>
                            <td
                                class="px-2 py-2 text-gray-600 dark:text-gray-400"
                            >
                                {{ link.email || link.mobile || '—' }}
                                <div class="text-[10px] text-gray-400">
                                    {{ link.collect_for
                                    }}<template v-if="link.sent_at">
                                        · e-mailed
                                        {{
                                            propertyTime.dateTime(link.sent_at)
                                        }}</template
                                    >
                                </div>
                            </td>
                            <td class="px-2 py-2">
                                <span
                                    class="rounded-full px-2 py-0.5 text-[10px] font-semibold"
                                    :class="paymentLinkBadgeClass(link)"
                                >
                                    {{
                                        link.unpayable_reason
                                            ? 'Not payable'
                                            : link.status_label
                                    }}
                                </span>
                                <div
                                    v-if="link.unpayable_reason"
                                    class="mt-1 max-w-[180px] text-[10px] text-amber-700 dark:text-amber-400"
                                >
                                    {{ link.unpayable_reason }}
                                </div>
                            </td>
                            <td
                                class="px-2 py-2 text-gray-600 dark:text-gray-400"
                            >
                                <template v-if="link.status === 'paid'">
                                    {{ propertyTime.dateTime(link.paid_at) }}
                                    <div
                                        v-if="link.receipt_number"
                                        class="text-[10px] text-gray-400"
                                    >
                                        {{ link.receipt_number }}
                                    </div>
                                </template>
                                <template v-else>{{
                                    propertyTime.dateTime(link.expires_at)
                                }}</template>
                            </td>
                            <td
                                class="px-2 py-2 text-right font-bold text-gray-800 dark:text-gray-200"
                            >
                                {{ formatCurrency(link.amount) }}
                            </td>
                            <td class="py-2 pl-2 text-right whitespace-nowrap">
                                <template v-if="link.status === 'pending'">
                                    <button
                                        type="button"
                                        class="cursor-pointer font-semibold text-[#0284c7] hover:underline"
                                        @click="copyPaymentLink(link)"
                                    >
                                        {{
                                            copiedLinkId === link.id
                                                ? 'Copied!'
                                                : 'Copy'
                                        }}
                                    </button>
                                    <button
                                        v-if="
                                            link.email && !link.unpayable_reason
                                        "
                                        type="button"
                                        class="ml-2 cursor-pointer font-semibold text-[#0284c7] hover:underline"
                                        @click="resendPaymentLink(link)"
                                    >
                                        Resend
                                    </button>
                                    <button
                                        type="button"
                                        class="ml-2 cursor-pointer font-semibold text-red-600 hover:underline"
                                        @click="cancellingLink = link"
                                    >
                                        Cancel
                                    </button>
                                </template>
                                <span v-else class="text-gray-400">—</span>
                            </td>
                        </tr>
                        <tr v-if="!booking.payment_links.length">
                            <td
                                colspan="6"
                                class="py-3 text-center text-gray-500 italic"
                            >
                                No payment links yet.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- SECTION 6c: Folios — one invoice per room -->
        <div class="p-4">
            <div class="mb-3 flex items-center justify-between">
                <h2
                    class="text-sm font-bold text-[#1b1b18] dark:text-[#EDEDEC]"
                >
                    Folios
                    <span
                        v-if="booking.rooms_split"
                        class="ml-1 rounded bg-sky-100 px-1.5 py-0.5 text-[10px] font-semibold text-sky-700 dark:bg-sky-950 dark:text-sky-300"
                        >Split — rooms have their own dates</span
                    >
                </h2>
                <button
                    v-if="manageStays && canSplit"
                    type="button"
                    title="Let each room keep its own dates"
                    class="mr-2 ml-auto cursor-pointer rounded border border-sky-500 px-2 py-0.5 text-xs font-bold text-sky-700 hover:bg-sky-50 dark:hover:bg-sky-950/20"
                    @click="splitBooking"
                >
                    Split Rooms
                </button>
                <button
                    v-if="folios.length"
                    type="button"
                    title="Open another bill on this booking, e.g. for a different payer"
                    class="mr-2 cursor-pointer rounded border border-gray-400 px-2 py-0.5 text-xs font-bold text-gray-700 hover:bg-gray-50 dark:text-gray-300 dark:hover:bg-[#262625]"
                    @click="openAddFolio(null)"
                >
                    + Folio
                </button>
                <button
                    v-if="folioWindows.some((window) => window.balance > 0)"
                    type="button"
                    class="cursor-pointer rounded border border-orange-500 px-2 py-0.5 text-xs font-bold text-orange-600 hover:bg-orange-50 dark:hover:bg-orange-950/20"
                    @click="openSplitPayment"
                >
                    Split Payment
                </button>
            </div>

            <p
                v-if="!folios.length"
                class="py-3 text-center text-xs text-gray-500 italic"
            >
                No folios yet.
            </p>

            <div class="flex flex-col gap-2">
                <template v-for="folio in folios" :key="folio.id">
                    <div
                        v-for="window in folio.windows"
                        :key="window.id"
                        class="rounded border border-gray-200 dark:border-[#2b2b28]"
                    >
                        <div class="flex items-center">
                            <button
                                type="button"
                                class="flex min-w-0 flex-1 cursor-pointer items-center justify-between gap-3 px-3 py-2 text-left text-xs"
                                :aria-expanded="!!expandedWindows[window.id]"
                                @click="
                                    expandedWindows[window.id] =
                                        !expandedWindows[window.id]
                                "
                            >
                                <span
                                    class="font-semibold text-gray-800 dark:text-gray-200"
                                >
                                    {{ expandedWindows[window.id] ? '▾' : '▸' }}
                                    {{ folio.label }}
                                    <span
                                        v-if="folio.room_number"
                                        class="font-normal text-gray-500"
                                        >· Room No.
                                        {{ folio.room_number }}</span
                                    >
                                    <span
                                        v-if="folio.windows.length > 1"
                                        class="font-normal text-gray-500"
                                        >— {{ window.name }}</span
                                    >
                                    <span
                                        v-if="window.payer"
                                        class="ml-1 rounded bg-violet-100 px-1.5 py-0.5 text-[10px] font-semibold text-violet-700 dark:bg-violet-950 dark:text-violet-300"
                                        >Paid by {{ window.payer.name }}</span
                                    >
                                    <span
                                        class="ml-1 font-mono text-[10px] font-normal text-gray-400"
                                        >{{ folioNumberLabel(folio) }}</span
                                    >
                                    <span
                                        v-if="folio.status !== 'open'"
                                        class="ml-1 rounded bg-gray-100 px-1.5 py-0.5 text-[10px] text-gray-500 uppercase dark:bg-[#262625]"
                                        >{{ folio.status }}</span
                                    >
                                    <span
                                        v-if="folio.stay"
                                        class="ml-1 rounded px-1.5 py-0.5 text-[10px] font-semibold"
                                        :class="
                                            folio.stay.status === 'checked_in'
                                                ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300'
                                                : 'bg-gray-100 text-gray-600 dark:bg-[#262625] dark:text-gray-400'
                                        "
                                        >{{
                                            stayLabels[folio.stay.status] ??
                                            folio.stay.status
                                        }}<template
                                            v-if="
                                                folio.stay.status ===
                                                    'checked_out' &&
                                                folio.stay.check_out_date <
                                                    (booking.check_out ?? '')
                                            "
                                        >
                                            · left
                                            {{
                                                calendarDate(
                                                    folio.stay.check_out_date,
                                                )
                                            }}</template
                                        ></span
                                    >
                                </span>
                                <span
                                    class="font-bold"
                                    :class="
                                        balanceClass(
                                            windowBalance(folio, window),
                                        )
                                    "
                                >
                                    {{
                                        balanceLabel(
                                            windowBalance(folio, window),
                                        )
                                    }}
                                    <span
                                        v-if="
                                            isMasterWindow(folio, window) &&
                                            roomWindows.length
                                        "
                                        class="font-normal text-gray-400"
                                        >(incl. rooms)</span
                                    >
                                </span>
                            </button>
                            <button
                                v-if="
                                    folio.status === 'open' &&
                                    window ===
                                        folio.windows[folio.windows.length - 1]
                                "
                                type="button"
                                title="Add a billing window (sub-folio) to this folio"
                                class="mr-2 shrink-0 cursor-pointer rounded border border-gray-400 px-2 py-0.5 text-[11px] font-bold text-gray-700 hover:bg-gray-50 dark:text-gray-300 dark:hover:bg-[#262625]"
                                @click="openAddFolio(folio)"
                            >
                                + Window
                            </button>
                            <button
                                type="button"
                                title="Rename this window or change who pays it"
                                class="mr-2 shrink-0 cursor-pointer rounded border border-gray-400 px-2 py-0.5 text-[11px] font-bold text-gray-700 hover:bg-gray-50 dark:text-gray-300 dark:hover:bg-[#262625]"
                                @click="openAddFolio(folio, window)"
                            >
                                Paid by
                            </button>
                            <button
                                v-if="
                                    Math.abs(window.balance) >= 0.01 &&
                                    folioWindows.length > 1
                                "
                                type="button"
                                :title="`Move this folio's ${window.balance > 0 ? 'amount due' : 'credit'} to another folio`"
                                class="mr-2 shrink-0 cursor-pointer rounded border border-sky-500 px-2 py-0.5 text-[11px] font-bold text-sky-700 hover:bg-sky-50 dark:hover:bg-sky-950/20"
                                @click="openTransfer(folio, window)"
                            >
                                Transfer
                            </button>
                        </div>
                        <div
                            v-if="
                                manageStays &&
                                folio.stay &&
                                window === folio.windows[0]
                            "
                            class="flex flex-wrap justify-end gap-2 border-t border-gray-100 px-3 py-1.5 text-[11px] dark:border-[#20201e]"
                        >
                            <button
                                v-if="
                                    folio.stay.status === 'booked' &&
                                    (!today ||
                                        (folio.stay.check_in_date <= today &&
                                            today < folio.stay.check_out_date))
                                "
                                type="button"
                                class="cursor-pointer font-bold text-emerald-700 hover:underline"
                                @click="askRoomStayTime(folio, 'checked_in')"
                            >
                                Check in room
                            </button>
                            <template v-if="folio.stay.status === 'checked_in'">
                                <button
                                    type="button"
                                    class="cursor-pointer font-bold text-orange-600 hover:underline"
                                    @click="
                                        askRoomStayTime(folio, 'checked_out')
                                    "
                                >
                                    Check out room
                                </button>
                                <button
                                    type="button"
                                    class="cursor-pointer text-gray-500 hover:underline"
                                    @click="changeRoomStay(folio, 'booked')"
                                >
                                    Undo check-in
                                </button>
                            </template>
                            <button
                                v-if="folio.stay.status === 'checked_out'"
                                type="button"
                                class="cursor-pointer text-gray-500 hover:underline"
                                @click="changeRoomStay(folio, 'checked_in')"
                            >
                                Undo check-out
                            </button>
                            <span
                                v-if="booking.rooms_split"
                                class="mr-auto text-gray-500"
                                >{{ calendarDate(folio.stay.check_in_date) }} →
                                {{
                                    calendarDate(folio.stay.check_out_date)
                                }}</span
                            >
                            <button
                                v-if="canChangeRoomDates(folio)"
                                type="button"
                                class="cursor-pointer font-bold text-sky-700 hover:underline"
                                @click="openRoomDates(folio)"
                            >
                                Change dates
                            </button>
                        </div>
                        <!-- The master folio's statement: what each room's sub-folio owes, then its own entries. -->
                        <table
                            v-if="
                                expandedWindows[window.id] &&
                                isMasterWindow(folio, window) &&
                                roomWindows.length
                            "
                            class="min-w-full border-t border-gray-100 bg-gray-50 text-xs dark:border-[#20201e] dark:bg-[#1D1D1B]"
                        >
                            <thead>
                                <tr
                                    class="text-left text-[10px] font-semibold tracking-wide text-gray-500 uppercase"
                                >
                                    <th class="py-1.5 pr-2 pl-3" colspan="3">
                                        Sub-folio balances
                                    </th>
                                    <th class="py-1.5 pr-3 pl-2 text-right">
                                        Balance
                                    </th>
                                </tr>
                            </thead>
                            <tbody
                                class="divide-y divide-gray-100 dark:divide-[#20201e]"
                            >
                                <tr
                                    v-for="roomWindow in roomWindows"
                                    :key="roomWindow.id"
                                >
                                    <td
                                        class="py-1.5 pr-2 pl-3 text-gray-700 dark:text-gray-300"
                                        colspan="3"
                                    >
                                        {{ roomWindow.label }}
                                        <span
                                            class="ml-1 font-mono text-[10px] text-gray-400"
                                            >Sub-folio #{{
                                                roomWindow.folioId
                                            }}</span
                                        >
                                    </td>
                                    <td
                                        class="py-1.5 pr-3 pl-2 text-right font-semibold whitespace-nowrap"
                                        :class="
                                            balanceClass(roomWindow.balance)
                                        "
                                    >
                                        {{ balanceLabel(roomWindow.balance) }}
                                    </td>
                                </tr>
                                <tr>
                                    <td
                                        class="py-1.5 pr-2 pl-3 text-gray-700 dark:text-gray-300"
                                        colspan="3"
                                    >
                                        Master folio (own entries below)
                                        <span
                                            class="ml-1 font-mono text-[10px] text-gray-400"
                                            >Folio #{{ folio.id }}</span
                                        >
                                    </td>
                                    <td
                                        class="py-1.5 pr-3 pl-2 text-right font-semibold whitespace-nowrap"
                                        :class="balanceClass(window.balance)"
                                    >
                                        {{ balanceLabel(window.balance) }}
                                    </td>
                                </tr>
                                <tr
                                    class="border-t border-dashed border-gray-300 dark:border-[#3E3E3A]"
                                >
                                    <td
                                        class="py-1.5 pr-2 pl-3 font-bold text-gray-800 dark:text-gray-200"
                                        colspan="3"
                                    >
                                        Booking balance
                                    </td>
                                    <td
                                        class="py-1.5 pr-3 pl-2 text-right font-bold whitespace-nowrap"
                                        :class="
                                            balanceClass(
                                                windowBalance(folio, window),
                                            )
                                        "
                                    >
                                        {{
                                            balanceLabel(
                                                windowBalance(folio, window),
                                            )
                                        }}
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                        <table
                            v-if="expandedWindows[window.id]"
                            class="min-w-full border-t border-gray-100 text-xs dark:border-[#20201e]"
                        >
                            <tbody
                                class="divide-y divide-gray-100 dark:divide-[#20201e]"
                            >
                                <tr
                                    v-for="entry in window.entries"
                                    :key="entry.id"
                                >
                                    <td
                                        class="py-1.5 pr-2 pl-3 whitespace-nowrap text-gray-500"
                                    >
                                        {{ propertyTime.date(entry.date) }}
                                    </td>
                                    <td class="px-2 py-1.5 text-gray-400">
                                        {{ entry.code }}
                                    </td>
                                    <td
                                        class="px-2 py-1.5 text-gray-700 dark:text-gray-300"
                                    >
                                        <button
                                            v-if="entry.outlet_bill_id"
                                            type="button"
                                            class="text-left text-[#f53003] hover:underline dark:text-[#FF4433]"
                                            :title="'Open outlet bill'"
                                            @click="
                                                openOutletBillId =
                                                    entry.outlet_bill_id
                                            "
                                        >
                                            {{ entry.description }}
                                        </button>
                                        <template v-else>{{
                                            entry.description
                                        }}</template>
                                    </td>
                                    <td
                                        class="py-1.5 pr-3 pl-2 text-right whitespace-nowrap"
                                        :class="
                                            entry.type === 'credit'
                                                ? 'text-emerald-600'
                                                : 'text-gray-800 dark:text-gray-200'
                                        "
                                    >
                                        {{ entry.type === 'credit' ? '−' : ''
                                        }}{{ formatCurrency(entry.amount) }}
                                    </td>
                                </tr>
                                <tr v-if="!window.entries.length">
                                    <td
                                        colspan="4"
                                        class="py-2 text-center text-gray-500 italic"
                                    >
                                        No entries.
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </template>
            </div>
        </div>

        <!-- Add Payment Modal Component -->
        <AddPaymentModal
            :show="addPaymentModalOpen"
            :property="property"
            :properties="properties"
            :staff="staff"
            :current-user="currentUser"
            :reservations="reservations"
            :countries="countries"
            :payment-modes="paymentModes"
            :guest-ledger="
                guestLedgerBalance
                    ? { balance: guestLedgerBalance, booking_id: booking.id }
                    : null
            "
            @close="addPaymentModalOpen = false"
            @saved="onPaymentSaved"
        />

        <!-- Receipt Details Modal Component -->
        <ReceiptDetailsModal
            :show="!!selectedReceipt"
            :receipt="selectedReceipt"
            @close="selectedReceipt = null"
        />

        <!-- Collect Online Modal Component -->
        <CollectOnlineModal
            :show="collectOnlineModalOpen"
            :property="property"
            :booking="booking"
            :guest="guest"
            @close="collectOnlineModalOpen = false"
        />

        <OutletBillModal
            :property-id="property.id"
            :bill-id="openOutletBillId"
            @close="openOutletBillId = null"
        />

        <Modal
            :show="roomDatesEdit !== null"
            title="Change this room's dates"
            @close="roomDatesEdit = null"
        >
            <form
                v-if="roomDatesEdit"
                class="flex flex-col gap-3 text-sm"
                @submit.prevent="saveRoomDates"
            >
                <p class="text-xs text-[#706f6c] dark:text-[#A1A09A]">
                    {{ roomDatesEdit.folio.label }}.
                    <template v-if="!booking.rooms_split"
                        >Saving splits the booking: the other rooms keep their
                        current dates.</template
                    >
                </p>
                <div class="grid grid-cols-2 gap-3">
                    <label
                        class="flex flex-col gap-1 text-xs text-[#706f6c] dark:text-[#A1A09A]"
                    >
                        Check-in
                        <input
                            v-model="roomDatesEdit.checkIn"
                            type="date"
                            :disabled="
                                roomDatesEdit.folio.stay?.status ===
                                'checked_in'
                            "
                            class="rounded-md border border-[#e3e3e0] bg-white px-2 py-1.5 text-sm text-[#1b1b18] disabled:opacity-50 dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]"
                            @change="previewRoomDates"
                        />
                    </label>
                    <label
                        class="flex flex-col gap-1 text-xs text-[#706f6c] dark:text-[#A1A09A]"
                    >
                        Check-out
                        <input
                            v-model="roomDatesEdit.checkOut"
                            type="date"
                            class="rounded-md border border-[#e3e3e0] bg-white px-2 py-1.5 text-sm text-[#1b1b18] dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]"
                            @change="previewRoomDates"
                        />
                    </label>
                </div>
                <p v-if="roomDatesEdit.checking" class="text-xs text-[#706f6c]">
                    Checking the price…
                </p>
                <p
                    v-else-if="
                        roomDatesEdit.price &&
                        Math.abs(roomDatesEdit.price.difference) >= 0.01
                    "
                    class="rounded bg-amber-100 px-3 py-2 text-xs font-semibold text-amber-900 dark:bg-amber-900/40 dark:text-amber-200"
                    role="alert"
                >
                    The booking total will change from
                    {{ formatCurrency(roomDatesEdit.price.current_total) }} to
                    {{ formatCurrency(roomDatesEdit.price.new_total) }} ({{
                        roomDatesEdit.price.difference > 0 ? '+' : '−'
                    }}{{
                        formatCurrency(
                            Math.abs(roomDatesEdit.price.difference),
                        )
                    }}).
                </p>
                <p
                    v-else-if="roomDatesEdit.price"
                    class="text-xs text-[#706f6c] dark:text-[#A1A09A]"
                >
                    The booking total stays the same.
                </p>
                <p
                    v-for="(message, key) in roomDatesForm.errors"
                    :key="key"
                    class="text-xs text-[#f53003] dark:text-[#FF4433]"
                >
                    {{ message }}
                </p>
                <div
                    class="flex justify-end gap-2 border-t border-[#e3e3e0] pt-3 dark:border-[#3E3E3A]"
                >
                    <button
                        type="button"
                        class="rounded-md border border-[#e3e3e0] px-3 py-1.5 text-sm dark:border-[#3E3E3A] dark:text-[#EDEDEC]"
                        @click="roomDatesEdit = null"
                    >
                        Cancel
                    </button>
                    <button
                        type="submit"
                        :disabled="
                            roomDatesForm.processing || roomDatesEdit.checking
                        "
                        class="rounded-md bg-sky-700 px-3 py-1.5 text-sm font-semibold text-white hover:bg-sky-800 disabled:opacity-50"
                    >
                        {{
                            roomDatesForm.processing ? 'Saving…' : 'Save Dates'
                        }}
                    </button>
                </div>
            </form>
        </Modal>

        <Modal
            :show="splitPaymentOpen"
            title="Split Payment"
            @close="splitPaymentOpen = false"
        >
            <form
                class="flex flex-col gap-3 text-sm"
                @submit.prevent="submitSplitPayment"
            >
                <p class="text-xs text-[#706f6c] dark:text-[#A1A09A]">
                    One payment, settling several invoices. Each invoice keeps
                    its own receipt line.
                </p>

                <div
                    class="flex flex-col divide-y divide-[#e3e3e0] rounded border border-[#e3e3e0] dark:divide-[#3E3E3A] dark:border-[#3E3E3A]"
                >
                    <label
                        v-for="window in folioWindows"
                        :key="window.id"
                        class="flex items-center gap-2 px-3 py-2"
                    >
                        <input
                            v-model="splitForm.shares[window.id].selected"
                            type="checkbox"
                            class="h-4 w-4 rounded border-[#e3e3e0] text-[#f53003] focus:ring-[#f53003]"
                        />
                        <span
                            class="flex-1 text-xs text-[#1b1b18] dark:text-[#EDEDEC]"
                        >
                            {{ window.label }}
                            <span
                                class="block text-[10px] text-[#706f6c] dark:text-[#A1A09A]"
                                >Due
                                {{
                                    formatCurrency(Math.max(0, window.balance))
                                }}</span
                            >
                        </span>
                        <input
                            v-model.number="splitForm.shares[window.id].amount"
                            type="number"
                            min="0"
                            step="0.01"
                            :disabled="!splitForm.shares[window.id].selected"
                            :aria-label="`Amount for ${window.label}`"
                            class="w-28 rounded-md border border-[#e3e3e0] bg-white px-2 py-1 text-right text-sm disabled:opacity-40 dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]"
                        />
                    </label>
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <label
                        class="flex flex-col gap-1 text-xs text-[#706f6c] dark:text-[#A1A09A]"
                    >
                        Payment Mode
                        <select
                            v-model="splitForm.payment_mode"
                            class="rounded-md border border-[#e3e3e0] bg-white px-2 py-1.5 text-sm text-[#1b1b18] dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]"
                        >
                            <option
                                v-for="mode in splitPaymentModes"
                                :key="mode"
                                :value="mode"
                            >
                                {{ mode }}
                            </option>
                        </select>
                    </label>
                    <label
                        class="flex flex-col gap-1 text-xs text-[#706f6c] dark:text-[#A1A09A]"
                    >
                        Reference (optional)
                        <input
                            v-model="splitForm.gateway_reference"
                            type="text"
                            placeholder="Card / UPI reference"
                            class="rounded-md border border-[#e3e3e0] bg-white px-2 py-1.5 text-sm text-[#1b1b18] dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]"
                        />
                    </label>
                </div>

                <p
                    v-for="(message, key) in splitForm.errors"
                    :key="key"
                    class="text-xs text-[#f53003] dark:text-[#FF4433]"
                >
                    {{ message }}
                </p>

                <div
                    class="flex items-center justify-between border-t border-[#e3e3e0] pt-3 dark:border-[#3E3E3A]"
                >
                    <span
                        class="font-semibold text-[#1b1b18] dark:text-[#EDEDEC]"
                        >Total {{ formatCurrency(splitTotal) }}</span
                    >
                    <div class="flex gap-2">
                        <button
                            type="button"
                            class="rounded-md border border-[#e3e3e0] px-3 py-1.5 text-sm dark:border-[#3E3E3A] dark:text-[#EDEDEC]"
                            @click="splitPaymentOpen = false"
                        >
                            Cancel
                        </button>
                        <button
                            type="submit"
                            :disabled="splitForm.processing || splitTotal <= 0"
                            class="rounded-md bg-[#ea580c] px-3 py-1.5 text-sm font-semibold text-white hover:bg-[#c2410c] disabled:opacity-50"
                        >
                            {{
                                splitForm.processing
                                    ? 'Recording…'
                                    : 'Record Payment'
                            }}
                        </button>
                    </div>
                </div>
            </form>
        </Modal>

        <Modal
            :show="roomStayTime !== null"
            :title="
                roomStayTime?.status === 'checked_out'
                    ? 'Check out room'
                    : 'Check in room'
            "
            @close="roomStayTime = null"
        >
            <form
                v-if="roomStayTime"
                class="flex flex-col gap-3 text-sm"
                @submit.prevent="saveRoomStayTime"
            >
                <p class="text-xs text-[#706f6c] dark:text-[#A1A09A]">
                    {{ roomStayTime.folio.label }}
                </p>
                <label
                    class="flex flex-col gap-1 text-xs text-[#706f6c] dark:text-[#A1A09A]"
                >
                    {{
                        roomStayTime.status === 'checked_out'
                            ? 'Check-out'
                            : 'Check-in'
                    }}
                    date &amp; time
                    <input
                        v-model="roomStayTime.at"
                        type="datetime-local"
                        required
                        :max="propertyTime.toInput()"
                        class="rounded-md border border-[#e3e3e0] bg-white px-2 py-1.5 text-sm text-[#1b1b18] dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]"
                    />
                </label>
                <!-- Housekeeping: a room whose status doesn't allow check-in can still be checked in when confirmed (not past a hard stop). -->
                <label
                    v-if="roomStayTime.status === 'checked_in'"
                    class="flex items-start gap-2 text-xs text-[#706f6c] dark:text-[#A1A09A]"
                >
                    <input
                        v-model="roomStayTime.checkInAnyway"
                        type="checkbox"
                        class="mt-0.5 h-4 w-4 rounded"
                    />
                    Check in anyway if housekeeping hasn't marked the room ready
                </label>
                <div
                    class="flex justify-end gap-2 border-t border-[#e3e3e0] pt-3 dark:border-[#3E3E3A]"
                >
                    <button
                        type="button"
                        class="rounded-md border border-[#e3e3e0] px-3 py-1.5 text-sm dark:border-[#3E3E3A] dark:text-[#EDEDEC]"
                        @click="roomStayTime = null"
                    >
                        Cancel
                    </button>
                    <button
                        type="submit"
                        :disabled="roomStayTime.saving"
                        class="rounded-md bg-[#ea580c] px-3 py-1.5 text-sm font-semibold text-white hover:bg-[#c2410c] disabled:opacity-50"
                    >
                        {{
                            roomStayTime.saving
                                ? 'Saving…'
                                : roomStayTime.status === 'checked_out'
                                  ? 'Check Out'
                                  : 'Check In'
                        }}
                    </button>
                </div>
            </form>
        </Modal>

        <Modal
            :show="transferFrom !== null"
            title="Transfer folio balance"
            @close="transferFrom = null"
        >
            <form
                v-if="transferFrom"
                class="flex flex-col gap-3 text-sm"
                @submit.prevent="submitTransfer"
            >
                <p class="text-xs text-[#706f6c] dark:text-[#A1A09A]">
                    {{ transferFrom.label }} has
                    <span
                        class="font-semibold"
                        :class="balanceClass(transferFrom.balance)"
                        >{{ balanceLabel(transferFrom.balance) }}</span
                    >. Moving it doesn't change the booking's total balance.
                </p>
                <label
                    class="flex flex-col gap-1 text-xs text-[#706f6c] dark:text-[#A1A09A]"
                >
                    Transfer
                    {{
                        transferFrom.balance > 0
                            ? 'the amount due'
                            : 'the credit'
                    }}
                    to
                    <select
                        v-model="transferForm.to_sub_folio_id"
                        class="rounded-md border border-[#e3e3e0] bg-white px-2 py-1.5 text-sm text-[#1b1b18] dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]"
                    >
                        <option
                            v-for="target in transferTargets"
                            :key="target.id"
                            :value="target.id"
                        >
                            {{ target.label }} ·
                            {{
                                target.isRoom
                                    ? `Sub-folio #${target.folioId}`
                                    : `Folio #${target.folioId}`
                            }}
                            ({{ balanceLabel(target.balance) }})
                        </option>
                    </select>
                </label>
                <label
                    class="flex flex-col gap-1 text-xs text-[#706f6c] dark:text-[#A1A09A]"
                >
                    Amount
                    <input
                        v-model.number="transferForm.amount"
                        type="number"
                        min="0.01"
                        step="0.01"
                        :max="Math.abs(transferFrom.balance)"
                        required
                        class="rounded-md border border-[#e3e3e0] bg-white px-2 py-1.5 text-right text-sm text-[#1b1b18] dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]"
                    />
                </label>
                <p
                    v-for="(message, key) in transferForm.errors"
                    :key="key"
                    class="text-xs text-[#f53003] dark:text-[#FF4433]"
                >
                    {{ message }}
                </p>
                <div
                    class="flex justify-end gap-2 border-t border-[#e3e3e0] pt-3 dark:border-[#3E3E3A]"
                >
                    <button
                        type="button"
                        class="rounded-md border border-[#e3e3e0] px-3 py-1.5 text-sm dark:border-[#3E3E3A] dark:text-[#EDEDEC]"
                        @click="transferFrom = null"
                    >
                        Cancel
                    </button>
                    <button
                        type="submit"
                        :disabled="
                            transferForm.processing ||
                            !transferForm.to_sub_folio_id
                        "
                        class="rounded-md bg-sky-700 px-3 py-1.5 text-sm font-semibold text-white hover:bg-sky-800 disabled:opacity-50"
                    >
                        {{
                            transferForm.processing
                                ? 'Transferring…'
                                : 'Transfer'
                        }}
                    </button>
                </div>
            </form>
        </Modal>

        <Modal
            :show="addingFolio !== null"
            :title="
                addingFolio?.window
                    ? 'Billing window'
                    : addingFolio?.folio
                      ? 'Add billing window'
                      : 'Add folio'
            "
            @close="addingFolio = null"
        >
            <form
                v-if="addingFolio"
                class="flex flex-col gap-3 text-sm"
                @submit.prevent="submitAddFolio"
            >
                <p class="text-xs text-[#706f6c] dark:text-[#A1A09A]">
                    <template v-if="addingFolio.window && addingFolio.folio"
                        >{{ addingFolio.folio.label }} ·
                        {{ folioNumberLabel(addingFolio.folio) }}.</template
                    >
                    <template v-else-if="addingFolio.folio"
                        >A new window on {{ addingFolio.folio.label }} ·
                        {{ folioNumberLabel(addingFolio.folio) }}.</template
                    >
                    <template v-else
                        >A new folio on this booking, besides its master
                        folio.</template
                    >
                    <template v-if="!addingFolio.window">
                        Move amounts to it with Transfer, Split Payment or Add
                        Payment.</template
                    >
                </p>
                <label
                    class="flex flex-col gap-1 text-xs text-[#706f6c] dark:text-[#A1A09A]"
                >
                    Window name
                    <input
                        v-model="addFolioForm.window_name"
                        type="text"
                        maxlength="50"
                        :placeholder="
                            addingFolio.window
                                ? addingFolio.window.name
                                : addingFolio.folio
                                  ? `Window ${String.fromCharCode(65 + addingFolio.folio.windows.length)}`
                                  : 'Window A'
                        "
                        class="rounded-md border border-[#e3e3e0] bg-white px-2 py-1.5 text-sm text-[#1b1b18] dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]"
                    />
                </label>
                <label
                    class="flex flex-col gap-1 text-xs text-[#706f6c] dark:text-[#A1A09A]"
                >
                    Paid by
                    <select
                        v-model="addFolioForm.payer"
                        class="rounded-md border border-[#e3e3e0] bg-white px-2 py-1.5 text-sm text-[#1b1b18] dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]"
                    >
                        <option value="">The folio owner</option>
                        <optgroup
                            v-if="folioPayers?.guests.length"
                            label="Guests"
                        >
                            <option
                                v-for="payer in folioPayers.guests"
                                :key="`g${payer.id}`"
                                :value="`guest:${payer.id}`"
                            >
                                {{ payer.name }}
                            </option>
                        </optgroup>
                        <optgroup label="Companies">
                            <option
                                v-for="payer in folioPayers?.companies ?? []"
                                :key="`c${payer.id}`"
                                :value="`company_profile:${payer.id}`"
                            >
                                {{ payer.name }}
                            </option>
                            <option value="company_profile:new">
                                + New company…
                            </option>
                        </optgroup>
                    </select>
                </label>
                <label
                    v-if="addFolioForm.payer === 'company_profile:new'"
                    class="flex flex-col gap-1 text-xs text-[#706f6c] dark:text-[#A1A09A]"
                >
                    Company name
                    <input
                        v-model="addFolioForm.company_name"
                        type="text"
                        maxlength="255"
                        required
                        class="rounded-md border border-[#e3e3e0] bg-white px-2 py-1.5 text-sm text-[#1b1b18] dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]"
                    />
                </label>
                <p
                    v-for="(message, key) in addFolioForm.errors"
                    :key="key"
                    class="text-xs text-[#f53003] dark:text-[#FF4433]"
                >
                    {{ message }}
                </p>
                <div
                    class="flex justify-end gap-2 border-t border-[#e3e3e0] pt-3 dark:border-[#3E3E3A]"
                >
                    <button
                        type="button"
                        class="rounded-md border border-[#e3e3e0] px-3 py-1.5 text-sm dark:border-[#3E3E3A] dark:text-[#EDEDEC]"
                        @click="addingFolio = null"
                    >
                        Cancel
                    </button>
                    <button
                        type="submit"
                        :disabled="addFolioForm.processing"
                        class="rounded-md bg-sky-700 px-3 py-1.5 text-sm font-semibold text-white hover:bg-sky-800 disabled:opacity-50"
                    >
                        {{
                            addFolioForm.processing
                                ? 'Saving…'
                                : addingFolio.window
                                  ? 'Save'
                                  : 'Add'
                        }}
                    </button>
                </div>
            </form>
        </Modal>

        <Modal
            :show="cancellingLink !== null"
            title="Cancel payment link?"
            @close="cancellingLink = null"
        >
            <p class="text-sm text-[#706f6c] dark:text-[#A1A09A]">
                The guest won't be able to pay
                {{
                    cancellingLink ? formatCurrency(cancellingLink.amount) : ''
                }}
                with this link any more. This can't be undone.
            </p>
            <div class="mt-4 flex justify-end gap-2">
                <button
                    type="button"
                    class="rounded-md border border-[#e3e3e0] px-4 py-1.5 text-sm dark:border-[#3E3E3A] dark:text-[#EDEDEC]"
                    @click="cancellingLink = null"
                >
                    Keep Link
                </button>
                <button
                    type="button"
                    class="rounded-md bg-red-600 px-4 py-1.5 text-sm font-semibold text-white hover:bg-red-700"
                    @click="confirmCancelPaymentLink"
                >
                    Cancel Link
                </button>
            </div>
        </Modal>
    </div>
</template>
