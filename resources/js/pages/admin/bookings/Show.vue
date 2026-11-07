<script setup lang="ts">
import BookingBillingPanel, {
    type FolioItem,
    type FolioPayers,
    type PaymentItem,
    type PaymentLinkItem,
} from '@/components/BookingBillingPanel.vue';
import BookingAddonsPanel, {
    type AddonOption,
    type BookingAddonLine,
} from '@/components/BookingAddonsPanel.vue';
import BookingPrintMenu from '@/components/BookingPrintMenu.vue';
import WebCheckinPanel, {
    type WebCheckinSummary,
} from '@/components/WebCheckinPanel.vue';
import Modal from '@/components/Modal.vue';
import StayTimeChargeNotice from '@/components/StayTimeChargeNotice.vue';
import DocumentViewerModal, {
    type ViewableDocument,
} from '@/components/DocumentViewerModal.vue';
import AdminLayout from '@/layouts/AdminLayout.vue';
import { roomSummaryLines } from '@/lib/roomSummaryLines';
import admin from '@/routes/admin';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { useCan } from '@/lib/useCan';
import { useCurrency } from '@/lib/useCurrency';
import { usePropertyTime } from '@/lib/usePropertyTime';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';

const { amount, code, symbol } = useCurrency();
/** Times arrive in UTC; they're shown, and entered, in the property's timezone. */
const propertyTime = usePropertyTime();
const can = useCan();

defineOptions({ layout: AdminLayout });

export type RoomItem = {
    id: number;
    room_category_id: number;
    category_name: string;
    category_abbreviation: string;
    room_id: number | null;
    room_number: string;
    meal_plan: string;
    adults: number;
    children: number;
    infants: number;
    rate_per_night: number;
    total_amount: number;
    discount?: number;
    taxes?: number;
    total?: number;
    sub_booking_id: number;
    stay_status: string;
    check_in_date: string;
    check_out_date: string;
    /** When the room was actually checked in / out at the front desk. */
    checked_in_at: string | null;
    checked_out_at: string | null;
    /** The physical room's housekeeping status (whether it may take guests). */
    housekeeping?: {
        name: string;
        color: string;
        allow_check_in: boolean;
        hard_stop: boolean;
    } | null;
};

export type GuestItem = {
    id: number;
    name: string;
    first_name: string;
    last_name: string;
    mobile: string | null;
    phone_country_code: string | null;
    email: string | null;
    nationality: string | null;
    address: string | null;
};

export type ActivityLogItem = {
    id: number;
    user_name: string;
    action: string;
    date: string;
};

export type AttachmentItem = {
    id: number;
    document_type: string;
    document_number: string | null;
    expiry_date: string | null;
    original_name: string;
    mime_type: string | null;
    size: number;
    uploaded_by: string | null;
    uploaded_at: string | null;
    url: string;
    download_url: string;
};

const props = defineProps<{
    property: {
        id: number;
        name: string;
        abbreviation: string;
        country_id?: number | null;
    };
    properties: { id: number; name: string; abbreviation: string }[];
    booking: {
        id: number;
        booking_ref_id: string;
        source: string;
        type: string;
        booking_type?: string | null;
        check_in: string | null;
        check_out: string | null;
        check_in_time?: string | null;
        check_out_time?: string | null;
        /** Each room keeps its own dates; the booking's dates span them. */
        rooms_split?: boolean;
        same_plan_for_all_rooms: boolean;
        pay_at_hotel: boolean;
        rate_plan?: string | null;
        check_in_status?: string | null;
        status: string;
        room_total: number;
        discount: number;
        taxes: number;
        total: number;
        outstanding: number;
        room_bill_to: string;
        extras_bill_to: string;
        bill_room_to_guest_company: boolean;
        bill_extras_to_guest_company: boolean;
        /** Bill To Guest Company: the City Ledger account billed (null when the booking isn't billed to a company). */
        company_billing?: CompanyBilling | null;
        no_post: boolean;
        special_instructions?: string | null;
        internal_notes?: string | null;
        copy_to?: Array<{ name: string; phone?: string; email?: string }>;
        inclusions?: string[];
        created_at?: string;
        updated_at?: string;
        created_by?: string;
        updated_by?: string;
        nights: number;
        rooms: RoomItem[];
        guests: GuestItem[];
        payments: PaymentItem[];
        payment_links: PaymentLinkItem[];
        attachments: AttachmentItem[];
        addons: BookingAddonLine[];
        addons_total: number;
    };
    addonCatalogue: AddonOption[];
    webCheckin: WebCheckinSummary;
    folios: FolioItem[];
    folioPayers: FolioPayers;
    splitPaymentModes: string[];
    cancellationPolicy: string;
    activityLogs: ActivityLogItem[];
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
    canCheckoutWithOutstanding?: boolean;
    /** Booking Settings → Housekeeping: what the rooms become when the guests leave. */
    housekeepingStatuses: { name: string; color: string }[];
    housekeepingCheckOutDefault: string | null;
    today?: string;
    /** Company / agency accounts the balance can be billed to (empty without the permission). */
    cityLedgerAccounts: { id: number; name: string; code: string | null }[];
    /** Settings → Exchange rates: 1 unit of the currency in the property's currency. */
    exchangeRates: {
        currency_code: string;
        rate: number;
        effective_date: string;
    }[];
    upgrades: {
        id: number;
        sub_booking_id: number;
        from: string;
        to: string;
        type: 'complimentary' | 'paid';
        supplement_per_night: number;
        nights: number;
        reason: string;
        by: string | null;
        at: string | null;
    }[];
    /** Settings → Booking → Payment Settings: the modes offered on Add Payment. */
    paymentModes: string[];
    /** The guest's ledger balance, offered as a payment mode on Add Payment. */
    guestLedgerBalance: number;
    /** The booking guest's ledger at the property (null without a guest). */
    guestLedger: {
        guest_name: string;
        balance: number;
        statement: {
            id: number;
            at: string | null;
            description: string | null;
            credit: number;
            debit: number;
        }[];
        booking_owed: number;
        booking_credit: number;
    } | null;
}>();

// ── Guest ledger: move this booking's credit onto it, or use its balance here ──
const guestLedgerAmount = ref<Record<'deposit' | 'apply', number>>({
    deposit: props.guestLedger?.booking_credit ?? 0,
    apply: Math.min(
        props.guestLedger?.balance ?? 0,
        props.guestLedger?.booking_owed ?? 0,
    ),
});
const guestLedgerBusy = ref(false);

function moveGuestLedger(action: 'deposit' | 'apply'): void {
    const route =
        action === 'deposit'
            ? admin.bookings.guestLedger.deposit
            : admin.bookings.guestLedger.apply;
    guestLedgerBusy.value = true;
    router.post(
        route({ property: props.property.id, booking: props.booking.id }).url,
        { amount: guestLedgerAmount.value[action] },
        {
            preserveScroll: true,
            onFinish: () => (guestLedgerBusy.value = false),
        },
    );
}

// ── Room upgrade ──
type UpgradeOption = {
    id: number;
    name: string;
    free_rooms: { id: number; name: string }[];
    suggested_supplement: number;
};
const upgradeRoom = ref<RoomItem | null>(null);
const upgradeOptions = ref<{
    nights: number;
    from: string;
    to: string;
    current_rate: number;
    options: UpgradeOption[];
} | null>(null);
const upgradeLoadError = ref('');
const upgradeForm = useForm({
    room_category_id: null as number | null,
    room_id: null as number | null,
    type: 'complimentary' as 'complimentary' | 'paid',
    supplement_per_night: 0,
    reason: '',
});
const chosenUpgrade = computed(
    () =>
        upgradeOptions.value?.options.find(
            (option) => option.id === upgradeForm.room_category_id,
        ) ?? null,
);

function mayUpgrade(room: RoomItem): boolean {
    return (
        can('reservation.upgrade_room') &&
        ['booked', 'hold', 'checked_in'].includes(room.stay_status)
    );
}

async function openUpgrade(room: RoomItem): Promise<void> {
    upgradeRoom.value = room;
    upgradeOptions.value = null;
    upgradeLoadError.value = '';
    upgradeForm.reset();
    upgradeForm.clearErrors();

    const response = await fetch(
        admin.bookings.rooms.upgradeOptions({
            property: props.property.id,
            booking: props.booking.id,
            subBooking: room.sub_booking_id,
        }).url,
        { headers: { Accept: 'application/json' } },
    );
    const data = await response.json();

    if (!response.ok) {
        upgradeLoadError.value = data.message ?? 'The room cannot be upgraded.';

        return;
    }

    upgradeOptions.value = data;
}

function chooseUpgrade(option: UpgradeOption): void {
    upgradeForm.room_category_id = option.id;
    upgradeForm.room_id = option.free_rooms[0]?.id ?? null;
    upgradeForm.supplement_per_night = option.suggested_supplement;
}

function submitUpgrade(): void {
    if (!upgradeRoom.value) {
        return;
    }

    upgradeForm.post(
        admin.bookings.rooms.upgrade({
            property: props.property.id,
            booking: props.booking.id,
            subBooking: upgradeRoom.value.sub_booking_id,
        }).url,
        {
            preserveScroll: true,
            onSuccess: () => (upgradeRoom.value = null),
        },
    );
}

/** The balance in another currency, at the property's current rate for it. */
const foreignCurrency = ref<string>(
    props.exchangeRates[0]?.currency_code ?? '',
);
const foreignOutstanding = computed(() => {
    const rate =
        props.exchangeRates.find(
            (entry) => entry.currency_code === foreignCurrency.value,
        )?.rate ?? 0;

    return rate > 0 ? netPayableByGuest.value / rate : null;
});

type CompanyBilling =
    | { linked: false }
    | {
          linked: true;
          account_id: number;
          name: string;
          tax_number: string | null;
          payment_terms_days: number;
          credit_limit: number | null;
          available_credit: number | null;
          room: boolean;
          extras: boolean;
          billed: number;
          unpaid: number;
          due_at_checkout: number;
      };

const cityLedgerOpen = ref(false);
const cityLedgerForm = ref<{ account_id: number | ''; amount: string }>({
    account_id: '',
    amount: '',
});
const cityLedgerErrors = ref<Record<string, string>>({});
const cityLedgerProcessing = ref(false);

function openCityLedger(): void {
    cityLedgerForm.value = {
        account_id: props.cityLedgerAccounts[0]?.id ?? '',
        amount:
            props.booking.outstanding > 0
                ? props.booking.outstanding.toFixed(2)
                : '',
    };
    cityLedgerErrors.value = {};
    cityLedgerOpen.value = true;
}

function submitCityLedger(): void {
    if (cityLedgerForm.value.account_id === '') {
        return;
    }

    cityLedgerProcessing.value = true;
    router.post(
        admin.databases.cityLedger.transfer({
            cityLedgerAccount: cityLedgerForm.value.account_id,
        }).url,
        {
            booking_ref_id: props.booking.booking_ref_id,
            amount:
                cityLedgerForm.value.amount === ''
                    ? null
                    : cityLedgerForm.value.amount,
        },
        {
            preserveScroll: true,
            onSuccess: () => (cityLedgerOpen.value = false),
            onError: (errors) => (cityLedgerErrors.value = errors),
            onFinish: () => (cityLedgerProcessing.value = false),
        },
    );
}

const activeTab = ref<'general' | 'outlet'>('general');

const viewedDocument = ref<ViewableDocument | null>(null);

function viewAttachment(attachment: AttachmentItem): void {
    viewedDocument.value = {
        title: attachment.document_type,
        subtitle: [
            attachment.document_number,
            attachment.expiry_date ? `Expires ${attachment.expiry_date}` : null,
            attachment.original_name,
        ]
            .filter(Boolean)
            .join(' · '),
        url: attachment.url,
        mimeType: attachment.mime_type,
        fileName: attachment.original_name,
        downloadUrl: attachment.download_url,
    };
}
const billingPanel = ref<InstanceType<typeof BookingBillingPanel> | null>(null);
const viewMoreInclusions = ref(false);
const emailDropdownOpen = ref(false);
const moreDropdownOpen = ref(false);
const emailDropdownRef = ref<HTMLElement | null>(null);
const moreDropdownRef = ref<HTMLElement | null>(null);

/** A click anywhere else (e.g. the Print button) closes the open EMAIL / MORE menu. */
function closeDropdownsOnOutsideClick(event: MouseEvent): void {
    const target = event.target as Node;

    if (emailDropdownRef.value && !emailDropdownRef.value.contains(target)) {
        emailDropdownOpen.value = false;
    }

    if (moreDropdownRef.value && !moreDropdownRef.value.contains(target)) {
        moreDropdownOpen.value = false;
    }
}

onMounted(() =>
    document.addEventListener('mousedown', closeDropdownsOnOutsideClick),
);
onBeforeUnmount(() =>
    document.removeEventListener('mousedown', closeDropdownsOnOutsideClick),
);

const primaryGuest = computed(() => {
    return (
        props.booking.guests[0] ?? {
            id: 0,
            name: '—',
            first_name: '—',
            last_name: '',
            mobile: '—',
            phone_country_code: '',
            email: '—',
            nationality: null,
            address: null,
        }
    );
});

const primaryRoom = computed(() => {
    return (
        props.booking.rooms[0] ?? {
            id: 0,
            room_category_id: 0,
            category_name: 'Room',
            category_abbreviation: '',
            room_id: null,
            room_number: 'Unassigned',
            meal_plan: 'EP',
            adults: 1,
            children: 0,
            infants: 0,
            rate_per_night: 0,
            total_amount: 0,
        }
    );
});

const summaryRoomLines = computed(() =>
    roomSummaryLines(
        props.booking.rooms.map((room) => ({
            category: room.category_name,
            mealPlan: room.meal_plan,
            adults: room.adults,
        })),
    ),
);

const totalAdults = computed(() => {
    return props.booking.rooms.reduce((acc, r) => acc + (r.adults || 0), 0);
});

const totalPaid = computed(() => {
    return props.booking.payments.reduce(
        (acc, p) => acc + (p.tagged_amount ?? p.total_txn_amount ?? 0),
        0,
    );
});

/**
 * Bill To Guest Company: what the company pays, the share still to move to its City Ledger
 * account at checkout plus what it was billed and hasn't paid yet. Null when no company pays.
 */
const netPayableByCompany = computed(() => {
    const billing = props.booking.company_billing;

    return billing?.linked
        ? Math.round((billing.due_at_checkout + billing.unpaid) * 100) / 100
        : null;
});

/** The whole bill: room charges less discount plus taxes (booking.total), plus the add-ons (incl. their tax). */
const totalBill = computed(
    () =>
        Math.round(
            (props.booking.total + (props.booking.addons_total ?? 0)) * 100,
        ) / 100,
);

/** What the guest still owes (less the company's share), whether settled at the hotel or before arrival. */
const netPayableByGuest = computed(() => {
    const billing = props.booking.company_billing;
    const companyShare = billing?.linked ? billing.due_at_checkout : 0;

    return Math.max(
        0,
        Math.round((props.booking.outstanding - companyShare) * 100) / 100,
    );
});

/** The part of that to collect at the hotel: all of it on a pay-at-hotel booking. */
const netPayableAtHotel = computed(() =>
    props.booking.pay_at_hotel ? netPayableByGuest.value : 0,
);

function formatCurrency(value: number): string {
    return amount(value);
}

function parseDateParts(dateStr: string | null): {
    day: string;
    monthYear: string;
    full: string;
} {
    if (!dateStr) return { day: '--', monthYear: '--', full: '--' };
    const date = new Date(dateStr);
    if (isNaN(date.getTime()))
        return { day: '--', monthYear: '--', full: '--' };
    const day = String(date.getDate()).padStart(2, '0');
    const month = date
        .toLocaleString('en-US', { month: 'short' })
        .toUpperCase();
    const year = date.getFullYear();
    const daySlashMonthSlashYear = `${day}/${String(date.getMonth() + 1).padStart(2, '0')}/${year}`;
    return {
        day,
        monthYear: `${month} ${year}`,
        full: daySlashMonthSlashYear,
    };
}

const checkInParts = computed(() => parseDateParts(props.booking.check_in));
const checkOutParts = computed(() => parseDateParts(props.booking.check_out));

const sourceDisplay = computed(() => {
    const map: Record<string, string> = {
        direct: 'Direct',
        corporate: 'Corporate',
        travel_agent: 'Agent',
        ota: 'OTA',
        meta: 'Meta',
        booking_engine: 'Booking Engine',
    };
    return map[props.booking.source] ?? props.booking.source;
});

const typeDisplay = computed(() => {
    if (props.booking.type === 'walk_in') return 'Walk-in';
    return props.booking.type.replace('_', ' ');
});

const statusLabel = computed(() => {
    const s = props.booking.status.toLowerCase();
    if (s === 'checked_in') return 'CHECKED IN';
    if (s === 'checked_out') return 'CHECKED OUT';
    if (s === 'cancelled') return 'CANCELLED';
    if (s === 'on_hold') return 'ON HOLD';
    if (s === 'enquiry') return 'ENQUIRY';
    if (s === 'expired_quote') return 'EXPIRED QUOTE';
    return 'CONFIRMED';
});

const statusStampColor = computed(() => {
    const s = props.booking.status.toLowerCase();
    if (s === 'cancelled') return 'border-red-500 text-red-500';
    if (s === 'checked_out' || s === 'expired_quote')
        return 'border-gray-500 text-gray-600';
    if (s === 'enquiry' || s === 'on_hold')
        return 'border-amber-500 text-amber-600';
    return 'border-emerald-500 text-emerald-600';
});

function localToday(): string {
    const d = new Date();

    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
}

/** Guests can arrive from the check-in date until the day before check-out. */
const canCheckIn = computed(() => {
    const today = props.today ?? localToday();

    return (
        props.booking.status === 'confirmed' &&
        !!props.booking.check_in &&
        !!props.booking.check_out &&
        props.booking.check_in <= today &&
        today < props.booking.check_out
    );
});

/** Rooms that can arrive today; with more than one, CHECK IN asks which of them to check in. */
const arrivingRooms = computed(() => {
    const today = props.today ?? localToday();

    return props.booking.rooms.filter(
        (room) =>
            room.stay_status === 'booked' &&
            room.check_in_date <= today &&
            today < room.check_out_date,
    );
});

const checkinChoiceOpen = ref(false);
const checkinScope = ref<'all' | 'selected'>('all');
const checkinRoomIds = ref<number[]>([]);

/** The reported check-in / check-out date & time the front desk confirms (defaults to now). */
const stayTime = ref('');
const stayError = ref('');
const stayProcessing = ref(false);
/** Early check-in / late check-out: the front desk waives the charge shown in the dialog. */
const waiveStayCharge = ref(false);
const stayReportedAt = computed(() =>
    stayTime.value ? propertyTime.fromInput(stayTime.value) : null,
);

/** Keeps the dialog open on a refusal, with the reason; closes it when the change went through. */
function stayRequestOptions(close: () => void) {
    stayError.value = '';
    stayProcessing.value = true;

    return {
        preserveScroll: true,
        onSuccess: () => {
            // Refusals (missing guest details, outside the stay dates…) come back as an error flash.
            if (usePage().props.flash.error) {
                stayError.value = usePage().props.flash.error ?? '';
            } else {
                close();
            }
        },
        onError: (errors: Record<string, string>) =>
            (stayError.value =
                Object.values(errors)[0] ?? 'The change was not saved.'),
        onFinish: () => (stayProcessing.value = false),
    };
}

function startCheckIn(): void {
    checkinScope.value = 'all';
    checkinRoomIds.value = arrivingRooms.value.map(
        (room) => room.sub_booking_id,
    );
    stayTime.value = propertyTime.toInput();
    stayError.value = '';
    waiveStayCharge.value = false;
    checkInAnyway.value = false;
    checkinChoiceOpen.value = true;
}

// ── Housekeeping at check-in: rooms that aren't ready (Booking Settings → Housekeeping) ──
/** Confirms checking in rooms whose status doesn't allow check-in (not those behind a hard stop). */
const checkInAnyway = ref(false);

const roomsCheckingIn = computed(() =>
    checkinScope.value === 'all'
        ? arrivingRooms.value
        : arrivingRooms.value.filter((room) =>
              checkinRoomIds.value.includes(room.sub_booking_id),
          ),
);

const housekeepingBlocks = computed(() => ({
    hard: roomsCheckingIn.value.filter(
        (room) =>
            room.housekeeping &&
            !room.housekeeping.allow_check_in &&
            room.housekeeping.hard_stop,
    ),
    soft: roomsCheckingIn.value.filter(
        (room) =>
            room.housekeeping &&
            !room.housekeeping.allow_check_in &&
            !room.housekeeping.hard_stop,
    ),
}));

const checkinBlocked = computed(
    () =>
        housekeepingBlocks.value.hard.length > 0 ||
        (housekeepingBlocks.value.soft.length > 0 && !checkInAnyway.value),
);

function confirmCheckinChoice(): void {
    if (
        (checkinScope.value === 'selected' &&
            checkinRoomIds.value.length === 0) ||
        checkinBlocked.value
    ) {
        return;
    }

    const options = stayRequestOptions(() => (checkinChoiceOpen.value = false));

    if (
        checkinScope.value === 'all' ||
        checkinRoomIds.value.length === arrivingRooms.value.length
    ) {
        router.patch(
            admin.bookings.updateStatus({
                property: props.property,
                booking: props.booking.id,
            }).url,
            {
                status: 'checked_in',
                reported_at: propertyTime.fromInput(stayTime.value),
                override_housekeeping: checkInAnyway.value,
                waive_stay_time_charges: waiveStayCharge.value,
            },
            options,
        );

        return;
    }

    router.post(
        admin.bookings.rooms.checkIn({
            property: props.property.id,
            booking: props.booking.id,
        }).url,
        {
            sub_booking_ids: checkinRoomIds.value,
            reported_at: propertyTime.fromInput(stayTime.value),
            override_housekeeping: checkInAnyway.value,
            waive_stay_time_charges: waiveStayCharge.value,
        },
        options,
    );
}

/** Rooms currently in house; with more than one, CHECK OUT asks which of them are leaving. */
const inHouseRooms = computed(() =>
    props.booking.rooms.filter((room) => room.stay_status === 'checked_in'),
);

const checkoutChoiceOpen = ref(false);
const checkoutScope = ref<'all' | 'selected'>('all');
const checkoutRoomIds = ref<number[]>([]);

/** "Room 2 | Double Room · Room No. 101" — numbered like the room details and folios. */
function roomLabel(room: RoomItem): string {
    const position =
        props.booking.rooms.findIndex(
            (candidate) => candidate.sub_booking_id === room.sub_booking_id,
        ) + 1;

    return `Room ${position} | ${room.category_name} · Room No. ${room.room_number}`;
}

/** Check-out → e-mail the invoice to the guest (on by default when the guest has an address). */
const sendInvoice = ref(true);
/** Check-out → "Mark room as": the housekeeping status the rooms take (default: the check-out default). */
const checkoutHousekeeping = ref<string | null>(null);
const guestEmail = computed(() =>
    primaryGuest.value.email && primaryGuest.value.email !== '—'
        ? primaryGuest.value.email
        : null,
);

function startCheckout(): void {
    checkoutScope.value = 'all';
    checkoutRoomIds.value = inHouseRooms.value.map(
        (room) => room.sub_booking_id,
    );
    stayTime.value = propertyTime.toInput();
    stayError.value = '';
    waiveStayCharge.value = false;
    sendInvoice.value = guestEmail.value !== null;
    checkoutHousekeeping.value =
        props.housekeepingCheckOutDefault ??
        props.housekeepingStatuses[0]?.name ??
        null;
    checkoutChoiceOpen.value = true;
}

function confirmCheckoutChoice(): void {
    if (
        checkoutScope.value === 'selected' &&
        checkoutRoomIds.value.length === 0
    ) {
        return;
    }

    const options = stayRequestOptions(
        () => (checkoutChoiceOpen.value = false),
    );

    if (
        inHouseRooms.value.length < 2 ||
        checkoutScope.value === 'all' ||
        checkoutRoomIds.value.length === inHouseRooms.value.length
    ) {
        handleCheckout(options);

        return;
    }

    router.post(
        admin.bookings.rooms.checkOut({
            property: props.property.id,
            booking: props.booking.id,
        }).url,
        {
            sub_booking_ids: checkoutRoomIds.value,
            reported_at: propertyTime.fromInput(stayTime.value),
            send_invoice: sendInvoice.value,
            housekeeping_status: checkoutHousekeeping.value,
            waive_stay_time_charges: waiveStayCharge.value,
        },
        options,
    );
}

/** Reported (actual) check-in / check-out; rooms that aren't split share one. */
const reportedStay = computed(() => ({
    checkedInAt:
        props.booking.rooms.map((room) => room.checked_in_at).find(Boolean) ??
        null,
    checkedOutAt: props.booking.rooms.every((room) => room.checked_out_at)
        ? (props.booking.rooms
              .map((room) => room.checked_out_at)
              .find(Boolean) ?? null)
        : null,
}));

function handleCheckout(options: ReturnType<typeof stayRequestOptions>): void {
    // A pay-at-hotel booking settles at the desk (the server enforces this too).
    if (props.booking.pay_at_hotel && props.booking.outstanding > 0) {
        stayProcessing.value = false;
        stayError.value =
            'It is set payable at hotel and it is not fully paid.';

        return;
    }

    router.patch(
        admin.bookings.updateStatus({
            property: props.property,
            booking: props.booking.id,
        }).url,
        {
            status: 'checked_out',
            reported_at: propertyTime.fromInput(stayTime.value),
            send_invoice: sendInvoice.value,
            housekeeping_status: checkoutHousekeeping.value,
            waive_stay_time_charges: waiveStayCharge.value,
        },
        options,
    );
}

function changeStatus(newStatus: string): void {
    router.patch(
        admin.bookings.updateStatus({
            property: props.property,
            booking: props.booking.id,
        }).url,
        { status: newStatus },
        { preserveScroll: true },
    );
}

function markNoStay(): void {
    if (
        typeof window !== 'undefined' &&
        !window.confirm(
            'Are you sure you want to mark this booking as No Stay?',
        )
    ) {
        return;
    }
    router.patch(
        admin.bookings.updateStatus({
            property: props.property,
            booking: props.booking.id,
        }).url,
        { status: 'no_stay' },
        { preserveScroll: true },
    );
}

function printPage(): void {
    window.print();
}

function showAlert(msg: string): void {
    if (typeof window !== 'undefined') {
        window.alert(msg);
    }
}
</script>

<template>
    <Head :title="`Reservation #${booking.booking_ref_id}`" />

    <div class="relative pb-24 text-[#333]">
        <!-- Top Back Link & Actions -->
        <div class="mb-3 flex items-center justify-between">
            <Link
                :href="
                    admin.databases.bookings.index({
                        query: { property: property.id },
                    }).url
                "
                class="inline-flex items-center gap-1.5 text-xs font-semibold text-[#0284c7] hover:underline dark:text-sky-400"
            >
                <svg
                    xmlns="http://www.w3.org/2000/svg"
                    class="h-3.5 w-3.5"
                    fill="none"
                    viewBox="0 0 24 24"
                    stroke="currentColor"
                >
                    <path
                        stroke-linecap="round"
                        stroke-linejoin="round"
                        stroke-width="2.5"
                        d="M15 19l-7-7 7-7"
                    />
                </svg>
                Go Back
            </Link>
        </div>

        <!-- 3-Column Layout Grid -->
        <div class="grid grid-cols-1 items-start gap-5 lg:grid-cols-12">
            <!-- ================= LEFT SIDEBAR CARD ================= -->
            <div class="lg:col-span-3">
                <div
                    class="sticky top-4 overflow-hidden rounded border border-gray-300 bg-white shadow-sm dark:border-[#3E3E3A] dark:bg-[#161615]"
                >
                    <!-- Header (Green / Dark) -->
                    <div class="flex text-white">
                        <div class="flex-1 bg-[#16a34a] px-3 py-2">
                            <span class="text-sm font-bold tracking-tight"
                                >#{{ booking.booking_ref_id }}</span
                            >
                        </div>
                        <div class="bg-[#1e293b] px-3 py-2 text-right">
                            <div
                                class="text-[9px] font-bold tracking-wider text-gray-400"
                            >
                                REF I.D.
                            </div>
                            <div class="text-xs font-semibold text-gray-100">
                                {{ booking.booking_ref_id }}
                            </div>
                        </div>
                    </div>

                    <!-- Stay Dates Block -->
                    <div
                        class="border-b border-gray-200 px-4 py-3 dark:border-[#2b2b28]"
                    >
                        <div
                            class="flex items-center justify-between text-center"
                        >
                            <div>
                                <div
                                    class="text-3xl font-extrabold text-[#111] dark:text-[#f3f4f6]"
                                >
                                    {{ checkInParts.day }}
                                </div>
                                <div
                                    class="text-[10px] font-semibold text-gray-500 uppercase"
                                >
                                    {{ checkInParts.monthYear }}
                                </div>
                            </div>
                            <div class="flex flex-col items-center px-2">
                                <span
                                    class="text-[11px] font-medium text-gray-400"
                                    >........
                                    {{ booking.nights }} ........</span
                                >
                            </div>
                            <div>
                                <div
                                    class="text-3xl font-extrabold text-[#111] dark:text-[#f3f4f6]"
                                >
                                    {{ checkOutParts.day }}
                                </div>
                                <div
                                    class="text-[10px] font-semibold text-gray-500 uppercase"
                                >
                                    {{ checkOutParts.monthYear }}
                                </div>
                            </div>
                        </div>

                        <!-- Room and Meal plan summary -->
                        <div
                            class="mt-2.5 flex items-center justify-between border-t border-gray-100 pt-2 text-[11px] font-medium text-gray-600 dark:border-[#2b2b28] dark:text-gray-400"
                        >
                            <span class="flex min-w-0 flex-col gap-0.5">
                                <span
                                    v-for="line in summaryRoomLines"
                                    :key="line"
                                    class="truncate"
                                    >{{ line }}</span
                                >
                            </span>
                            <span class="text-orange-600" title="Guests">
                                <svg
                                    xmlns="http://www.w3.org/2000/svg"
                                    class="inline h-4 w-4"
                                    viewBox="0 0 20 20"
                                    fill="currentColor"
                                >
                                    <path
                                        fill-rule="evenodd"
                                        d="M10 9a3 3 0 100-6 3 3 0 000 6zm-7 9a7 7 0 1114 0H3z"
                                        clip-rule="evenodd"
                                    />
                                </svg>
                            </span>
                        </div>
                    </div>

                    <!-- Guest Details & Status Stamp -->
                    <div
                        class="relative flex items-center justify-between border-b border-gray-200 p-4 dark:border-[#2b2b28]"
                    >
                        <div class="space-y-0.5 pr-2 text-left">
                            <div
                                class="text-sm font-bold text-[#111] dark:text-[#EDEDEC]"
                            >
                                {{ primaryGuest.name }}
                            </div>
                            <div
                                class="text-xs text-gray-600 dark:text-gray-400"
                            >
                                {{ primaryGuest.mobile || '—' }}
                            </div>
                            <div
                                class="text-xs text-gray-500 dark:text-gray-500"
                            >
                                {{ primaryGuest.email || '--' }}
                            </div>
                        </div>

                        <!-- Circular Stamp -->
                        <div
                            class="relative flex h-16 w-16 flex-shrink-0 -rotate-12 items-center justify-center rounded-full border-2 border-dashed p-1 text-center select-none"
                            :class="statusStampColor"
                        >
                            <div
                                class="flex h-full w-full items-center justify-center rounded-full border border-solid"
                                :class="statusStampColor"
                            >
                                <span
                                    class="text-[9px] leading-tight font-black tracking-tighter"
                                    >{{ statusLabel }}</span
                                >
                            </div>
                        </div>
                    </div>

                    <!-- Financial Summary -->
                    <div
                        class="flex items-center justify-between bg-gray-50/70 p-3 text-xs dark:bg-[#1f1f1d]"
                    >
                        <div>
                            <div
                                class="text-[10px] font-bold tracking-wide text-gray-400 uppercase"
                            >
                                TOTAL BILL
                            </div>
                            <div
                                class="text-sm font-extrabold text-[#111] dark:text-[#EDEDEC]"
                                data-test="total-bill"
                            >
                                {{ code }} {{ formatCurrency(totalBill) }}
                            </div>
                        </div>
                        <div class="text-right">
                            <!-- The guest's share; what the guest's company pays is shown under it. -->
                            <div
                                class="text-[10px] font-bold tracking-wide text-gray-400 uppercase"
                            >
                                OUTSTANDING
                            </div>
                            <template v-if="netPayableByGuest > 0">
                                <button
                                    type="button"
                                    class="mt-0.5 inline-flex cursor-pointer items-center gap-1 rounded bg-[#ea580c] px-2 py-0.5 text-xs font-bold text-white shadow-xs transition hover:bg-[#c2410c]"
                                    @click="billingPanel?.openCollectOnline()"
                                >
                                    <span>Collect Online &gt;&gt;</span>
                                    <span
                                        >{{ code }}
                                        {{
                                            formatCurrency(netPayableByGuest)
                                        }}</span
                                    >
                                </button>
                            </template>
                            <div
                                v-else
                                class="text-sm font-extrabold text-[#111] dark:text-[#EDEDEC]"
                            >
                                {{ code }} 0.00
                            </div>
                            <div
                                v-if="netPayableByCompany"
                                class="mt-0.5 text-[10px] font-semibold text-emerald-700 dark:text-emerald-400"
                                data-test="summary-company-pays"
                            >
                                Company pays {{ code }}
                                {{ formatCurrency(netPayableByCompany) }}
                            </div>
                            <div
                                v-if="
                                    exchangeRates.length &&
                                    netPayableByGuest > 0
                                "
                                class="mt-0.5 flex items-center justify-end gap-1 text-[10px] text-gray-500 dark:text-gray-400"
                            >
                                <span>≈</span>
                                <select
                                    v-model="foreignCurrency"
                                    class="rounded border border-gray-200 bg-white px-0.5 py-0 text-[10px] dark:border-[#3E3E3A] dark:bg-[#0a0a0a]"
                                    aria-label="Show balance in"
                                >
                                    <option
                                        v-for="rate in exchangeRates"
                                        :key="rate.currency_code"
                                        :value="rate.currency_code"
                                    >
                                        {{ rate.currency_code }}
                                    </option>
                                </select>
                                <span v-if="foreignOutstanding !== null">{{
                                    foreignOutstanding.toFixed(2)
                                }}</span>
                            </div>
                        </div>
                    </div>

                    <!-- 4 Quick Action / Status Tiles -->
                    <div
                        class="grid grid-cols-4 divide-x divide-gray-200 border-t border-gray-200 bg-gray-50 text-center dark:divide-[#3E3E3A] dark:border-[#3E3E3A] dark:bg-[#1a1a18]"
                    >
                        <!-- Tile 1: Source -->
                        <div
                            class="flex flex-col items-center justify-center py-2.5 text-[10px] font-semibold text-gray-700 dark:text-gray-300"
                        >
                            <svg
                                xmlns="http://www.w3.org/2000/svg"
                                class="mb-0.5 h-4 w-4 text-gray-600 dark:text-gray-400"
                                fill="none"
                                viewBox="0 0 24 24"
                                stroke="currentColor"
                            >
                                <path
                                    stroke-linecap="round"
                                    stroke-linejoin="round"
                                    stroke-width="2"
                                    d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"
                                />
                            </svg>
                            <span class="max-w-[55px] truncate uppercase">{{
                                sourceDisplay
                            }}</span>
                        </div>
                        <!-- Tile 2: Currency / Note -->
                        <div
                            class="flex flex-col items-center justify-center py-2.5 text-[10px] font-semibold text-gray-700 dark:text-gray-300"
                        >
                            <svg
                                xmlns="http://www.w3.org/2000/svg"
                                class="mb-0.5 h-4 w-4 text-gray-600 dark:text-gray-400"
                                fill="none"
                                viewBox="0 0 24 24"
                                stroke="currentColor"
                            >
                                <path
                                    stroke-linecap="round"
                                    stroke-linejoin="round"
                                    stroke-width="2"
                                    d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z"
                                />
                            </svg>
                            <span>..</span>
                        </div>
                        <!-- Tile 3: Stay Status Icon -->
                        <div
                            class="flex flex-col items-center justify-center py-2.5 text-[10px] font-semibold text-gray-700 dark:text-gray-300"
                        >
                            <svg
                                xmlns="http://www.w3.org/2000/svg"
                                class="mb-0.5 h-4 w-4 text-gray-600 dark:text-gray-400"
                                fill="none"
                                viewBox="0 0 24 24"
                                stroke="currentColor"
                            >
                                <path
                                    stroke-linecap="round"
                                    stroke-linejoin="round"
                                    stroke-width="2"
                                    d="M11 16l-4-4m0 0l4-4m-4 4h14m-5 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h7a3 3 0 013 3v1"
                                />
                            </svg>
                            <span class="max-w-[55px] truncate uppercase">{{
                                statusLabel
                            }}</span>
                        </div>
                        <!-- Tile 4: Card Icon -->
                        <div
                            class="flex flex-col items-center justify-center py-2.5 text-[10px] font-semibold text-gray-700 dark:text-gray-300"
                        >
                            <svg
                                xmlns="http://www.w3.org/2000/svg"
                                class="mb-0.5 h-4 w-4 text-gray-600 dark:text-gray-400"
                                fill="none"
                                viewBox="0 0 24 24"
                                stroke="currentColor"
                            >
                                <path
                                    stroke-linecap="round"
                                    stroke-linejoin="round"
                                    stroke-width="2"
                                    d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z"
                                />
                            </svg>
                            <span>..</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ================= CENTER MAIN CONTENT ================= -->
            <div class="lg:col-span-6">
                <!-- Navigation Tabs -->
                <div
                    class="flex items-center gap-1 border-b border-gray-300 dark:border-[#3E3E3A]"
                >
                    <button
                        type="button"
                        class="rounded-t border-x border-t border-gray-300 px-4 py-1.5 text-xs font-bold tracking-wide transition-colors dark:border-[#3E3E3A]"
                        :class="
                            activeTab === 'general'
                                ? 'bg-white text-[#111] dark:bg-[#161615] dark:text-[#EDEDEC]'
                                : 'bg-gray-100 text-gray-500 hover:text-gray-800 dark:bg-[#20201e] dark:text-gray-400'
                        "
                        @click="activeTab = 'general'"
                    >
                        GENERAL
                    </button>
                    <button
                        type="button"
                        class="rounded-t px-3 py-1.5 text-xs font-semibold text-gray-500 hover:bg-gray-100 hover:text-gray-800 dark:text-gray-400 dark:hover:bg-[#20201e]"
                        @click="activeTab = 'outlet'"
                    >
                        + Outlet
                    </button>
                </div>

                <!-- Main Container Card -->
                <div
                    class="divide-y divide-gray-200 rounded-b border-x border-b border-gray-300 bg-white text-xs shadow-sm dark:divide-[#2b2b28] dark:border-[#3E3E3A] dark:bg-[#161615]"
                >
                    <!-- SECTION 1: Booking Details -->
                    <div class="p-4">
                        <h2
                            class="mb-3 text-sm font-bold text-[#1b1b18] dark:text-[#EDEDEC]"
                        >
                            Booking Details
                        </h2>
                        <div
                            class="grid grid-cols-1 gap-y-3 text-xs sm:grid-cols-2 sm:gap-x-6"
                        >
                            <div>
                                <span class="font-medium text-gray-500"
                                    >Booking source:</span
                                >
                                <span
                                    class="ml-2 font-semibold text-[#111] dark:text-gray-200"
                                    >{{ sourceDisplay }}</span
                                >
                            </div>
                            <div>
                                <span class="font-medium text-gray-500"
                                    >Type:</span
                                >
                                <span
                                    class="ml-2 font-semibold text-[#111] dark:text-gray-200"
                                    >{{ typeDisplay }}</span
                                >
                            </div>
                            <div>
                                <span class="font-medium text-gray-500"
                                    >Booking Ref. Id:</span
                                >
                                <span
                                    class="ml-2 font-semibold text-[#111] dark:text-gray-200"
                                    >{{ booking.booking_ref_id }}</span
                                >
                            </div>
                            <div>
                                <span class="font-medium text-gray-500"
                                    >Pay At Hotel:</span
                                >
                                <span
                                    class="ml-2 font-semibold text-[#111] dark:text-gray-200"
                                    >{{
                                        booking.pay_at_hotel ? 'Yes' : 'No'
                                    }}</span
                                >
                            </div>
                            <div class="sm:col-span-2">
                                <span class="font-medium text-gray-500"
                                    >Date:</span
                                >
                                <span
                                    class="ml-2 font-semibold text-[#111] dark:text-gray-200"
                                    >{{ checkInParts.full }} --
                                    {{ checkOutParts.full }}</span
                                >
                            </div>
                            <div>
                                <span class="font-medium text-gray-500"
                                    >Rooms:</span
                                >
                                <span
                                    class="ml-2 font-semibold text-[#111] dark:text-gray-200"
                                    >{{ booking.rooms.length }}</span
                                >
                            </div>
                            <div>
                                <span class="font-medium text-gray-500"
                                    >Meal Plan:</span
                                >
                                <span
                                    class="ml-2 font-semibold text-[#111] dark:text-gray-200"
                                    >{{ primaryRoom.meal_plan }}</span
                                >
                            </div>
                            <div class="sm:col-span-2">
                                <span class="font-medium text-gray-500"
                                    >Rate Plan:</span
                                >
                                <span
                                    class="ml-2 font-semibold text-[#111] dark:text-gray-200"
                                    >{{
                                        booking.rate_plan ||
                                        'Best Available Rates'
                                    }}</span
                                >
                            </div>
                        </div>
                    </div>

                    <!-- SECTION 2: Requirements -->
                    <div class="p-4">
                        <h2
                            class="mb-3 text-sm font-bold text-[#1b1b18] dark:text-[#EDEDEC]"
                        >
                            Requirements
                        </h2>
                        <div
                            v-if="!booking.rooms_split"
                            class="mb-3 grid grid-cols-2 gap-2 rounded bg-gray-50 px-3 py-2 text-xs dark:bg-[#1D1D1B]"
                        >
                            <div>
                                <span class="text-gray-500"
                                    >Reported Check-in:</span
                                >
                                <span
                                    class="ml-1 font-semibold text-[#111] dark:text-gray-200"
                                    >{{
                                        propertyTime.dateTime(
                                            reportedStay.checkedInAt,
                                        )
                                    }}</span
                                >
                            </div>
                            <div>
                                <span class="text-gray-500"
                                    >Reported Check-out:</span
                                >
                                <span
                                    class="ml-1 font-semibold text-[#111] dark:text-gray-200"
                                    >{{
                                        propertyTime.dateTime(
                                            reportedStay.checkedOutAt,
                                        )
                                    }}</span
                                >
                            </div>
                        </div>
                        <div
                            v-for="(room, idx) in booking.rooms"
                            :key="room.id"
                            class="mb-4 last:mb-0"
                        >
                            <div
                                class="mb-2 flex items-center justify-between font-bold text-[#111] dark:text-[#EDEDEC]"
                            >
                                <span
                                    >Room {{ idx + 1 }} |
                                    {{ room.category_name }}</span
                                >
                                <button
                                    v-if="mayUpgrade(room)"
                                    type="button"
                                    class="rounded border border-[#ea580c] px-2 py-0.5 text-[11px] font-semibold text-[#ea580c] hover:bg-orange-50"
                                    :data-test="`upgrade-room-${room.sub_booking_id}`"
                                    @click="openUpgrade(room)"
                                >
                                    Upgrade
                                </button>
                            </div>
                            <p
                                v-for="upgrade in upgrades.filter(
                                    (entry) =>
                                        entry.sub_booking_id ===
                                        room.sub_booking_id,
                                )"
                                :key="upgrade.id"
                                class="mb-2 rounded bg-violet-50 px-2 py-1 text-[11px] text-violet-900 dark:bg-violet-950/40 dark:text-violet-200"
                            >
                                {{
                                    upgrade.type === 'paid'
                                        ? 'Paid'
                                        : 'Complimentary'
                                }}
                                upgrade {{ upgrade.from }} → {{ upgrade.to
                                }}<template v-if="upgrade.type === 'paid'"
                                    >, +{{
                                        formatCurrency(
                                            upgrade.supplement_per_night,
                                        )
                                    }}
                                    × {{ upgrade.nights }} night(s)</template
                                >
                                — {{ upgrade.reason }} ({{
                                    upgrade.by ?? 'System'
                                }}, {{ propertyTime.dateTime(upgrade.at) }})
                            </p>
                            <!-- A split booking's rooms keep their own dates and report their own arrival / departure. -->
                            <div
                                v-if="booking.rooms_split"
                                class="mb-2 grid grid-cols-2 gap-2 rounded bg-gray-50 px-3 py-2 text-xs dark:bg-[#1D1D1B]"
                            >
                                <div class="col-span-2 text-gray-500">
                                    Stay:
                                    {{
                                        parseDateParts(room.check_in_date).full
                                    }}
                                    →
                                    {{
                                        parseDateParts(room.check_out_date).full
                                    }}
                                </div>
                                <div>
                                    <span class="text-gray-500"
                                        >Reported Check-in:</span
                                    >
                                    <span
                                        class="ml-1 font-semibold text-[#111] dark:text-gray-200"
                                        >{{
                                            propertyTime.dateTime(
                                                room.checked_in_at,
                                            )
                                        }}</span
                                    >
                                </div>
                                <div>
                                    <span class="text-gray-500"
                                        >Reported Check-out:</span
                                    >
                                    <span
                                        class="ml-1 font-semibold text-[#111] dark:text-gray-200"
                                        >{{
                                            propertyTime.dateTime(
                                                room.checked_out_at,
                                            )
                                        }}</span
                                    >
                                </div>
                            </div>

                            <div
                                class="grid grid-cols-5 gap-2 text-xs text-gray-500"
                            >
                                <div>Adults</div>
                                <div>Children</div>
                                <div>Infant</div>
                                <div>Room No.</div>
                                <div class="text-right">Price</div>
                            </div>
                            <div
                                class="mt-0.5 grid grid-cols-5 gap-2 text-xs font-semibold text-[#111] dark:text-gray-200"
                            >
                                <div>{{ room.adults }}</div>
                                <div>{{ room.children }}</div>
                                <div>{{ room.infants }}</div>
                                <div>{{ room.room_number }}</div>
                                <div
                                    class="text-right font-bold text-gray-800 dark:text-gray-200"
                                >
                                    {{ code }}
                                    {{ formatCurrency(room.total_amount) }} /
                                    {{ booking.nights }} nt
                                </div>
                            </div>

                            <!-- Financial details of requirement -->
                            <div
                                class="mt-3 flex flex-col items-end border-t border-gray-100 pt-2 text-xs dark:border-[#2b2b28]"
                            >
                                <div class="flex w-64 justify-between py-0.5">
                                    <span class="text-gray-500"
                                        >Room Total</span
                                    >
                                    <span
                                        class="font-medium text-gray-800 dark:text-gray-200"
                                        >{{
                                            formatCurrency(room.total_amount)
                                        }}</span
                                    >
                                </div>
                                <div class="flex w-64 justify-between py-0.5">
                                    <span class="text-gray-500">Discount</span>
                                    <span
                                        class="font-medium text-gray-800 dark:text-gray-200"
                                        >({{
                                            formatCurrency(room.discount ?? 0)
                                        }})</span
                                    >
                                </div>
                                <div class="flex w-64 justify-between py-0.5">
                                    <span class="text-gray-500">Taxes</span>
                                    <span
                                        class="font-medium text-gray-800 dark:text-gray-200"
                                        >{{
                                            formatCurrency(room.taxes ?? 0)
                                        }}</span
                                    >
                                </div>
                                <div
                                    class="mt-1 flex w-64 justify-between border-t border-dashed border-gray-300 pt-1 font-bold dark:border-[#3E3E3A]"
                                >
                                    <span
                                        class="text-gray-800 dark:text-gray-200"
                                        >Total</span
                                    >
                                    <span
                                        class="inline-flex items-center gap-1 text-gray-900 dark:text-white"
                                    >
                                        {{
                                            formatCurrency(
                                                room.total ?? room.total_amount,
                                            )
                                        }}
                                        <span
                                            class="cursor-pointer font-normal text-gray-400"
                                            title="Calculated Total"
                                            >ℹ</span
                                        >
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- SECTION 3: Billing Preferences -->
                    <div class="p-4">
                        <div class="mb-2 flex items-center gap-1.5">
                            <h2
                                class="text-sm font-bold text-[#1b1b18] dark:text-[#EDEDEC]"
                            >
                                Billing Preferences
                            </h2>
                            <span
                                class="cursor-pointer text-gray-400"
                                title="Billing routing details"
                                >ℹ</span
                            >
                        </div>
                        <div class="grid grid-cols-2 gap-4 text-xs">
                            <div>
                                <span class="font-medium text-gray-500"
                                    >Room Bill</span
                                >
                                <div
                                    class="mt-0.5 font-semibold text-[#111] dark:text-gray-200"
                                >
                                    {{
                                        booking.room_bill_to === 'to_company'
                                            ? 'To Company'
                                            : 'To Guest'
                                    }}
                                </div>
                            </div>
                            <div>
                                <span class="font-medium text-gray-500"
                                    >Extras</span
                                >
                                <div
                                    class="mt-0.5 font-semibold text-[#111] dark:text-gray-200"
                                >
                                    {{
                                        booking.extras_bill_to === 'to_company'
                                            ? 'To Company'
                                            : 'To Guest'
                                    }}
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- SECTION 4: Guest Details -->
                    <div class="p-4">
                        <h2
                            class="mb-3 text-sm font-bold text-[#1b1b18] dark:text-[#EDEDEC]"
                        >
                            Guest Details
                        </h2>
                        <div class="flex items-center justify-between">
                            <div>
                                <div
                                    class="flex items-center gap-1.5 font-bold text-[#111] dark:text-[#EDEDEC]"
                                >
                                    <span>{{ primaryGuest.name }}</span>
                                    <span class="text-yellow-500">★</span>
                                    <button
                                        type="button"
                                        @click="printPage"
                                        class="text-gray-400 hover:text-black dark:hover:text-white"
                                        title="Print guest card"
                                    >
                                        🖨
                                    </button>
                                </div>
                                <div
                                    class="mt-0.5 text-xs text-gray-600 dark:text-gray-400"
                                >
                                    {{ primaryGuest.mobile || '—' }}
                                </div>
                            </div>
                            <div
                                class="text-right font-bold text-gray-800 dark:text-gray-200"
                            >
                                {{ primaryRoom.room_number }}
                            </div>
                        </div>
                    </div>

                    <!-- Web check-in: the guest's pre-arrival form -->
                    <WebCheckinPanel
                        :property-id="property.id"
                        :booking-id="booking.id"
                        :checkin="webCheckin"
                        :guest-email="
                            primaryGuest.email && primaryGuest.email !== '—'
                                ? primaryGuest.email
                                : null
                        "
                    />

                    <!-- SECTION 5: Addons -->
                    <BookingAddonsPanel
                        :property-id="property.id"
                        :booking-id="booking.id"
                        :lines="booking.addons"
                        :catalogue="addonCatalogue"
                        :editable="
                            ![
                                'cancelled',
                                'no_show',
                                'checked_out',
                                'expired_quote',
                            ].includes(booking.status)
                        "
                    />

                    <!-- Bill To Guest Company: who pays, what moved to the company and what it still owes. -->
                    <div
                        v-if="booking.company_billing"
                        class="rounded-lg border p-4 text-sm"
                        :class="
                            booking.company_billing.linked
                                ? 'border-emerald-300 bg-emerald-50 dark:border-emerald-800 dark:bg-emerald-950/30'
                                : 'border-amber-300 bg-amber-50 dark:border-amber-800 dark:bg-amber-950/30'
                        "
                        data-test="company-pays"
                    >
                        <template v-if="booking.company_billing.linked">
                            <div
                                class="flex flex-wrap items-center justify-between gap-2"
                            >
                                <p
                                    class="font-semibold text-[#1b1b18] dark:text-[#EDEDEC]"
                                >
                                    <span
                                        class="mr-2 rounded bg-emerald-600 px-1.5 py-0.5 text-xs font-semibold text-white"
                                        >Company pays</span
                                    >
                                    <Link
                                        :href="
                                            admin.databases.cityLedger.show(
                                                booking.company_billing
                                                    .account_id,
                                            ).url
                                        "
                                        class="underline"
                                        >{{
                                            booking.company_billing.name
                                        }}</Link
                                    >
                                    <span
                                        v-if="
                                            booking.company_billing.tax_number
                                        "
                                        class="ml-1 text-xs font-normal text-[#706f6c] dark:text-[#A1A09A]"
                                        >{{
                                            booking.company_billing.tax_number
                                        }}</span
                                    >
                                </p>
                                <p
                                    class="text-xs text-[#706f6c] dark:text-[#A1A09A]"
                                >
                                    {{
                                        [
                                            booking.company_billing.room
                                                ? 'Room bill'
                                                : '',
                                            booking.company_billing.extras
                                                ? 'Extras'
                                                : '',
                                        ]
                                            .filter(Boolean)
                                            .join(' + ')
                                    }}
                                    · pays in
                                    {{
                                        booking.company_billing
                                            .payment_terms_days
                                    }}
                                    days
                                </p>
                            </div>
                            <dl
                                class="mt-2 grid grid-cols-2 gap-2 sm:grid-cols-4"
                            >
                                <div>
                                    <dt
                                        class="text-xs text-[#706f6c] dark:text-[#A1A09A]"
                                    >
                                        To bill at checkout
                                    </dt>
                                    <dd class="font-medium">
                                        {{
                                            booking.company_billing.due_at_checkout.toFixed(
                                                2,
                                            )
                                        }}
                                    </dd>
                                </div>
                                <div>
                                    <dt
                                        class="text-xs text-[#706f6c] dark:text-[#A1A09A]"
                                    >
                                        Billed to company
                                    </dt>
                                    <dd class="font-medium">
                                        {{
                                            booking.company_billing.billed.toFixed(
                                                2,
                                            )
                                        }}
                                    </dd>
                                </div>
                                <div>
                                    <dt
                                        class="text-xs text-[#706f6c] dark:text-[#A1A09A]"
                                    >
                                        Unpaid by company
                                    </dt>
                                    <dd
                                        class="font-medium"
                                        :class="
                                            booking.company_billing.unpaid > 0
                                                ? 'text-[#f53003] dark:text-[#FF4433]'
                                                : ''
                                        "
                                    >
                                        {{
                                            booking.company_billing.unpaid.toFixed(
                                                2,
                                            )
                                        }}
                                    </dd>
                                </div>
                                <div>
                                    <dt
                                        class="text-xs text-[#706f6c] dark:text-[#A1A09A]"
                                    >
                                        Credit left
                                    </dt>
                                    <dd
                                        class="font-medium"
                                        :class="
                                            booking.company_billing
                                                .available_credit !== null &&
                                            booking.company_billing
                                                .available_credit <
                                                booking.company_billing
                                                    .due_at_checkout
                                                ? 'text-[#f53003] dark:text-[#FF4433]'
                                                : ''
                                        "
                                    >
                                        {{
                                            booking.company_billing
                                                .available_credit === null
                                                ? 'No limit'
                                                : booking.company_billing.available_credit.toFixed(
                                                      2,
                                                  )
                                        }}
                                    </dd>
                                </div>
                            </dl>
                            <p
                                v-if="
                                    booking.company_billing.available_credit !==
                                        null &&
                                    booking.company_billing.available_credit <
                                        booking.company_billing.due_at_checkout
                                "
                                class="mt-2 text-xs text-[#f53003] dark:text-[#FF4433]"
                            >
                                The company's credit left doesn't cover this
                                bill: at checkout it stays with the guest unless
                                the limit is raised.
                            </p>
                        </template>
                        <p v-else class="text-amber-800 dark:text-amber-300">
                            Billed to the guest's company, but the company isn't
                            linked to a City Ledger account, so the bill stays
                            with the guest. Link it under the guest's Add/Update
                            Company.
                        </p>
                    </div>

                    <BookingBillingPanel
                        ref="billingPanel"
                        :property="property"
                        :booking="{
                            ...booking,
                            rooms_count: booking.rooms.length,
                        }"
                        :guest="primaryGuest"
                        :folios="folios"
                        :folio-payers="folioPayers"
                        :split-payment-modes="splitPaymentModes"
                        :payment-modes="paymentModes"
                        :guest-ledger-balance="guestLedgerBalance"
                        :properties="properties"
                        :staff="staff"
                        :current-user="currentUser"
                        :reservations="reservations"
                        :countries="countries"
                        :today="today"
                    />

                    <!-- SECTION 7: Allowance -->
                    <div class="p-4">
                        <div class="flex items-center justify-between">
                            <h2
                                class="text-sm font-bold text-[#1b1b18] dark:text-[#EDEDEC]"
                            >
                                Allowance
                            </h2>
                            <span class="text-[11px] text-gray-400 italic"
                                >*Allowance can be created after CheckIn and
                                before CheckOut</span
                            >
                        </div>
                    </div>

                    <!-- SECTION 8: Refunds -->
                    <div class="p-4">
                        <div class="flex items-center justify-between">
                            <h2
                                class="text-sm font-bold text-[#1b1b18] dark:text-[#EDEDEC]"
                            >
                                Refunds
                            </h2>
                            <button
                                v-if="totalPaid > totalBill"
                                type="button"
                                class="cursor-pointer rounded border border-gray-300 bg-white px-2.5 py-0.5 text-xs font-bold text-gray-800 shadow-xs hover:bg-gray-50 dark:border-[#3E3E3A] dark:bg-[#161615] dark:text-gray-200"
                            >
                                ADD
                            </button>
                        </div>
                    </div>

                    <!-- SECTION 9: Bill Summary -->
                    <div class="p-4">
                        <h2
                            class="mb-2 text-sm font-bold text-[#1b1b18] dark:text-[#EDEDEC]"
                        >
                            Bill Summary
                        </h2>
                        <div class="flex flex-col items-end text-xs">
                            <div class="flex w-72 justify-between py-0.5">
                                <span class="text-gray-600 dark:text-gray-400"
                                    >Room Charges</span
                                >
                                <span
                                    class="font-medium text-gray-800 dark:text-gray-200"
                                    >{{
                                        formatCurrency(booking.room_total)
                                    }}</span
                                >
                            </div>
                            <div class="flex w-72 justify-between py-0.5">
                                <span class="text-gray-600 dark:text-gray-400"
                                    >Addons (incl. tax)</span
                                >
                                <span
                                    class="font-medium text-gray-800 dark:text-gray-200"
                                    >{{
                                        formatCurrency(booking.addons_total)
                                    }}</span
                                >
                            </div>
                            <div class="flex w-72 justify-between py-0.5">
                                <span class="text-gray-600 dark:text-gray-400"
                                    >Discount</span
                                >
                                <span
                                    class="font-medium text-gray-800 dark:text-gray-200"
                                    >({{
                                        formatCurrency(booking.discount)
                                    }})</span
                                >
                            </div>
                            <div
                                class="mt-1 flex w-72 justify-between border-t border-gray-200 pt-1 font-semibold dark:border-[#2b2b28]"
                            >
                                <span class="text-gray-800 dark:text-gray-200"
                                    >Sub Total</span
                                >
                                <span class="text-gray-900 dark:text-white">{{
                                    formatCurrency(
                                        booking.room_total - booking.discount,
                                    )
                                }}</span>
                            </div>
                            <div class="flex w-72 justify-between py-0.5">
                                <span class="text-gray-600 dark:text-gray-400"
                                    >Taxes ℹ</span
                                >
                                <span
                                    class="font-medium text-gray-800 dark:text-gray-200"
                                    >{{ formatCurrency(booking.taxes) }}</span
                                >
                            </div>
                            <div class="flex w-72 justify-between py-0.5">
                                <span class="text-gray-600 dark:text-gray-400"
                                    >Payments</span
                                >
                                <span
                                    class="font-medium text-gray-800 dark:text-gray-200"
                                    >{{ formatCurrency(totalPaid) }}</span
                                >
                            </div>
                            <div class="flex w-72 justify-between py-0.5">
                                <span class="text-gray-600 dark:text-gray-400"
                                    >Credit Note</span
                                >
                                <span
                                    class="font-medium text-gray-800 dark:text-gray-200"
                                    >0.00</span
                                >
                            </div>
                            <div
                                v-if="netPayableByCompany !== null"
                                class="flex w-72 justify-between py-0.5 font-bold"
                                data-test="net-payable-company"
                            >
                                <span class="text-gray-700 dark:text-gray-300"
                                    >Net Payable by Company</span
                                >
                                <span class="text-gray-900 dark:text-white">{{
                                    formatCurrency(netPayableByCompany)
                                }}</span>
                            </div>
                            <div
                                class="flex w-72 justify-between py-0.5 font-bold"
                            >
                                <span class="text-gray-700 dark:text-gray-300"
                                    >Net Payable by Guest</span
                                >
                                <span class="text-gray-900 dark:text-white">{{
                                    formatCurrency(netPayableByGuest)
                                }}</span>
                            </div>
                            <div
                                class="flex w-72 justify-between py-0.5 font-bold"
                            >
                                <span class="text-gray-700 dark:text-gray-300"
                                    >Net Payable at Hotel</span
                                >
                                <span class="text-gray-900 dark:text-white">{{
                                    formatCurrency(netPayableAtHotel)
                                }}</span>
                            </div>
                        </div>
                    </div>

                    <!-- Guest ledger -->
                    <div
                        v-if="guestLedger"
                        class="border-b border-gray-200 p-4 dark:border-[#2b2b28]"
                        data-test="guest-ledger"
                    >
                        <div class="flex items-center justify-between">
                            <h2
                                class="text-sm font-bold text-[#1b1b18] dark:text-[#EDEDEC]"
                            >
                                Guest Ledger · {{ guestLedger.guest_name }}
                            </h2>
                            <span
                                class="text-sm font-semibold"
                                :class="
                                    guestLedger.balance > 0
                                        ? 'text-emerald-700 dark:text-emerald-400'
                                        : 'text-gray-500'
                                "
                            >
                                Balance
                                {{ formatCurrency(guestLedger.balance) }}
                            </span>
                        </div>
                        <p
                            class="mt-1 text-xs text-gray-500 dark:text-gray-400"
                        >
                            Money held for the guest at this property, to use on
                            their bookings.
                        </p>

                        <div class="mt-3 flex flex-wrap gap-4">
                            <form
                                v-if="guestLedger.booking_credit > 0"
                                class="flex items-end gap-2"
                                @submit.prevent="moveGuestLedger('deposit')"
                            >
                                <label
                                    class="flex flex-col gap-1 text-xs text-gray-600 dark:text-gray-400"
                                >
                                    Move this booking's credit ({{
                                        formatCurrency(
                                            guestLedger.booking_credit,
                                        )
                                    }}) to the ledger
                                    <input
                                        v-model.number="
                                            guestLedgerAmount.deposit
                                        "
                                        type="number"
                                        min="0.01"
                                        :max="guestLedger.booking_credit"
                                        step="0.01"
                                        class="w-36 rounded border border-gray-300 px-2 py-1 text-sm dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]"
                                    />
                                </label>
                                <button
                                    type="submit"
                                    :disabled="guestLedgerBusy"
                                    class="rounded bg-[#ea580c] px-3 py-1.5 text-xs font-bold text-white uppercase hover:bg-[#c2410c] disabled:opacity-50"
                                    data-test="guest-ledger-deposit"
                                >
                                    Move to ledger
                                </button>
                            </form>
                            <form
                                v-if="
                                    guestLedger.balance > 0 &&
                                    guestLedger.booking_owed > 0
                                "
                                class="flex items-end gap-2"
                                @submit.prevent="moveGuestLedger('apply')"
                            >
                                <label
                                    class="flex flex-col gap-1 text-xs text-gray-600 dark:text-gray-400"
                                >
                                    Use the ledger on this booking (owes
                                    {{
                                        formatCurrency(
                                            guestLedger.booking_owed,
                                        )
                                    }})
                                    <input
                                        v-model.number="guestLedgerAmount.apply"
                                        type="number"
                                        min="0.01"
                                        :max="
                                            Math.min(
                                                guestLedger.balance,
                                                guestLedger.booking_owed,
                                            )
                                        "
                                        step="0.01"
                                        class="w-36 rounded border border-gray-300 px-2 py-1 text-sm dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]"
                                    />
                                </label>
                                <button
                                    type="submit"
                                    :disabled="guestLedgerBusy"
                                    class="rounded bg-[#ea580c] px-3 py-1.5 text-xs font-bold text-white uppercase hover:bg-[#c2410c] disabled:opacity-50"
                                    data-test="guest-ledger-apply"
                                >
                                    Use balance
                                </button>
                            </form>
                        </div>

                        <table
                            v-if="guestLedger.statement.length"
                            class="mt-3 w-full text-xs"
                        >
                            <thead>
                                <tr
                                    class="text-left text-gray-500 dark:text-gray-400"
                                >
                                    <th class="py-1 font-medium">Date</th>
                                    <th class="py-1 font-medium">Entry</th>
                                    <th class="py-1 text-right font-medium">
                                        In
                                    </th>
                                    <th class="py-1 text-right font-medium">
                                        Out
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr
                                    v-for="entry in guestLedger.statement"
                                    :key="entry.id"
                                    class="border-t border-gray-100 dark:border-[#2b2b28]"
                                >
                                    <td
                                        class="py-1 text-gray-600 dark:text-gray-400"
                                    >
                                        {{ propertyTime.dateTime(entry.at) }}
                                    </td>
                                    <td class="py-1 dark:text-gray-200">
                                        {{ entry.description }}
                                    </td>
                                    <td
                                        class="py-1 text-right text-emerald-700 dark:text-emerald-400"
                                    >
                                        {{
                                            entry.credit
                                                ? formatCurrency(entry.credit)
                                                : ''
                                        }}
                                    </td>
                                    <td
                                        class="py-1 text-right dark:text-gray-200"
                                    >
                                        {{
                                            entry.debit
                                                ? formatCurrency(entry.debit)
                                                : ''
                                        }}
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <!-- SECTION 10: Credit Note -->
                    <div class="p-4">
                        <div class="flex items-center justify-between">
                            <h2
                                class="text-sm font-bold text-[#1b1b18] dark:text-[#EDEDEC]"
                            >
                                Credit Note
                            </h2>
                            <button
                                type="button"
                                class="rounded border border-gray-300 bg-white px-2.5 py-0.5 text-xs font-bold text-gray-800 shadow-xs hover:bg-gray-50 dark:border-[#3E3E3A] dark:bg-[#161615] dark:text-gray-200"
                            >
                                Add
                            </button>
                        </div>
                    </div>

                    <!-- SECTION 11: Sales Executive -->
                    <div class="p-4">
                        <div class="flex items-center gap-1.5">
                            <h2
                                class="text-sm font-bold text-[#1b1b18] dark:text-[#EDEDEC]"
                            >
                                Sales Executive
                            </h2>
                            <span
                                class="cursor-pointer text-gray-400"
                                title="Sales executive assigned"
                                >ℹ</span
                            >
                        </div>
                    </div>

                    <!-- SECTION 12: Attachments -->
                    <div class="p-4">
                        <h2
                            class="text-sm font-bold text-[#1b1b18] dark:text-[#EDEDEC]"
                        >
                            Attachments
                        </h2>
                        <p
                            v-if="!booking.attachments.length"
                            class="mt-2 text-gray-500 dark:text-gray-400"
                        >
                            No documents uploaded.
                        </p>
                        <div
                            v-else
                            class="mt-2 divide-y divide-gray-200 rounded border border-gray-200 dark:divide-[#3E3E3A] dark:border-[#3E3E3A]"
                        >
                            <div
                                v-for="attachment in booking.attachments"
                                :key="attachment.id"
                                class="flex items-center justify-between gap-3 px-3 py-2"
                            >
                                <div class="min-w-0">
                                    <p
                                        class="font-semibold text-[#1b1b18] dark:text-[#EDEDEC]"
                                    >
                                        {{ attachment.document_type
                                        }}<template
                                            v-if="attachment.document_number"
                                        >
                                            ·
                                            {{
                                                attachment.document_number
                                            }}</template
                                        >
                                    </p>
                                    <p
                                        class="truncate text-xs text-gray-500 dark:text-gray-400"
                                    >
                                        {{ attachment.original_name
                                        }}<template
                                            v-if="attachment.expiry_date"
                                        >
                                            · Expires
                                            {{
                                                attachment.expiry_date
                                            }}</template
                                        ><template
                                            v-if="attachment.uploaded_by"
                                        >
                                            ·
                                            {{
                                                attachment.uploaded_by
                                            }}</template
                                        ><template v-if="attachment.uploaded_at"
                                            >,
                                            {{
                                                propertyTime.dateTime(
                                                    attachment.uploaded_at,
                                                )
                                            }}</template
                                        >
                                    </p>
                                </div>
                                <button
                                    type="button"
                                    class="shrink-0 rounded border border-gray-300 px-3 py-1 text-xs font-medium text-[#1b1b18] hover:bg-gray-50 dark:border-[#3E3E3A] dark:text-[#EDEDEC] dark:hover:bg-[#1D1D1B]"
                                    @click="viewAttachment(attachment)"
                                >
                                    View
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- SECTION 13: Others -->
                    <div class="space-y-3 p-4">
                        <h2
                            class="text-sm font-bold text-[#1b1b18] dark:text-[#EDEDEC]"
                        >
                            Others
                        </h2>

                        <!-- Inclusions -->
                        <div>
                            <div
                                class="font-semibold text-gray-700 dark:text-gray-300"
                            >
                                Inclusions
                            </div>
                            <div
                                v-if="
                                    booking.inclusions &&
                                    booking.inclusions.length
                                "
                                class="mt-1"
                            >
                                <ul
                                    class="list-inside list-disc text-gray-600 dark:text-gray-400"
                                >
                                    <li
                                        v-for="(inc, i) in viewMoreInclusions
                                            ? booking.inclusions
                                            : booking.inclusions.slice(0, 2)"
                                        :key="i"
                                    >
                                        {{ inc }}
                                    </li>
                                </ul>
                                <button
                                    v-if="booking.inclusions.length > 2"
                                    type="button"
                                    class="mt-1 text-[11px] font-semibold text-[#0284c7] hover:underline dark:text-sky-400"
                                    @click="
                                        viewMoreInclusions = !viewMoreInclusions
                                    "
                                >
                                    {{
                                        viewMoreInclusions
                                            ? '...View less'
                                            : '...View more'
                                    }}
                                </button>
                            </div>
                            <div v-else class="text-gray-400 italic">None</div>
                        </div>

                        <!-- Special Instructions -->
                        <div
                            class="border-t border-gray-100 pt-2 dark:border-[#2b2b28]"
                        >
                            <div
                                class="font-semibold text-gray-700 dark:text-gray-300"
                            >
                                Special Instructions
                                <span class="font-normal text-gray-400"
                                    >(shared with guest)</span
                                >
                            </div>
                            <div
                                class="mt-0.5 text-gray-600 dark:text-gray-400"
                            >
                                {{ booking.special_instructions || '—' }}
                            </div>
                        </div>

                        <!-- Internal Notes -->
                        <div
                            class="border-t border-gray-100 pt-2 dark:border-[#2b2b28]"
                        >
                            <div
                                class="font-semibold text-gray-700 dark:text-gray-300"
                            >
                                Internal Notes
                            </div>
                            <div
                                class="mt-0.5 text-gray-600 dark:text-gray-400"
                            >
                                {{ booking.internal_notes || '—' }}
                            </div>
                        </div>

                        <!-- Cancellation Policy -->
                        <div
                            class="border-t border-gray-100 pt-2 dark:border-[#2b2b28]"
                        >
                            <div
                                class="font-semibold text-gray-700 dark:text-gray-300"
                            >
                                Cancellation Policy
                            </div>
                            <div
                                class="mt-0.5 whitespace-pre-line text-gray-600 italic dark:text-gray-400"
                            >
                                {{ cancellationPolicy }}
                            </div>
                        </div>

                        <!-- Copy To -->
                        <div
                            class="border-t border-gray-100 pt-2 dark:border-[#2b2b28]"
                        >
                            <div
                                class="font-semibold text-gray-700 dark:text-gray-300"
                            >
                                Copy To:
                            </div>
                            <div
                                v-if="booking.copy_to && booking.copy_to.length"
                                class="mt-0.5 space-y-1"
                            >
                                <div
                                    v-for="(c, ci) in booking.copy_to"
                                    :key="ci"
                                    class="text-gray-600 dark:text-gray-400"
                                >
                                    {{ c.name }}
                                    <span v-if="c.phone">({{ c.phone }})</span>
                                    <span v-if="c.email"
                                        >&lt;{{ c.email }}&gt;</span
                                    >
                                </div>
                            </div>
                            <div v-else class="text-gray-400 italic">None</div>
                        </div>

                        <!-- Audit Timestamps -->
                        <div
                            class="border-t border-gray-100 pt-3 text-[11px] text-gray-500 dark:border-[#2b2b28]"
                        >
                            <div>
                                Last updated on
                                {{
                                    propertyTime.dateTime(
                                        booking.updated_at,
                                        true,
                                    )
                                }}, by {{ booking.updated_by || 'Staff' }}
                            </div>
                            <div class="mt-0.5">
                                Created on
                                {{
                                    propertyTime.dateTime(
                                        booking.created_at,
                                        true,
                                    )
                                }}, by {{ booking.created_by || 'Staff' }}
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ================= RIGHT SIDEBAR ================= -->
            <div class="space-y-4 lg:col-span-3">
                <!-- Tasks Card -->
                <div
                    class="overflow-hidden rounded border border-gray-300 bg-white shadow-sm dark:border-[#3E3E3A] dark:bg-[#161615]"
                >
                    <div
                        class="flex items-center justify-between bg-[#111] px-3 py-2 text-xs font-bold text-white dark:bg-[#0a0a0a]"
                    >
                        <span>Tasks</span>
                        <div class="flex items-center gap-1.5 text-gray-400">
                            <span class="cursor-pointer" title="Tasks info"
                                >ℹ</span
                            >
                            <span class="cursor-pointer">▶</span>
                        </div>
                    </div>
                    <div class="p-3 text-xs">
                        <label
                            class="flex cursor-pointer items-center gap-2 text-gray-600 dark:text-gray-400"
                        >
                            <input
                                type="checkbox"
                                class="rounded border-gray-300 text-orange-600 focus:ring-orange-500"
                            />
                            <span>No pending tasks</span>
                        </label>
                    </div>
                </div>

                <!-- Activity Log Card -->
                <div
                    class="overflow-hidden rounded border border-gray-300 bg-white shadow-sm dark:border-[#3E3E3A] dark:bg-[#161615]"
                >
                    <div
                        class="border-b border-gray-200 bg-gray-100 px-3 py-2 text-xs font-bold text-gray-700 dark:border-[#3E3E3A] dark:bg-[#1e1e1b] dark:text-gray-300"
                    >
                        Activity Log
                    </div>
                    <div
                        class="space-y-3 divide-y divide-gray-100 p-3 dark:divide-[#2b2b28]"
                    >
                        <div
                            v-for="log in activityLogs"
                            :key="log.id"
                            class="pt-2 first:pt-0"
                        >
                            <div
                                class="flex items-center justify-between text-[11px]"
                            >
                                <span
                                    class="inline-flex items-center gap-1 font-bold text-gray-700 dark:text-gray-300"
                                >
                                    {{ log.user_name }}
                                    <span
                                        class="cursor-pointer font-normal text-gray-400"
                                        title="User"
                                        >ℹ</span
                                    >
                                </span>
                                <span
                                    class="text-[10px] text-gray-400 italic"
                                    >{{
                                        propertyTime.dateTime(log.date, true)
                                    }}</span
                                >
                            </div>
                            <div
                                class="mt-1 rounded border border-gray-100 bg-gray-50 p-2 text-xs text-gray-700 italic dark:border-[#2b2b28] dark:bg-[#20201e] dark:text-gray-300"
                            >
                                {{ log.action }}
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- ================= BOTTOM STICKY ACTION BAR ================= -->
        <div
            class="fixed right-0 bottom-0 left-0 z-40 flex items-center justify-center gap-1.5 border-t border-stone-800 bg-[#352c28] px-4 py-2 shadow-xl"
        >
            <!-- EDIT Button -->
            <button
                type="button"
                class="cursor-pointer rounded bg-[#ea580c] px-3.5 py-1.5 text-xs font-bold text-white uppercase shadow-xs transition-colors hover:bg-[#c2410c]"
                @click="
                    router.visit(
                        admin.bookings.edit({
                            property: property.id,
                            booking: booking.id,
                        }).url,
                    )
                "
            >
                EDIT
            </button>

            <!-- CHECK IN Button: shown from the check-in date until the day before check-out -->
            <button
                v-if="canCheckIn"
                type="button"
                class="cursor-pointer rounded bg-[#ea580c] px-3.5 py-1.5 text-xs font-bold text-white uppercase shadow-xs transition-colors hover:bg-[#c2410c]"
                data-test="booking-check-in"
                @click="startCheckIn"
            >
                CHECK IN
            </button>

            <!-- UNDO CHECK IN Button: Only shown when the customer is already checked in but has not checked out -->
            <button
                v-if="booking.status === 'checked_in'"
                type="button"
                class="cursor-pointer rounded bg-[#ea580c] px-3.5 py-1.5 text-xs font-bold text-white uppercase shadow-xs transition-colors hover:bg-[#c2410c]"
                @click="changeStatus('confirmed')"
            >
                UNDO CHECK IN
            </button>

            <!-- CHECK OUT Button: Shown when customer is checked in -->
            <button
                v-if="booking.status === 'checked_in'"
                type="button"
                class="cursor-pointer rounded bg-[#ea580c] px-3.5 py-1.5 text-xs font-bold text-white uppercase shadow-xs transition-colors hover:bg-[#c2410c]"
                @click="startCheckout"
            >
                CHECK OUT
            </button>

            <!-- UNDO CHECK OUT Button: Shown when checked out -->
            <button
                v-if="booking.status === 'checked_out'"
                type="button"
                class="cursor-pointer rounded bg-[#ea580c] px-3.5 py-1.5 text-xs font-bold text-white uppercase shadow-xs transition-colors hover:bg-[#c2410c]"
                @click="changeStatus('confirmed')"
            >
                UNDO CHECK OUT
            </button>

            <!-- EMAIL Dropdown -->
            <div ref="emailDropdownRef" class="relative">
                <button
                    type="button"
                    class="inline-flex cursor-pointer items-center gap-1 rounded bg-[#ea580c] px-3.5 py-1.5 text-xs font-bold text-white uppercase shadow-xs transition-colors hover:bg-[#c2410c]"
                    @click="emailDropdownOpen = !emailDropdownOpen"
                >
                    EMAIL ▾
                </button>
                <div
                    v-if="emailDropdownOpen"
                    class="absolute bottom-full left-0 z-50 mb-1 w-44 rounded-md border border-gray-200 bg-white py-1 text-xs text-gray-700 shadow-lg dark:border-[#3E3E3A] dark:bg-[#161615] dark:text-gray-200"
                    @click="emailDropdownOpen = false"
                >
                    <button
                        type="button"
                        class="w-full px-3 py-1.5 text-left hover:bg-gray-100 dark:hover:bg-neutral-800"
                        @click="showAlert('Voucher emailed')"
                    >
                        Email Voucher
                    </button>
                    <button
                        type="button"
                        class="w-full px-3 py-1.5 text-left hover:bg-gray-100 dark:hover:bg-neutral-800"
                        @click="showAlert('Invoice emailed')"
                    >
                        Email Invoice
                    </button>
                </div>
            </div>

            <BookingPrintMenu
                :property-id="property.id"
                :booking-id="booking.id"
            />

            <!-- MORE Dropdown -->
            <div ref="moreDropdownRef" class="relative">
                <button
                    type="button"
                    class="inline-flex cursor-pointer items-center gap-1 rounded bg-[#ea580c] px-3.5 py-1.5 text-xs font-bold text-white uppercase shadow-xs transition-colors hover:bg-[#c2410c]"
                    @click="moreDropdownOpen = !moreDropdownOpen"
                >
                    MORE ▾
                </button>
                <div
                    v-if="moreDropdownOpen"
                    class="absolute right-0 bottom-full z-50 mb-1 w-44 rounded-md border border-gray-200 bg-white py-1 text-xs text-gray-700 shadow-lg dark:border-[#3E3E3A] dark:bg-[#161615] dark:text-gray-200"
                    @click="moreDropdownOpen = false"
                >
                    <button
                        type="button"
                        class="w-full px-3 py-1.5 text-left hover:bg-gray-100 dark:hover:bg-neutral-800"
                        @click="billingPanel?.openAddPayment()"
                    >
                        Add Payment
                    </button>
                    <button
                        v-if="cityLedgerAccounts.length"
                        type="button"
                        class="w-full px-3 py-1.5 text-left hover:bg-gray-100 dark:hover:bg-neutral-800"
                        @click="openCityLedger"
                    >
                        Bill to City Ledger
                    </button>
                    <button
                        type="button"
                        class="w-full px-3 py-1.5 text-left hover:bg-gray-100 dark:hover:bg-neutral-800"
                        @click="markNoStay"
                    >
                        Mark as No Stay
                    </button>
                    <button
                        type="button"
                        class="w-full px-3 py-1.5 text-left text-red-600 hover:bg-red-50 dark:hover:bg-red-950/20"
                        @click="changeStatus('cancelled')"
                    >
                        Cancel Booking
                    </button>
                </div>
            </div>
        </div>

        <Modal
            :show="upgradeRoom !== null"
            :title="`Upgrade room ${upgradeRoom?.room_number ?? ''}`"
            @close="upgradeRoom = null"
        >
            <form
                class="flex flex-col gap-3 text-sm"
                @submit.prevent="submitUpgrade"
            >
                <p
                    v-if="upgradeLoadError"
                    class="rounded bg-red-50 px-3 py-2 text-xs text-red-700"
                    role="alert"
                >
                    {{ upgradeLoadError }}
                </p>
                <p v-else-if="!upgradeOptions" class="text-xs text-[#706f6c]">
                    Finding free rooms…
                </p>
                <template v-else>
                    <p class="text-xs text-[#706f6c] dark:text-[#A1A09A]">
                        From {{ upgradeRoom?.category_name }} for
                        {{ upgradeOptions.nights }} night(s),
                        {{ upgradeOptions.from }} → {{ upgradeOptions.to }}.
                    </p>
                    <p
                        v-if="!upgradeOptions.options.length"
                        class="text-xs text-[#706f6c]"
                    >
                        No other room category is free for these nights.
                    </p>
                    <label
                        v-for="option in upgradeOptions.options"
                        :key="option.id"
                        class="flex cursor-pointer items-center justify-between gap-2 rounded border border-[#e3e3e0] px-3 py-2 dark:border-[#3E3E3A]"
                        :class="
                            upgradeForm.room_category_id === option.id
                                ? 'border-[#ea580c]'
                                : ''
                        "
                    >
                        <span class="flex items-center gap-2">
                            <input
                                type="radio"
                                name="upgrade-category"
                                :checked="
                                    upgradeForm.room_category_id === option.id
                                "
                                :data-test="`upgrade-to-${option.id}`"
                                @change="chooseUpgrade(option)"
                            />
                            <span class="font-medium dark:text-[#EDEDEC]">{{
                                option.name
                            }}</span>
                            <span class="text-xs text-[#706f6c]"
                                >{{ option.free_rooms.length }} free</span
                            >
                        </span>
                        <span class="text-xs text-[#706f6c]">{{
                            option.suggested_supplement > 0
                                ? `+${formatCurrency(option.suggested_supplement)}/night`
                                : 'same rate'
                        }}</span>
                    </label>
                    <template v-if="chosenUpgrade">
                        <label
                            class="flex flex-col gap-1 text-xs text-[#706f6c] dark:text-[#A1A09A]"
                        >
                            Room
                            <select
                                v-model="upgradeForm.room_id"
                                class="rounded-md border border-[#e3e3e0] bg-white px-2 py-1.5 text-sm dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]"
                            >
                                <option :value="null">
                                    Assign automatically
                                </option>
                                <option
                                    v-for="free in chosenUpgrade.free_rooms"
                                    :key="free.id"
                                    :value="free.id"
                                >
                                    {{ free.name }}
                                </option>
                            </select>
                        </label>
                        <div class="flex gap-4">
                            <label
                                class="flex items-center gap-2 dark:text-[#EDEDEC]"
                                ><input
                                    v-model="upgradeForm.type"
                                    type="radio"
                                    value="complimentary"
                                />
                                Complimentary (rate stays)</label
                            >
                            <label
                                class="flex items-center gap-2 dark:text-[#EDEDEC]"
                                ><input
                                    v-model="upgradeForm.type"
                                    type="radio"
                                    value="paid"
                                    data-test="upgrade-paid"
                                />
                                Paid</label
                            >
                        </div>
                        <label
                            v-if="upgradeForm.type === 'paid'"
                            class="flex flex-col gap-1 text-xs text-[#706f6c] dark:text-[#A1A09A]"
                        >
                            Supplement per night ({{ code }})
                            <input
                                v-model.number="
                                    upgradeForm.supplement_per_night
                                "
                                type="number"
                                min="0"
                                step="0.01"
                                class="rounded-md border border-[#e3e3e0] bg-white px-2 py-1.5 text-sm dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]"
                                data-test="upgrade-supplement"
                            />
                            <span
                                >Adds
                                {{
                                    formatCurrency(
                                        (upgradeForm.supplement_per_night ||
                                            0) * upgradeOptions.nights,
                                    )
                                }}
                                plus tax to the bill.</span
                            >
                        </label>
                        <label
                            class="flex flex-col gap-1 text-xs text-[#706f6c] dark:text-[#A1A09A]"
                        >
                            Reason
                            <input
                                v-model="upgradeForm.reason"
                                type="text"
                                required
                                maxlength="255"
                                placeholder="e.g. Anniversary, overbooked category, guest request"
                                class="rounded-md border border-[#e3e3e0] bg-white px-2 py-1.5 text-sm dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]"
                                data-test="upgrade-reason"
                            />
                        </label>
                    </template>
                    <p
                        v-for="(message, field) in upgradeForm.errors"
                        :key="field"
                        class="rounded bg-red-50 px-3 py-2 text-xs text-red-700"
                        role="alert"
                    >
                        {{ message }}
                    </p>
                </template>
                <div
                    class="flex justify-end gap-2 border-t border-[#e3e3e0] pt-3 dark:border-[#3E3E3A]"
                >
                    <button
                        type="button"
                        class="rounded-md border border-[#e3e3e0] px-3 py-1.5 text-sm dark:border-[#3E3E3A] dark:text-[#EDEDEC]"
                        @click="upgradeRoom = null"
                    >
                        Cancel
                    </button>
                    <button
                        type="submit"
                        :disabled="
                            !chosenUpgrade ||
                            !upgradeForm.reason ||
                            upgradeForm.processing
                        "
                        class="rounded-md bg-[#ea580c] px-3 py-1.5 text-sm font-semibold text-white hover:bg-[#c2410c] disabled:opacity-50"
                        data-test="upgrade-submit"
                    >
                        Upgrade
                    </button>
                </div>
            </form>
        </Modal>

        <Modal
            :show="cityLedgerOpen"
            title="Bill to City Ledger"
            @close="cityLedgerOpen = false"
        >
            <form
                class="flex flex-col gap-3 text-sm"
                @submit.prevent="submitCityLedger"
            >
                <p class="text-xs text-[#706f6c] dark:text-[#A1A09A]">
                    The amount moves off the guest's bill to the company or
                    agency account, which settles it on credit.
                </p>
                <label
                    class="flex flex-col gap-1 text-xs text-[#706f6c] dark:text-[#A1A09A]"
                >
                    Account
                    <select
                        v-model="cityLedgerForm.account_id"
                        required
                        class="rounded-md border border-[#e3e3e0] bg-white px-2 py-1.5 text-sm text-[#1b1b18] dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]"
                    >
                        <option
                            v-for="account in cityLedgerAccounts"
                            :key="account.id"
                            :value="account.id"
                        >
                            {{ account.name
                            }}{{ account.code ? ` (${account.code})` : '' }}
                        </option>
                    </select>
                </label>
                <label
                    class="flex flex-col gap-1 text-xs text-[#706f6c] dark:text-[#A1A09A]"
                >
                    Amount ({{ code }})
                    <input
                        v-model="cityLedgerForm.amount"
                        type="number"
                        min="0.01"
                        step="0.01"
                        placeholder="The whole balance"
                        class="rounded-md border border-[#e3e3e0] bg-white px-2 py-1.5 text-sm text-[#1b1b18] dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]"
                    />
                </label>
                <p
                    v-for="(message, field) in cityLedgerErrors"
                    :key="field"
                    class="rounded bg-red-50 px-3 py-2 text-xs text-red-700 dark:bg-red-950 dark:text-red-300"
                    role="alert"
                >
                    {{ message }}
                </p>
                <div
                    class="flex justify-end gap-2 border-t border-[#e3e3e0] pt-3 dark:border-[#3E3E3A]"
                >
                    <button
                        type="button"
                        class="rounded-md border border-[#e3e3e0] px-3 py-1.5 text-sm dark:border-[#3E3E3A] dark:text-[#EDEDEC]"
                        @click="cityLedgerOpen = false"
                    >
                        Cancel
                    </button>
                    <button
                        type="submit"
                        :disabled="cityLedgerProcessing"
                        class="rounded-md bg-[#ea580c] px-3 py-1.5 text-sm font-semibold text-white hover:bg-[#c2410c] disabled:opacity-50"
                    >
                        {{
                            cityLedgerProcessing
                                ? 'Billing…'
                                : 'Bill to account'
                        }}
                    </button>
                </div>
            </form>
        </Modal>

        <Modal
            :show="checkinChoiceOpen"
            title="Check in"
            @close="checkinChoiceOpen = false"
        >
            <form
                class="flex flex-col gap-3 text-sm"
                @submit.prevent="confirmCheckinChoice"
            >
                <label
                    class="flex flex-col gap-1 text-xs text-[#706f6c] dark:text-[#A1A09A]"
                >
                    Check-in date &amp; time
                    <input
                        v-model="stayTime"
                        data-test="check-in-time"
                        type="datetime-local"
                        required
                        :max="propertyTime.toInput()"
                        class="rounded-md border border-[#e3e3e0] bg-white px-2 py-1.5 text-sm text-[#1b1b18] dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]"
                    />
                </label>
                <template v-if="arrivingRooms.length > 1">
                    <p class="text-[#706f6c] dark:text-[#A1A09A]">
                        This booking has {{ arrivingRooms.length }} rooms
                        arriving. Which rooms are checking in?
                    </p>
                    <label class="flex items-start gap-2">
                        <input
                            v-model="checkinScope"
                            type="radio"
                            value="all"
                            class="mt-0.5"
                        />
                        <span>
                            <span
                                class="font-semibold text-[#1b1b18] dark:text-[#EDEDEC]"
                                >All rooms</span
                            >
                            <span
                                class="block text-xs text-[#706f6c] dark:text-[#A1A09A]"
                                >Check in the whole booking.</span
                            >
                        </span>
                    </label>
                    <label class="flex items-start gap-2">
                        <input
                            v-model="checkinScope"
                            type="radio"
                            value="selected"
                            class="mt-0.5"
                        />
                        <span
                            class="font-semibold text-[#1b1b18] dark:text-[#EDEDEC]"
                            >Only selected rooms</span
                        >
                    </label>
                    <div
                        class="ml-6 flex flex-col gap-1.5 rounded border border-[#e3e3e0] p-2 dark:border-[#3E3E3A]"
                        :class="checkinScope !== 'selected' ? 'opacity-50' : ''"
                    >
                        <label
                            v-for="room in arrivingRooms"
                            :key="room.sub_booking_id"
                            class="flex items-center gap-2"
                        >
                            <input
                                v-model="checkinRoomIds"
                                type="checkbox"
                                :value="room.sub_booking_id"
                                :disabled="checkinScope !== 'selected'"
                                class="h-4 w-4 rounded"
                            />
                            <span class="text-[#1b1b18] dark:text-[#EDEDEC]">{{
                                roomLabel(room)
                            }}</span>
                        </label>
                    </div>
                    <p
                        v-if="
                            checkinScope === 'selected' &&
                            checkinRoomIds.length === 0
                        "
                        class="text-xs text-[#f53003]"
                    >
                        Select at least one room.
                    </p>
                </template>
                <StayTimeChargeNotice
                    v-if="checkinChoiceOpen"
                    v-model="waiveStayCharge"
                    :property-id="property.id"
                    :booking-id="booking.id"
                    kind="early_check_in"
                    :reported-at="stayReportedAt"
                    :sub-booking-ids="
                        checkinScope === 'all'
                            ? arrivingRooms.map((room) => room.sub_booking_id)
                            : checkinRoomIds
                    "
                />
                <!-- Housekeeping: rooms that aren't ready for guests. -->
                <p
                    v-if="housekeepingBlocks.hard.length"
                    class="rounded bg-red-50 px-3 py-2 text-xs text-red-700 dark:bg-red-950 dark:text-red-300"
                    role="alert"
                >
                    {{
                        housekeepingBlocks.hard
                            .map(
                                (room) =>
                                    `${room.room_number} is ${room.housekeeping?.name}`,
                            )
                            .join(', ')
                    }}
                    — it can't be checked in until housekeeping marks it ready.
                </p>
                <label
                    v-if="housekeepingBlocks.soft.length"
                    class="flex items-start gap-2 rounded bg-amber-50 px-3 py-2 text-xs text-amber-800 dark:bg-amber-950 dark:text-amber-300"
                >
                    <input
                        v-model="checkInAnyway"
                        type="checkbox"
                        class="mt-0.5 h-4 w-4 rounded"
                    />
                    <span>
                        {{
                            housekeepingBlocks.soft
                                .map(
                                    (room) =>
                                        `${room.room_number} is ${room.housekeeping?.name}`,
                                )
                                .join(', ')
                        }}.
                        <span class="font-semibold">Check in anyway</span>
                    </span>
                </label>
                <p
                    v-if="stayError"
                    class="rounded bg-red-50 px-3 py-2 text-xs text-red-700 dark:bg-red-950 dark:text-red-300"
                    role="alert"
                >
                    {{ stayError }}
                </p>
                <div
                    class="flex justify-end gap-2 border-t border-[#e3e3e0] pt-3 dark:border-[#3E3E3A]"
                >
                    <button
                        type="button"
                        class="rounded-md border border-[#e3e3e0] px-3 py-1.5 text-sm dark:border-[#3E3E3A] dark:text-[#EDEDEC]"
                        @click="checkinChoiceOpen = false"
                    >
                        Cancel
                    </button>
                    <button
                        type="submit"
                        :disabled="
                            stayProcessing ||
                            checkinBlocked ||
                            (checkinScope === 'selected' &&
                                checkinRoomIds.length === 0)
                        "
                        data-test="check-in-submit"
                        class="rounded-md bg-[#ea580c] px-3 py-1.5 text-sm font-semibold text-white hover:bg-[#c2410c] disabled:opacity-50"
                    >
                        {{ stayProcessing ? 'Checking in…' : 'Check In' }}
                    </button>
                </div>
            </form>
        </Modal>

        <Modal
            :show="checkoutChoiceOpen"
            title="Check out"
            @close="checkoutChoiceOpen = false"
        >
            <form
                class="flex flex-col gap-3 text-sm"
                @submit.prevent="confirmCheckoutChoice"
            >
                <label
                    class="flex flex-col gap-1 text-xs text-[#706f6c] dark:text-[#A1A09A]"
                >
                    Check-out date &amp; time
                    <input
                        v-model="stayTime"
                        type="datetime-local"
                        required
                        :max="propertyTime.toInput()"
                        class="rounded-md border border-[#e3e3e0] bg-white px-2 py-1.5 text-sm text-[#1b1b18] dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]"
                    />
                </label>
                <template v-if="inHouseRooms.length > 1">
                    <p class="text-[#706f6c] dark:text-[#A1A09A]">
                        This booking has {{ inHouseRooms.length }} rooms in
                        house. Which rooms are checking out?
                    </p>
                    <label class="flex items-start gap-2">
                        <input
                            v-model="checkoutScope"
                            type="radio"
                            value="all"
                            class="mt-0.5"
                        />
                        <span>
                            <span
                                class="font-semibold text-[#1b1b18] dark:text-[#EDEDEC]"
                                >All rooms</span
                            >
                            <span
                                class="block text-xs text-[#706f6c] dark:text-[#A1A09A]"
                                >Check out the whole booking.</span
                            >
                        </span>
                    </label>
                    <label class="flex items-start gap-2">
                        <input
                            v-model="checkoutScope"
                            type="radio"
                            value="selected"
                            class="mt-0.5"
                        />
                        <span>
                            <span
                                class="font-semibold text-[#1b1b18] dark:text-[#EDEDEC]"
                                >Only selected rooms</span
                            >
                            <span
                                class="block text-xs text-[#706f6c] dark:text-[#A1A09A]"
                                >The booking is split into its rooms; the others
                                stay checked in.</span
                            >
                        </span>
                    </label>
                    <div
                        class="ml-6 flex flex-col gap-1.5 rounded border border-[#e3e3e0] p-2 dark:border-[#3E3E3A]"
                        :class="
                            checkoutScope !== 'selected' ? 'opacity-50' : ''
                        "
                    >
                        <label
                            v-for="room in inHouseRooms"
                            :key="room.sub_booking_id"
                            class="flex items-center gap-2"
                        >
                            <input
                                v-model="checkoutRoomIds"
                                type="checkbox"
                                :value="room.sub_booking_id"
                                :disabled="checkoutScope !== 'selected'"
                                class="h-4 w-4 rounded"
                            />
                            <span class="text-[#1b1b18] dark:text-[#EDEDEC]">{{
                                roomLabel(room)
                            }}</span>
                        </label>
                    </div>
                    <p
                        v-if="
                            checkoutScope === 'selected' &&
                            checkoutRoomIds.length === 0
                        "
                        class="text-xs text-[#f53003]"
                    >
                        Select at least one room.
                    </p>
                </template>
                <StayTimeChargeNotice
                    v-if="checkoutChoiceOpen"
                    v-model="waiveStayCharge"
                    :property-id="property.id"
                    :booking-id="booking.id"
                    kind="late_check_out"
                    :reported-at="stayReportedAt"
                    :sub-booking-ids="
                        checkoutScope === 'all'
                            ? inHouseRooms.map((room) => room.sub_booking_id)
                            : checkoutRoomIds
                    "
                />
                <p
                    v-if="booking.outstanding > 0"
                    class="rounded px-3 py-2 text-xs"
                    :class="
                        booking.pay_at_hotel
                            ? 'bg-red-50 text-red-700 dark:bg-red-950 dark:text-red-300'
                            : 'bg-amber-50 text-amber-800 dark:bg-amber-950 dark:text-amber-300'
                    "
                >
                    <template v-if="booking.pay_at_hotel"
                        >This booking is payable at hotel: settle the {{ code }}
                        {{ formatCurrency(booking.outstanding) }} due before
                        checking out.</template
                    >
                    <template v-else
                        >{{ code }} {{ formatCurrency(booking.outstanding) }} is
                        still outstanding and will stay due after
                        check-out.</template
                    >
                </p>
                <fieldset
                    v-if="housekeepingStatuses.length"
                    class="rounded border border-[#e3e3e0] px-3 py-2 dark:border-[#3E3E3A]"
                >
                    <legend
                        class="px-1 text-xs font-semibold text-[#1b1b18] dark:text-[#EDEDEC]"
                    >
                        Mark room as
                    </legend>
                    <div class="flex flex-wrap gap-x-4 gap-y-1.5">
                        <label
                            v-for="status in housekeepingStatuses"
                            :key="status.name"
                            class="flex items-center gap-1.5"
                        >
                            <input
                                v-model="checkoutHousekeeping"
                                type="radio"
                                name="checkout-housekeeping"
                                :value="status.name"
                            />
                            <span
                                class="inline-block h-3 w-3 rounded-sm border border-black/10"
                                :style="{ backgroundColor: status.color }"
                            ></span>
                            <span class="text-[#1b1b18] dark:text-[#EDEDEC]">{{
                                status.name
                            }}</span>
                            <span
                                v-if="
                                    status.name === housekeepingCheckOutDefault
                                "
                                class="text-[10px] text-[#706f6c] dark:text-[#A1A09A]"
                                >(default)</span
                            >
                        </label>
                    </div>
                </fieldset>
                <label
                    class="flex items-start gap-2 rounded border border-[#e3e3e0] px-3 py-2 dark:border-[#3E3E3A]"
                    :class="guestEmail ? '' : 'opacity-60'"
                >
                    <input
                        v-model="sendInvoice"
                        type="checkbox"
                        :disabled="!guestEmail"
                        class="mt-0.5 h-4 w-4 rounded"
                    />
                    <span>
                        <span
                            class="font-semibold text-[#1b1b18] dark:text-[#EDEDEC]"
                            >Send invoice to guest</span
                        >
                        <span
                            v-if="guestEmail"
                            class="block text-xs text-[#706f6c] dark:text-[#A1A09A]"
                            >E-mails the invoice PDF to {{ guestEmail }}.</span
                        >
                        <span
                            v-else
                            class="block text-xs text-[#706f6c] dark:text-[#A1A09A]"
                            >The guest has no e-mail address.</span
                        >
                    </span>
                </label>
                <p
                    v-if="stayError"
                    class="rounded bg-red-50 px-3 py-2 text-xs text-red-700 dark:bg-red-950 dark:text-red-300"
                    role="alert"
                >
                    {{ stayError }}
                </p>
                <div
                    class="flex justify-end gap-2 border-t border-[#e3e3e0] pt-3 dark:border-[#3E3E3A]"
                >
                    <button
                        type="button"
                        class="rounded-md border border-[#e3e3e0] px-3 py-1.5 text-sm dark:border-[#3E3E3A] dark:text-[#EDEDEC]"
                        @click="checkoutChoiceOpen = false"
                    >
                        Cancel
                    </button>
                    <button
                        type="submit"
                        :disabled="
                            stayProcessing ||
                            (checkoutScope === 'selected' &&
                                checkoutRoomIds.length === 0)
                        "
                        class="rounded-md bg-[#ea580c] px-3 py-1.5 text-sm font-semibold text-white hover:bg-[#c2410c] disabled:opacity-50"
                    >
                        {{ stayProcessing ? 'Checking out…' : 'Check Out' }}
                    </button>
                </div>
            </form>
        </Modal>

        <DocumentViewerModal
            :document="viewedDocument"
            @close="viewedDocument = null"
        />
    </div>
</template>
