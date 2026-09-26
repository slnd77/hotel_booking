<script setup lang="ts">
import BookingEngineController from '@/actions/App/Http/Controllers/BookingEngineController';
import BookingEngineDateRangePicker from '@/components/BookingEngineDateRangePicker.vue';
import Modal from '@/components/Modal.vue';
import RoomDetailsModal from '@/components/RoomDetailsModal.vue';
import RoomGuestSelector, {
    type RoomRequirement,
} from '@/components/RoomGuestSelector.vue';
import { Head, router } from '@inertiajs/vue3';
import { computed, reactive, ref, watch } from 'vue';

export type MealPlanOption = {
    plan: string;
    label: string;
    rack_rate: number;
    nightly_price: number;
    /** Set when the option is a package (Rate Settings → Package) sold with this meal plan. */
    package_id?: number | null;
    package_name?: string | null;
    inclusions?: string | null;
};

type PackageOption = {
    package_id: number;
    name: string;
    color: string | null;
    inclusions: string | null;
    terms: string | null;
    plan: string;
    label: string;
    rack_rate: number;
    nightly_price: number;
};

type RoomCategoryOption = {
    id: number;
    name: string;
    description: string | null;
    inclusion_details: string | null;
    max_adults: number;
    max_children: number;
    ideal_adults: number;
    ideal_children: number;
    available: number;
    /** No rate for these dates (and zero-rate bookings are off): shown, but not for sale. */
    no_rate?: boolean;
    images: { id: number; url: string }[];
    bed_types: { name: string; quantity: number }[];
    amenities: string[];
    categorized_amenities?: { category: string; amenities: string[] }[];
    meal_plan: string | null;
    meal_plan_label: string | null;
    nightly_price: number;
    meal_plan_options?: MealPlanOption[];
    package_options?: PackageOption[];
    /** Why these dates can't be booked in it (Rate Settings → Restrictions), if so. */
    restriction?: string | null;
};

export type SelectedRoomChoice = {
    room: RoomCategoryOption;
    mealPlan: MealPlanOption;
};

export type PromoCode = {
    id: number;
    deal_type?: string;
    code: string;
    tag_line?: string;
    description?: string;
    discount_type: 'percentage' | 'fixed';
    discount_value: number;
    channels?: string[];
    min_length_of_stay?: number | null;
    length_of_stay?: { enabled: boolean; value: number | null };
    cut_off_days?: { enabled: boolean; value: number | null };
    last_minute?: { enabled: boolean; value: number | null };
    checkin_date_range?: {
        enabled: boolean;
        start: string | null;
        end: string | null;
    };
    checkout_date_range?: {
        enabled: boolean;
        start: string | null;
        end: string | null;
    };
    booking_creation_date_range?: {
        enabled: boolean;
        start: string | null;
        end: string | null;
    };
    stay_days_of_week?: { enabled: boolean; days: string[] };
    exclusion_date_range?: {
        enabled: boolean;
        start: string | null;
        end: string | null;
    };
    booking_amount_range?: {
        enabled: boolean;
        min: number | null;
        max: number | null;
    };
    max_discount_per_booking?: { enabled: boolean; value: number | null };
    applicable_on_pay_at_hotel?: boolean;
    terms_and_conditions?: string;
    status: boolean;
    deleted: boolean;
};

export type DealsPromotion = {
    auto_apply_public_promo: boolean;
    public_promo_codes: PromoCode[];
};

const props = defineProps<{
    property: {
        ulid: string;
        name: string;
        contact_number: string | null;
        currency: string;
        address: string;
        branding: {
            show_property_name: boolean;
            theme_color: string;
            header_color: string;
            text_color: string;
            logo_url: string | null;
            banner_image_url: string | null;
            phone_numbers: string[];
            support_emails: string[];
            website_url: string;
        };
    };
    search: {
        check_in: string;
        check_out: string;
        nights: number;
        rooms: RoomRequirement[];
    };
    roomCategories: RoomCategoryOption[];
    cancellationPolicy: string[];
    cancellationRuleSummary?: Array<{
        window: string;
        penalty: string;
        refund: string;
        description: string;
    }>;
    termsAndConditions?: string | null;
    /** Settings → Privacy & Terms: the property's public pages, and whether marketing consent is asked. */
    legal?: {
        privacy_url: string;
        terms_url: string;
        marketing_opt_in: boolean;
    };
    taxComponents?: Array<{ name: string; percentage?: number; rate?: number }>;
    dealsPromotion?: DealsPromotion;
    /** Add-on services the guest can add (Property → Add-ons, offered on the booking engine). */
    addons?: {
        id: number;
        name: string;
        description: string | null;
        pricing: string;
        pricing_label: string;
        price: number;
        tax_rate: number;
    }[];
    bookingEngineSettings?: {
        allow_partial_payment?: boolean;
        partial_payment_percentage?: number;
        allowed_payment_gateways?: {
            id: string;
            name: string;
            description?: string;
            enabled: boolean;
        }[];
        accepted_card_types?: { id: number; name: string; enabled: boolean }[];
    };
    testGatewayEnabled?: boolean;
}>();

const detailsModalRoom = ref<RoomCategoryOption | null>(null);
const detailsModalTab = ref<
    | 'images'
    | 'room_details'
    | 'inclusions'
    | 'cancellation_policy'
    | 'terms'
    | 'amenities'
    | 'bed_types'
>('images');
const detailsModalImageIndex = ref<number>(0);
const activeImageIndexByRoom = ref<Record<number, number>>({});

function getActiveRoomImageIndex(roomId: number, imagesCount: number): number {
    const idx = activeImageIndexByRoom.value[roomId] ?? 0;
    if (idx >= imagesCount) return 0;
    return idx;
}

function prevRoomImage(roomId: number, imagesCount: number): void {
    if (imagesCount <= 1) return;
    const current = getActiveRoomImageIndex(roomId, imagesCount);
    activeImageIndexByRoom.value[roomId] =
        (current - 1 + imagesCount) % imagesCount;
}

function nextRoomImage(roomId: number, imagesCount: number): void {
    if (imagesCount <= 1) return;
    const current = getActiveRoomImageIndex(roomId, imagesCount);
    activeImageIndexByRoom.value[roomId] = (current + 1) % imagesCount;
}

function setRoomImage(roomId: number, index: number): void {
    activeImageIndexByRoom.value[roomId] = index;
}

function openRoomDetails(
    room: RoomCategoryOption,
    tab:
        | 'images'
        | 'room_details'
        | 'inclusions'
        | 'cancellation_policy'
        | 'terms'
        | 'amenities'
        | 'bed_types' = 'images',
    imageIndex?: number,
): void {
    detailsModalRoom.value = room;
    detailsModalTab.value = tab;
    detailsModalImageIndex.value =
        imageIndex ?? activeImageIndexByRoom.value[room.id] ?? 0;
}

const searchForm = reactive({
    check_in: props.search.check_in,
    check_out: props.search.check_out,
    rooms: props.search.rooms.map((room) => ({ ...room })),
});

const totalSearchAdults = computed(() =>
    searchForm.rooms.reduce((sum, room) => sum + room.adults, 0),
);

const showResetConfirmModal = ref(false);
const isFetchingRooms = ref(false);
const pendingNewRooms = ref<RoomRequirement[] | null>(null);

function areRoomsEqual(a: RoomRequirement[], b: RoomRequirement[]): boolean {
    if (a.length !== b.length) return false;
    for (let i = 0; i < a.length; i++) {
        if (
            a[i].adults !== b[i].adults ||
            a[i].children !== b[i].children ||
            a[i].infants !== b[i].infants
        ) {
            return false;
        }
    }
    return true;
}

function hasSearchCriteriaChanged(): boolean {
    if (searchForm.check_in !== props.search.check_in) return true;
    if (searchForm.check_out !== props.search.check_out) return true;
    if (searchForm.rooms.length !== props.search.rooms.length) return true;

    for (let i = 0; i < searchForm.rooms.length; i++) {
        const cur = searchForm.rooms[i];
        const init = props.search.rooms[i];
        if (!init) return true;
        if (
            cur.adults !== init.adults ||
            cur.children !== init.children ||
            cur.infants !== init.infants
        ) {
            return true;
        }
    }

    return false;
}

function handleRoomGuestApply(newRooms: RoomRequirement[]): void {
    const fieldsChanged = !areRoomsEqual(newRooms, searchForm.rooms);
    const hasCartItems = selectedRoomsList.value.length > 0;

    if (fieldsChanged && hasCartItems) {
        pendingNewRooms.value = newRooms.map((r) => ({ ...r }));
        showResetConfirmModal.value = true;
        return;
    }

    searchForm.rooms = newRooms.map((r) => ({ ...r }));
    executeSearch();
}

function handleSearchOrApply(): void {
    const hasCartItems = selectedRoomsList.value.length > 0;
    if (hasCartItems && hasSearchCriteriaChanged()) {
        showResetConfirmModal.value = true;
        return;
    }

    executeSearch();
}

function confirmResetAndSearch(): void {
    showResetConfirmModal.value = false;
    if (pendingNewRooms.value) {
        searchForm.rooms = pendingNewRooms.value.map((r) => ({ ...r }));
        pendingNewRooms.value = null;
    }
    roomSelections.value = {};
    activeRoomIndex.value = 0;
    appliedPromo.value = null;
    executeSearch();
}

function cancelResetConfirm(): void {
    showResetConfirmModal.value = false;
    pendingNewRooms.value = null;
    searchForm.check_in = props.search.check_in;
    searchForm.check_out = props.search.check_out;
    searchForm.rooms = props.search.rooms.map((r) => ({ ...r }));
}

function executeSearch(): void {
    isFetchingRooms.value = true;
    router.get(
        window.location.pathname,
        {
            check_in: searchForm.check_in,
            check_out: searchForm.check_out,
            rooms: JSON.stringify(searchForm.rooms),
        },
        {
            preserveScroll: true,
            onSuccess: () => {
                showBadgeNotification('Available rooms list updated');
            },
            onFinish: () => {
                isFetchingRooms.value = false;
            },
        },
    );
}

const calendarUrl = computed(
    () => BookingEngineController.calendar(props.property.ulid).url,
);

const step = ref<'select-room' | 'guest-details' | 'confirmed'>('select-room');
const activeRoomIndex = ref(0);
const roomSelections = ref<Record<number, SelectedRoomChoice | null>>({});

watch(
    () => props.search.rooms,
    (newRooms) => {
        searchForm.rooms = newRooms.map((r) => ({ ...r }));
        Object.keys(roomSelections.value).forEach((key) => {
            if (Number(key) >= newRooms.length) {
                delete roomSelections.value[Number(key)];
            }
        });
        if (activeRoomIndex.value >= newRooms.length) {
            activeRoomIndex.value = 0;
        }
    },
    { deep: true },
);

watch(
    () => [props.search.check_in, props.search.check_out],
    ([newIn, newOut]) => {
        searchForm.check_in = newIn;
        searchForm.check_out = newOut;
    },
);

function getMealPlanOptions(room: RoomCategoryOption): MealPlanOption[] {
    // Nothing priced for these dates: no option to add.
    if (room.no_rate) {
        return [];
    }

    const base: MealPlanOption[] =
        room.meal_plan_options && room.meal_plan_options.length
            ? room.meal_plan_options
            : [
                  {
                      plan: room.meal_plan || 'EP',
                      label: room.meal_plan_label || 'Room Only',
                      rack_rate: room.nightly_price,
                      nightly_price: room.nightly_price,
                  },
              ];

    // Packages are offered alongside the plain meal plans.
    const packages: MealPlanOption[] = (room.package_options ?? []).map(
        (option) => ({
            plan: option.plan,
            label: `${option.name} (${option.label})`,
            rack_rate: option.rack_rate,
            nightly_price: option.nightly_price,
            package_id: option.package_id,
            package_name: option.name,
            inclusions: option.inclusions,
        }),
    );

    return [...base, ...packages];
}

/** One key per option: a meal plan, or a package with its meal plan. */
function optionKey(option: MealPlanOption): string {
    return option.package_id
        ? `package:${option.package_id}:${option.plan}`
        : option.plan;
}

/** Rooms these dates rule out (minimum stay, closed to arrival…), with the reason. */
const restrictedRoomCategories = computed(() =>
    props.roomCategories.filter((room) => room.restriction),
);

function remainingAvailable(category: RoomCategoryOption): number {
    let takenByOtherRooms = 0;
    for (let i = 0; i < searchForm.rooms.length; i++) {
        if (
            i !== activeRoomIndex.value &&
            roomSelections.value[i]?.room.id === category.id
        ) {
            takenByOtherRooms++;
        }
    }
    return Math.max(0, category.available - takenByOtherRooms);
}

const visibleRoomCategories = computed<RoomCategoryOption[]>(() => {
    return props.roomCategories.filter((room) => remainingAvailable(room) > 0);
});

function isSelectedForCurrentRoom(
    categoryId: number,
    planKey?: string,
): boolean {
    const current = roomSelections.value[activeRoomIndex.value];
    if (!current || current.room.id !== categoryId) {
        return false;
    }
    if (planKey) {
        return optionKey(current.mealPlan) === planKey;
    }
    return true;
}

/** Green for something that went through, red for a validation or payment failure. */
type ToastVariant = 'success' | 'error';

const toastNotification = ref<{
    visible: boolean;
    title: string;
    message: string;
    variant: ToastVariant;
    timeoutId: number | null;
}>({
    visible: false,
    title: 'Room added to cart',
    message: '',
    variant: 'success',
    timeoutId: null,
});

function showBadgeNotification(
    title: string,
    message: string = '',
    variant: ToastVariant = 'success',
): void {
    if (toastNotification.value.timeoutId) {
        clearTimeout(toastNotification.value.timeoutId);
    }
    toastNotification.value = {
        visible: true,
        title,
        message,
        variant,
        timeoutId: window.setTimeout(() => {
            toastNotification.value.visible = false;
        }, 3500),
    };
}

function dismissNotification(): void {
    if (toastNotification.value.timeoutId) {
        clearTimeout(toastNotification.value.timeoutId);
    }
    toastNotification.value = {
        visible: false,
        title: '',
        message: '',
        variant: 'success',
        timeoutId: null,
    };
}

function selectRoomOption(
    room: RoomCategoryOption,
    mealPlan: MealPlanOption,
): void {
    const currentIdx = activeRoomIndex.value;
    roomSelections.value[currentIdx] = { room, mealPlan };
    showBadgeNotification(
        'Room added to cart',
        `Room ${currentIdx + 1}: ${room.name} (${mealPlan.label})`,
    );

    const nextUnselected = searchForm.rooms.findIndex(
        (_, index) => !roomSelections.value[index],
    );
    if (nextUnselected !== -1) {
        activeRoomIndex.value = nextUnselected;
    } else if (activeRoomIndex.value < searchForm.rooms.length - 1) {
        activeRoomIndex.value++;
    }
}

function removeRoomSelection(index: number): void {
    delete roomSelections.value[index];
}

const selectedRoomsList = computed(() => {
    return Object.values(roomSelections.value).filter(
        (r): r is SelectedRoomChoice => r !== null && r !== undefined,
    );
});

const selectedRoomsWithIndex = computed(() => {
    const list: { index: number; choice: SelectedRoomChoice }[] = [];
    for (let i = 0; i < searchForm.rooms.length; i++) {
        const choice = roomSelections.value[i];
        if (choice) {
            list.push({ index: i, choice });
        }
    }
    return list;
});

const totalRooms = computed(() => selectedRoomsWithIndex.value.length);

const totalGuests = computed(() => {
    if (selectedRoomsWithIndex.value.length === 0) {
        return totalSearchAdults.value;
    }
    return selectedRoomsWithIndex.value.reduce((sum, item) => {
        const req = searchForm.rooms[item.index];
        return sum + (req ? req.adults + req.children : 1);
    }, 0);
});

const estimatedTotal = computed(() => {
    return totalPayable.value;
});

function formatCurrency(value: number): string {
    try {
        return new Intl.NumberFormat(undefined, {
            style: 'currency',
            currency: props.property.currency,
            maximumFractionDigits: 2,
        }).format(value);
    } catch {
        return `${props.property.currency} ${value}`;
    }
}

function formatDate(value: string): string {
    return new Date(`${value}T00:00:00`).toLocaleDateString(undefined, {
        day: '2-digit',
        month: 'short',
        year: 'numeric',
    });
}

function formatDateWithOrdinal(value?: string): string {
    if (!value) return '';
    const d = new Date(`${value}T00:00:00`);
    if (isNaN(d.getTime())) return value;
    const day = d.getDate();
    const j = day % 10,
        k = day % 100;
    let suffix = 'th';
    if (j === 1 && k !== 11) suffix = 'st';
    else if (j === 2 && k !== 12) suffix = 'nd';
    else if (j === 3 && k !== 13) suffix = 'rd';
    const month = d.toLocaleDateString('en-GB', { month: 'short' });
    const year = d.getFullYear();
    return `${day}${suffix} ${month} ${year}`;
}

const discountOpen = ref(true);
const taxesOpen = ref(false);
const showMoreDetails = ref(false);
const showTermsModal = ref(false);
const termsModalTab = ref<'terms' | 'cancellation'>('terms');

function openTermsModal(tab: 'terms' | 'cancellation' = 'terms'): void {
    termsModalTab.value = tab;
    showTermsModal.value = true;
}

// Consent must be given, not assumed: both boxes start unticked.
const agreeTerms = ref(false);
const agreeMarketing = ref(false);

const allowPartialPayment = computed(() => {
    return props.bookingEngineSettings?.allow_partial_payment ?? false;
});

const partialPaymentPercentage = computed(() => {
    const val = Number(props.bookingEngineSettings?.partial_payment_percentage);
    return !isNaN(val) && val > 0 && val < 100 ? val : 50;
});

const activePaymentGateways = computed(() => {
    return (props.bookingEngineSettings?.allowed_payment_gateways ?? []).filter(
        (g) => g.enabled,
    );
});

const partialPayment = ref(allowPartialPayment.value);

watch(
    allowPartialPayment,
    (allowed) => {
        if (!allowed) {
            partialPayment.value = false;
        } else {
            partialPayment.value = true;
        }
    },
    { immediate: true },
);

const enterGstDetails = ref(false);

const gstForm = reactive({
    gst_number: '',
    company_name: '',
});

function getRoomRackNightly(choice: SelectedRoomChoice): number {
    if (choice.mealPlan.rack_rate && choice.mealPlan.rack_rate > 0) {
        return choice.mealPlan.rack_rate;
    }
    return Math.round((choice.mealPlan.nightly_price / 1.18) * 100) / 100;
}

function getRoomOriginalTotal(choice: SelectedRoomChoice): number {
    return getRoomRackNightly(choice) * props.search.nights;
}

// The server's price for the current selection: what the summary shows and what the guest pays.
type CheckoutQuote = {
    room_total: number;
    promo_code: string | null;
    discount: number;
    tax_lines: Array<{ label: string; amount: number }>;
    taxes: number;
    total: number;
    addons: Array<{
        addon_id: number;
        name: string;
        quantity: number;
        total: number;
    }>;
    addons_total: number;
    grand_total: number;
    payment_type: 'full' | 'partial';
    pay_now: number;
    pay_later: number;
};

const serverQuote = ref<CheckoutQuote | null>(null);
const quoteError = ref('');
const isQuoting = ref(false);

const totalRoomCharges = computed(() => {
    if (serverQuote.value) return serverQuote.value.room_total;
    return selectedRoomsWithIndex.value.reduce((sum, item) => {
        return sum + getRoomOriginalTotal(item.choice);
    }, 0);
});

const appliedPromo = ref<PromoCode | null>(null);
const isCustomPromoActive = ref<boolean>(false);
const customPromoInput = ref<string>('');
const customPromoError = ref<string>('');
const customPromoSuccess = ref<string>('');

function isPromoDateApplicable(promo: PromoCode): {
    valid: boolean;
    reason?: string;
} {
    if (!promo.status || promo.deleted) {
        return {
            valid: false,
            reason: 'This promo code is currently inactive.',
        };
    }

    if (
        promo.channels &&
        promo.channels.length > 0 &&
        !promo.channels.includes('booking_engine')
    ) {
        return {
            valid: false,
            reason: 'This promo code is not available on the booking engine.',
        };
    }

    const nights = props.search.nights;

    // Minimum stay length
    if (
        promo.min_length_of_stay &&
        promo.min_length_of_stay > 0 &&
        nights < promo.min_length_of_stay
    ) {
        return {
            valid: false,
            reason: `Minimum stay of ${promo.min_length_of_stay} night(s) required.`,
        };
    }
    if (
        promo.length_of_stay?.enabled &&
        promo.length_of_stay.value &&
        nights < promo.length_of_stay.value
    ) {
        return {
            valid: false,
            reason: `Minimum stay of ${promo.length_of_stay.value} night(s) required.`,
        };
    }

    const checkInStr = props.search.check_in;
    const checkOutStr = props.search.check_out;
    const checkInDate = new Date(`${checkInStr}T00:00:00`);
    const today = new Date();
    today.setHours(0, 0, 0, 0);

    // Cut off days (must book at least X days in advance)
    if (
        promo.cut_off_days?.enabled &&
        promo.cut_off_days.value &&
        promo.cut_off_days.value > 0
    ) {
        const daysInAdvance = Math.floor(
            (checkInDate.getTime() - today.getTime()) / (1000 * 60 * 60 * 24),
        );
        if (daysInAdvance < promo.cut_off_days.value) {
            return {
                valid: false,
                reason: `Must be booked at least ${promo.cut_off_days.value} day(s) before check-in.`,
            };
        }
    }

    // Last minute (only allowed within X hours of check-in)
    if (
        promo.last_minute?.enabled &&
        promo.last_minute.value &&
        promo.last_minute.value > 0
    ) {
        const hoursUntilCheckIn = Math.floor(
            (checkInDate.getTime() - Date.now()) / (1000 * 60 * 60),
        );
        if (hoursUntilCheckIn > promo.last_minute.value) {
            return {
                valid: false,
                reason: `Only valid within ${promo.last_minute.value} hour(s) of check-in.`,
            };
        }
    }

    // Check-in date range
    if (promo.checkin_date_range?.enabled) {
        const { start, end } = promo.checkin_date_range;
        if (start && checkInStr < start) {
            return {
                valid: false,
                reason: `Valid for check-in on or after ${start}.`,
            };
        }
        if (end && checkInStr > end) {
            return {
                valid: false,
                reason: `Valid for check-in on or before ${end}.`,
            };
        }
    }

    // Check-out date range
    if (promo.checkout_date_range?.enabled) {
        const { start, end } = promo.checkout_date_range;
        if (start && checkOutStr < start) {
            return {
                valid: false,
                reason: `Valid for check-out on or after ${start}.`,
            };
        }
        if (end && checkOutStr > end) {
            return {
                valid: false,
                reason: `Valid for check-out on or before ${end}.`,
            };
        }
    }

    // Booking creation date range
    if (promo.booking_creation_date_range?.enabled) {
        const todayIso = new Date().toISOString().slice(0, 10);
        const { start, end } = promo.booking_creation_date_range;
        if (start && todayIso < start) {
            return {
                valid: false,
                reason: `Valid for bookings made on or after ${start}.`,
            };
        }
        if (end && todayIso > end) {
            return { valid: false, reason: `Promo code expired on ${end}.` };
        }
    }

    // Exclusion blackout dates
    if (promo.exclusion_date_range?.enabled) {
        const { start, end } = promo.exclusion_date_range;
        if (start && end && checkInStr < end && checkOutStr > start) {
            return {
                valid: false,
                reason: `Blackout dates apply between ${start} and ${end}.`,
            };
        }
    }

    return { valid: true };
}

function isPromoConditionFulfilled(promo: PromoCode): {
    valid: boolean;
    reason?: string;
} {
    const dateCheck = isPromoDateApplicable(promo);
    if (!dateCheck.valid) {
        return dateCheck;
    }

    // Booking amount range
    if (promo.booking_amount_range?.enabled) {
        const amount = totalRoomCharges.value;
        const { min, max } = promo.booking_amount_range;
        if (min !== null && min > 0 && amount < min) {
            if (amount === 0) {
                return {
                    valid: false,
                    reason: `Minimum booking amount of ${formatCurrency(min)} required.`,
                };
            }
            return {
                valid: false,
                reason: `Minimum booking amount of ${formatCurrency(min)} required (Current: ${formatCurrency(amount)}).`,
            };
        }
        if (max !== null && max > 0 && amount > max) {
            return {
                valid: false,
                reason: `Maximum booking amount for this promo is ${formatCurrency(max)} (Current: ${formatCurrency(amount)}).`,
            };
        }
    }

    return { valid: true };
}

// Active public promo codes applicable for the selected dates & channel
const availablePublicPromos = computed<PromoCode[]>(() => {
    const list = props.dealsPromotion?.public_promo_codes ?? [];
    return list.filter((p) => isPromoDateApplicable(p).valid);
});

// Qualifying public promo codes (conditions fulfilled including booking amount)
const qualifyingPublicPromos = computed<PromoCode[]>(() => {
    return availablePublicPromos.value.filter(
        (p) => isPromoConditionFulfilled(p).valid,
    );
});

// Auto-apply highest public promo if auto_apply_public_promo is true
watch(
    [
        qualifyingPublicPromos,
        () => props.dealsPromotion?.auto_apply_public_promo,
        totalRoomCharges,
    ],
    ([promos, autoApply]) => {
        // If applied promo no longer satisfies conditions, unapply it
        if (appliedPromo.value) {
            const check = isPromoConditionFulfilled(appliedPromo.value);
            if (!check.valid) {
                if (isCustomPromoActive.value) {
                    customPromoError.value =
                        check.reason ||
                        'Promo conditions are no longer fulfilled.';
                }
                appliedPromo.value = null;
                isCustomPromoActive.value = false;
            }
        }

        // Auto-apply if enabled and no custom promo is active
        if (autoApply && !isCustomPromoActive.value && !appliedPromo.value) {
            if (promos.length > 0) {
                const best = [...promos].sort(
                    (a, b) => b.discount_value - a.discount_value,
                )[0];
                appliedPromo.value = best;
                customPromoError.value = '';
                customPromoSuccess.value = '';
            }
        }
    },
    { immediate: true },
);

function selectPublicPromo(promo: PromoCode): void {
    if (appliedPromo.value?.code === promo.code && !isCustomPromoActive.value) {
        appliedPromo.value = null;
        customPromoError.value = '';
        customPromoSuccess.value = '';
    } else {
        const check = isPromoConditionFulfilled(promo);
        if (!check.valid) {
            return;
        }
        appliedPromo.value = promo;
        isCustomPromoActive.value = false;
        customPromoError.value = '';
        customPromoSuccess.value = '';
        showBadgeNotification(
            `Promo applied: ${promo.code}`,
            promo.tag_line || `${promo.discount_value}% discount`,
        );
    }
}

function toggleCustomPromoRadio(): void {
    if (isCustomPromoActive.value && appliedPromo.value !== null) {
        appliedPromo.value = null;
        isCustomPromoActive.value = false;
        customPromoSuccess.value = '';
        customPromoError.value = '';
    } else if (customPromoInput.value.trim()) {
        void applyEnteredPromoCode();
    }
}

const isCheckingPromo = ref(false);

// A code that isn't a public one may be secret: only the server knows those, and sends its rules once it applies.
async function fetchSecretPromo(code: string): Promise<PromoCode | null> {
    if (selectedRoomsWithIndex.value.length === 0) {
        customPromoError.value = 'Select a room to apply this promo code.';
        return null;
    }

    isCheckingPromo.value = true;
    try {
        const { response, data } = await postQuote({
            ...checkoutSelection(),
            promo_code: code,
        });
        if (!response.ok || !data.success) {
            customPromoError.value =
                data.errors?.promo_code?.[0] ||
                data.message ||
                'Invalid promo code.';
            return null;
        }
        return data.promo as PromoCode;
    } catch {
        customPromoError.value =
            'Unable to check the promo code. Please try again.';
        return null;
    } finally {
        isCheckingPromo.value = false;
    }
}

async function applyEnteredPromoCode(): Promise<void> {
    customPromoError.value = '';
    customPromoSuccess.value = '';

    const code = customPromoInput.value.trim().toUpperCase();
    if (!code) {
        customPromoError.value = 'Please enter a promo code.';
        return;
    }

    const publicList = props.dealsPromotion?.public_promo_codes ?? [];
    const target =
        publicList.find((p) => p.code.toUpperCase() === code) ??
        (await fetchSecretPromo(code));

    if (!target) {
        return;
    }

    const check = isPromoConditionFulfilled(target);
    if (!check.valid) {
        customPromoError.value =
            check.reason || 'Conditions for this promo code are not fulfilled.';
        return;
    }

    appliedPromo.value = target;
    isCustomPromoActive.value = true;
    customPromoSuccess.value = `Promo code "${target.code}" applied!`;
    showBadgeNotification(
        `Promo applied: ${target.code}`,
        target.tag_line || `${target.discount_value}% discount`,
    );
}

const appliedDiscountPercent = computed<number>(() => {
    if (!appliedPromo.value || totalRoomCharges.value <= 0) return 0;
    if (!isPromoConditionFulfilled(appliedPromo.value).valid) return 0;
    if (appliedPromo.value.discount_type === 'percentage') {
        return appliedPromo.value.discount_value;
    }
    return Math.min(
        100,
        Math.round(
            (appliedPromo.value.discount_value / totalRoomCharges.value) * 100,
        ),
    );
});

function getRoomDiscountedNightly(choice: SelectedRoomChoice): number {
    const rack = getRoomRackNightly(choice);
    const disc = appliedDiscountPercent.value / 100;
    return Math.round(rack * (1 - disc) * 100) / 100;
}

function getRoomNetTotal(choice: SelectedRoomChoice): number {
    return getRoomDiscountedNightly(choice) * props.search.nights;
}

const totalDiscountAmount = computed(() => {
    if (serverQuote.value) return serverQuote.value.discount;
    if (!appliedPromo.value || totalRoomCharges.value <= 0) return 0;
    if (!isPromoConditionFulfilled(appliedPromo.value).valid) return 0;

    let discount = 0;
    if (appliedPromo.value.discount_type === 'percentage') {
        discount =
            totalRoomCharges.value * (appliedPromo.value.discount_value / 100);
    } else {
        discount = appliedPromo.value.discount_value;
    }

    if (
        appliedPromo.value.max_discount_per_booking?.enabled &&
        appliedPromo.value.max_discount_per_booking.value
    ) {
        discount = Math.min(
            discount,
            appliedPromo.value.max_discount_per_booking.value,
        );
    }

    return Math.min(totalRoomCharges.value, Math.round(discount * 100) / 100);
});

const netRoomCharges = computed(() => {
    return Math.max(0, totalRoomCharges.value - totalDiscountAmount.value);
});

const taxRate = computed(() => {
    if (props.taxComponents && props.taxComponents.length > 0) {
        const sum = props.taxComponents.reduce(
            (acc, c) => acc + (c.percentage ?? c.rate ?? 0),
            0,
        );
        if (sum > 0) return sum / 100;
    }
    return 0.18;
});

const calculatedTaxes = computed(() => {
    if (serverQuote.value) return serverQuote.value.taxes;
    return Math.round(netRoomCharges.value * taxRate.value * 100) / 100;
});

const taxBreakdown = computed(() => {
    if (serverQuote.value) return serverQuote.value.tax_lines;
    const halfRate = Math.round((taxRate.value / 2) * 1000) / 10;
    const halfAmount = Math.round((calculatedTaxes.value / 2) * 100) / 100;
    return [
        { label: `CGST (${halfRate}%)`, amount: halfAmount },
        {
            label: `SGST (${halfRate}%)`,
            amount:
                Math.round((calculatedTaxes.value - halfAmount) * 100) / 100,
        },
    ];
});

// Add-ons the guest picked (add-on id → quantity), priced like the server: price × quantity ×
// nights and/or guests, plus its tax.
const addonQuantities = reactive<Record<number, number>>({});

const addonLines = computed(() =>
    (props.addons ?? [])
        .filter((addon) => (addonQuantities[addon.id] ?? 0) > 0)
        .map((addon) => {
            const quantity = addonQuantities[addon.id];
            const guests = Math.max(1, totalGuests.value);
            const nights = Math.max(1, props.search.nights);
            const multiplier =
                addon.pricing === 'per_night'
                    ? nights
                    : addon.pricing === 'per_guest'
                      ? guests
                      : addon.pricing === 'per_guest_per_night'
                        ? guests * nights
                        : 1;
            const amount =
                Math.round(addon.price * quantity * multiplier * 100) / 100;
            const tax =
                Math.round(((amount * addon.tax_rate) / 100) * 100) / 100;

            return {
                addon_id: addon.id,
                name: addon.name,
                quantity,
                total: Math.round((amount + tax) * 100) / 100,
            };
        }),
);

const addonsTotal = computed(
    () =>
        serverQuote.value?.addons_total ??
        Math.round(
            addonLines.value.reduce((sum, line) => sum + line.total, 0) * 100,
        ) / 100,
);

function toggleAddon(addonId: number): void {
    addonQuantities[addonId] = (addonQuantities[addonId] ?? 0) > 0 ? 0 : 1;
}

const totalPayable = computed(() => {
    if (serverQuote.value) return serverQuote.value.grand_total;
    return (
        Math.round(
            (netRoomCharges.value + calculatedTaxes.value + addonsTotal.value) *
                100,
        ) / 100
    );
});

const originalTotalTaxes = computed(() => {
    return Math.round(totalRoomCharges.value * taxRate.value * 100) / 100;
});

const originalTotalPayable = computed(() => {
    return (
        Math.round(
            (totalRoomCharges.value +
                originalTotalTaxes.value +
                addonsTotal.value) *
                100,
        ) / 100
    );
});

const totalSavedAmount = computed(() => {
    if (serverQuote.value) return serverQuote.value.discount;
    return Math.max(
        0,
        Math.round((originalTotalPayable.value - totalPayable.value) * 100) /
            100,
    );
});

function getPromoSavings(promo: PromoCode): number {
    if (totalRoomCharges.value <= 0) return 0;
    let discount = 0;
    if (promo.discount_type === 'percentage') {
        discount = totalRoomCharges.value * (promo.discount_value / 100);
    } else {
        discount = promo.discount_value;
    }
    if (
        promo.max_discount_per_booking?.enabled &&
        promo.max_discount_per_booking.value
    ) {
        discount = Math.min(discount, promo.max_discount_per_booking.value);
    }
    return Math.min(totalRoomCharges.value, Math.round(discount * 100) / 100);
}

const payNowAmount = computed(() => {
    if (serverQuote.value) return serverQuote.value.pay_now;
    if (allowPartialPayment.value && partialPayment.value) {
        return (
            Math.round(
                totalPayable.value *
                    (partialPaymentPercentage.value / 100) *
                    100,
            ) / 100
        );
    }
    return totalPayable.value;
});

// Sold out: the guest can join the waitlist for the searched dates and party.
const waitlistForm = reactive({ name: '', phone: '', email: '' });
const waitlistState = ref<{
    status: 'idle' | 'sending' | 'done' | 'error';
    message: string;
}>({ status: 'idle', message: '' });

async function joinWaitlist(): Promise<void> {
    waitlistState.value = { status: 'sending', message: '' };

    try {
        const response = await fetch(
            `/properties/${props.property.ulid}/booking-engine/waitlist`,
            {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-XSRF-TOKEN': getXsrfToken(),
                },
                body: JSON.stringify({
                    check_in: searchForm.check_in,
                    check_out: searchForm.check_out,
                    rooms: searchForm.rooms.length,
                    adults: searchForm.rooms[0]?.adults ?? 2,
                    children: searchForm.rooms[0]?.children ?? 0,
                    guest: { ...waitlistForm },
                }),
            },
        );
        const data = await response.json();

        if (response.ok && data.success) {
            waitlistState.value = { status: 'done', message: data.message };
            return;
        }

        const firstError = Object.values(
            (data.errors ?? {}) as Record<string, string[]>,
        ).flat()[0];
        waitlistState.value = {
            status: 'error',
            message:
                firstError ??
                data.message ??
                "We couldn't add you to the waitlist.",
        };
    } catch {
        waitlistState.value = {
            status: 'error',
            message: "We couldn't add you to the waitlist.",
        };
    }
}

// What the guest picked, without prices: the server prices it.
function checkoutSelection(): Record<string, unknown> {
    return {
        check_in: props.search.check_in,
        check_out: props.search.check_out,
        payment_type:
            allowPartialPayment.value && partialPayment.value
                ? 'partial'
                : 'full',
        promo_code: appliedPromo.value?.code ?? null,
        addons: addonLines.value.map((line) => ({
            addon_id: line.addon_id,
            quantity: line.quantity,
        })),
        rooms: selectedRoomsWithIndex.value.map((item) => {
            const req = searchForm.rooms[item.index];
            return {
                category_id: item.choice.room.id,
                meal_plan: item.choice.mealPlan.plan,
                package_id: item.choice.mealPlan.package_id ?? null,
                adults: req ? req.adults : 1,
                children: req ? req.children : 0,
                infants: req ? req.infants : 0,
            };
        }),
    };
}

async function postQuote(
    selection: Record<string, unknown>,
): Promise<{ response: Response; data: any }> {
    const response = await fetch(
        BookingEngineController.quote.url(props.property.ulid),
        {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-XSRF-TOKEN': getXsrfToken(),
            },
            body: JSON.stringify(selection),
        },
    );

    return { response, data: await response.json() };
}

let quoteRequest = 0;

async function refreshQuote(): Promise<void> {
    if (selectedRoomsWithIndex.value.length === 0) {
        serverQuote.value = null;
        quoteError.value = '';
        return;
    }

    const request = ++quoteRequest;
    isQuoting.value = true;
    try {
        const { response, data } = await postQuote(checkoutSelection());
        if (request !== quoteRequest) return;

        if (response.ok && data.success) {
            serverQuote.value = data.quote as CheckoutQuote;
            quoteError.value = '';
            if (data.promo) {
                appliedPromo.value = data.promo as PromoCode;
            }
            return;
        }

        // A promo code the server turns down is dropped, and the stay is priced again without it.
        if (data.errors?.promo_code && appliedPromo.value) {
            customPromoError.value = data.errors.promo_code[0];
            appliedPromo.value = null;
            isCustomPromoActive.value = false;
            return;
        }

        serverQuote.value = null;
        quoteError.value =
            data.message ||
            'This stay cannot be priced right now. Please try again.';
    } catch {
        if (request === quoteRequest) {
            serverQuote.value = null;
            quoteError.value =
                'This stay cannot be priced right now. Please try again.';
        }
    } finally {
        if (request === quoteRequest) {
            isQuoting.value = false;
        }
    }
}

let quoteTimer: ReturnType<typeof setTimeout> | undefined;

watch(
    () => JSON.stringify(checkoutSelection()),
    () => {
        clearTimeout(quoteTimer);
        quoteTimer = setTimeout(() => void refreshQuote(), 250);
    },
    { immediate: true },
);

const remainingBalanceAmount = computed(() => {
    return Math.max(
        0,
        Math.round((totalPayable.value - payNowAmount.value) * 100) / 100,
    );
});

/** What the partial option itself would take now and leave for the hotel, whichever option is selected. */
const partialPayNowAmount = computed(
    () =>
        Math.round(
            totalPayable.value * (partialPaymentPercentage.value / 100) * 100,
        ) / 100,
);
const partialRemainingAmount = computed(() =>
    Math.max(
        0,
        Math.round((totalPayable.value - partialPayNowAmount.value) * 100) /
            100,
    ),
);

function modifyRoom(index: number): void {
    activeRoomIndex.value = index;
    step.value = 'select-room';
    window.scrollTo({ top: 0, behavior: 'smooth' });
}

const selectedGateway = ref<'razorpay' | 'cashfree' | 'test'>('razorpay');

const guestDetails = reactive({
    name: '',
    phone: '',
    email: '',
    special_requests: '',
});

const guestErrors = reactive({
    name: '',
    phone: '',
    email: '',
});

const isProcessingPayment = ref(false);
const paymentError = ref('');

interface ConfirmedBooking {
    id: number;
    booking_ref_id: string;
    status: string;
    check_in: string;
    check_out: string;
    guest_name: string;
    guest_email: string;
    guest_phone: string;
    total: number;
    paid_now: number;
    outstanding: number;
    gateway: string;
    transaction_id: string;
    /** "Check in online", when the property offers web check-in. */
    web_checkin_url: string | null;
}

const confirmedBooking = ref<ConfirmedBooking | null>(null);

const showSimulatedPaymentModal = ref(false);
const simulatedPaymentData = ref<{
    gateway: 'razorpay' | 'cashfree' | 'test';
    booking_ref_id: string;
    order_id: string;
    amount: number;
    currency: string;
    is_test_mode?: boolean;
    payment_session_id?: string;
    key_id?: string;
} | null>(null);

function getXsrfToken(): string {
    const match = document.cookie.match(/XSRF-TOKEN=([^;]+)/);
    return match ? decodeURIComponent(match[1]) : '';
}

function loadExternalScript(src: string): Promise<boolean> {
    return new Promise((resolve) => {
        if (document.querySelector(`script[src="${src}"]`)) {
            resolve(true);
            return;
        }
        const script = document.createElement('script');
        script.src = src;
        script.async = true;
        script.onload = () => resolve(true);
        script.onerror = () => resolve(false);
        document.head.appendChild(script);
    });
}

function validateGuestForm(): boolean {
    guestErrors.name = '';
    guestErrors.phone = '';
    guestErrors.email = '';

    let isValid = true;

    if (!guestDetails.name || !guestDetails.name.trim()) {
        guestErrors.name = 'Full name is required';
        isValid = false;
    }

    if (!guestDetails.phone || !guestDetails.phone.trim()) {
        guestErrors.phone = 'Mobile number is required';
        isValid = false;
    } else if (guestDetails.phone.trim().length < 6) {
        guestErrors.phone = 'Please enter a valid mobile number';
        isValid = false;
    }

    const emailPattern = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
    if (!guestDetails.email || !guestDetails.email.trim()) {
        guestErrors.email = 'E-mail address is required';
        isValid = false;
    } else if (!emailPattern.test(guestDetails.email.trim())) {
        guestErrors.email = 'Please enter a valid e-mail address';
        isValid = false;
    }

    return isValid;
}

function proceed(): void {
    if (totalRooms.value === 0) {
        return;
    }

    step.value = 'guest-details';
    window.scrollTo({ top: 0, behavior: 'smooth' });
}

function backToRooms(): void {
    step.value = 'select-room';
    window.scrollTo({ top: 0, behavior: 'smooth' });
}

async function onPayOrProceed(): Promise<void> {
    if (step.value === 'select-room') {
        proceed();
        return;
    }

    if (!validateGuestForm()) {
        showBadgeNotification(
            'Missing guest information',
            'Please fill in all required guest details.',
            'error',
        );
        return;
    }

    if (!agreeTerms.value) {
        showBadgeNotification(
            'Terms required',
            'Please accept the Terms & Conditions, Privacy Policy and Refund Policy.',
            'error',
        );
        return;
    }

    // Pay only a price the server has quoted for this exact selection.
    if (isQuoting.value || !serverQuote.value) {
        showBadgeNotification(
            'Updating price',
            quoteError.value || 'Please wait while we confirm the price.',
            'error',
        );
        return;
    }

    await initiatePaymentFlow();
}

async function initiatePaymentFlow(): Promise<void> {
    isProcessingPayment.value = true;
    paymentError.value = '';

    try {
        const response = await fetch(
            `/properties/${props.property.ulid}/booking-engine/checkout/initiate`,
            {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-XSRF-TOKEN': getXsrfToken(),
                },
                body: JSON.stringify({
                    ...checkoutSelection(),
                    payment_gateway: selectedGateway.value,
                    guest: {
                        name: guestDetails.name.trim(),
                        phone: guestDetails.phone.trim(),
                        email: guestDetails.email.trim(),
                        special_requests:
                            guestDetails.special_requests.trim() || null,
                    },
                    consent: {
                        terms: agreeTerms.value,
                        marketing: agreeMarketing.value,
                    },
                }),
            },
        );

        const data = await response.json();

        if (!response.ok || !data.success) {
            throw new Error(
                data.message ||
                    'Unable to initiate checkout with payment gateway.',
            );
        }

        // The amount charged is the server's: show exactly that.
        if (data.quote) {
            serverQuote.value = data.quote as CheckoutQuote;
        }

        if (selectedGateway.value === 'test') {
            await handleTestGatewayFlow(data);
        } else if (selectedGateway.value === 'razorpay') {
            await handleRazorpayFlow(data);
        } else {
            await handleCashfreeFlow(data);
        }
    } catch (err: any) {
        isProcessingPayment.value = false;
        paymentError.value =
            err.message ||
            'Something went wrong initiating your payment. Please try again.';
        showBadgeNotification('Checkout Error', paymentError.value, 'error');
    }
}

async function handleTestGatewayFlow(data: any): Promise<void> {
    simulatedPaymentData.value = data;
    showSimulatedPaymentModal.value = true;
    isProcessingPayment.value = false;
}

async function handleRazorpayFlow(data: any): Promise<void> {
    const loaded = await loadExternalScript(
        'https://checkout.razorpay.com/v1/checkout.js',
    );
    if (!loaded || !(window as any).Razorpay) {
        isProcessingPayment.value = false;
        paymentError.value =
            'Failed to load Razorpay payment SDK. Please check your internet connection.';
        showBadgeNotification('Payment SDK Error', paymentError.value, 'error');
        return;
    }

    const options = {
        key: data.key_id,
        amount: data.amount,
        currency: data.currency || 'INR',
        name: props.property.name,
        description: `Booking reservation ${data.booking_ref_id}`,
        order_id: data.order_id,
        prefill: {
            name: guestDetails.name,
            email: guestDetails.email,
            contact: guestDetails.phone,
        },
        theme: {
            color: '#4a7c59',
        },
        handler: async function (response: any) {
            await finalizeBooking({
                booking_ref_id: data.booking_ref_id,
                payment_gateway: 'razorpay',
                transaction_id:
                    response.razorpay_payment_id || `RP_${Date.now()}`,
                order_id: response.razorpay_order_id || data.order_id,
                signature: response.razorpay_signature,
            });
        },
        modal: {
            ondismiss: function () {
                isProcessingPayment.value = false;
            },
        },
    };

    try {
        const rzp = new (window as any).Razorpay(options);
        rzp.on('payment.failed', function (resp: any) {
            isProcessingPayment.value = false;
            paymentError.value =
                resp.error?.description || 'Razorpay payment was unsuccessful.';
            showBadgeNotification(
                'Payment Failed',
                paymentError.value,
                'error',
            );
        });
        rzp.open();
    } catch (err: any) {
        isProcessingPayment.value = false;
        paymentError.value =
            err?.message || 'Unable to open Razorpay checkout.';
        showBadgeNotification('Razorpay Error', paymentError.value, 'error');
    }
}

async function handleCashfreeFlow(data: any): Promise<void> {
    if (!data.payment_session_id) {
        isProcessingPayment.value = false;
        paymentError.value =
            data.message || 'Cashfree payment session is missing.';
        showBadgeNotification('Cashfree Error', paymentError.value, 'error');
        return;
    }

    const loaded = await loadExternalScript(
        'https://sdk.cashfree.com/js/v3/cashfree.js',
    );
    if (!loaded || !(window as any).Cashfree) {
        isProcessingPayment.value = false;
        paymentError.value =
            'Failed to load Cashfree payment SDK. Please check your internet connection.';
        showBadgeNotification('Payment SDK Error', paymentError.value, 'error');
        return;
    }

    try {
        const mode = data.mode === 'production' ? 'production' : 'sandbox';
        const cashfree = (window as any).Cashfree({ mode });
        cashfree
            .checkout({
                paymentSessionId: data.payment_session_id,
                redirectTarget: '_modal',
            })
            .then(async (result: any) => {
                if (result.error) {
                    isProcessingPayment.value = false;
                    paymentError.value =
                        result.error.message || 'Cashfree payment failed.';
                    showBadgeNotification(
                        'Payment Failed',
                        paymentError.value,
                        'error',
                    );
                } else if (result.paymentDetails) {
                    await finalizeBooking({
                        booking_ref_id: data.booking_ref_id,
                        payment_gateway: 'cashfree',
                        transaction_id:
                            result.paymentDetails.paymentMessage ||
                            result.paymentDetails.cfPaymentId ||
                            `CF_${Date.now()}`,
                        order_id: data.order_id,
                    });
                } else {
                    isProcessingPayment.value = false;
                }
            })
            .catch((err: any) => {
                isProcessingPayment.value = false;
                paymentError.value =
                    err?.message || 'Cashfree checkout encountered an issue.';
                showBadgeNotification(
                    'Cashfree Error',
                    paymentError.value,
                    'error',
                );
            });
    } catch (err: any) {
        isProcessingPayment.value = false;
        paymentError.value =
            err?.message || 'Unable to open Cashfree checkout modal.';
        showBadgeNotification('Cashfree Error', paymentError.value, 'error');
    }
}

async function finalizeBooking(paymentDetails: {
    booking_ref_id: string;
    payment_gateway: string;
    transaction_id?: string;
    order_id?: string;
    signature?: string;
}): Promise<void> {
    isProcessingPayment.value = true;
    paymentError.value = '';

    try {
        const response = await fetch(
            `/properties/${props.property.ulid}/booking-engine/checkout/confirm`,
            {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-XSRF-TOKEN': getXsrfToken(),
                },
                body: JSON.stringify({
                    booking_ref_id: paymentDetails.booking_ref_id,
                    payment_gateway: paymentDetails.payment_gateway,
                    transaction_id: paymentDetails.transaction_id,
                    order_id: paymentDetails.order_id,
                    signature: paymentDetails.signature,
                }),
            },
        );

        const data = await response.json();

        if (!response.ok || !data.success) {
            throw new Error(
                data.message ||
                    'Payment was received but booking confirmation encountered an error. Please contact property support.',
            );
        }

        confirmedBooking.value = data.booking;
        showSimulatedPaymentModal.value = false;
        // The stay is booked: nothing is left in the cart.
        roomSelections.value = {};
        activeRoomIndex.value = 0;
        step.value = 'confirmed';
        showBadgeNotification(
            'Booking Confirmed!',
            `Reference: ${data.booking.booking_ref_id}`,
        );
        window.scrollTo({ top: 0, behavior: 'smooth' });
    } catch (err: any) {
        paymentError.value = err.message || 'Booking confirmation failed.';
        showBadgeNotification(
            'Confirmation Error',
            paymentError.value,
            'error',
        );
    } finally {
        isProcessingPayment.value = false;
    }
}

async function simulateSuccessfulPayment(): Promise<void> {
    if (!simulatedPaymentData.value) return;
    const prefix =
        simulatedPaymentData.value.gateway === 'razorpay'
            ? 'pay_rzp_mock_'
            : simulatedPaymentData.value.gateway === 'cashfree'
              ? 'pay_cf_mock_'
              : 'pay_test_mock_';
    await finalizeBooking({
        booking_ref_id: simulatedPaymentData.value.booking_ref_id,
        payment_gateway: simulatedPaymentData.value.gateway,
        transaction_id:
            prefix + Math.random().toString(36).substring(2, 10).toUpperCase(),
        order_id: simulatedPaymentData.value.order_id,
    });
}

function cancelSimulatedPayment(): void {
    showSimulatedPaymentModal.value = false;
    isProcessingPayment.value = false;
    paymentError.value = 'Payment was cancelled.';
}

function startNewBooking(): void {
    roomSelections.value = {};
    activeRoomIndex.value = 0;
    step.value = 'select-room';
    confirmedBooking.value = null;
    paymentError.value = '';
    window.scrollTo({ top: 0, behavior: 'smooth' });
}

function printBookingReceipt(): void {
    window.print();
}
</script>

<template>
    <Head :title="`${property.name} — Booking Engine`" />

    <div
        class="min-h-screen bg-[#f5f5f4]"
        :style="{ '--be-theme': property.branding.theme_color }"
    >
        <!-- Badge Notification: Room added to cart (Center Top) -->
        <div
            class="pointer-events-none fixed inset-x-0 top-5 z-50 flex justify-center px-4"
        >
            <Transition
                enter-active-class="transition duration-300 ease-out"
                enter-from-class="-translate-y-4 opacity-0 scale-95"
                enter-to-class="translate-y-0 opacity-100 scale-100"
                leave-active-class="transition duration-200 ease-in"
                leave-from-class="translate-y-0 opacity-100 scale-100"
                leave-to-class="-translate-y-4 opacity-0 scale-95"
            >
                <div
                    v-if="toastNotification.visible"
                    class="pointer-events-auto flex max-w-md items-center gap-3 rounded-lg border px-4 py-3 shadow-xl"
                    :class="
                        toastNotification.variant === 'error'
                            ? 'border-red-300 bg-red-50 text-red-950'
                            : 'border-emerald-300 bg-emerald-50 text-emerald-950'
                    "
                    :role="
                        toastNotification.variant === 'error'
                            ? 'alert'
                            : 'status'
                    "
                    :aria-live="
                        toastNotification.variant === 'error'
                            ? 'assertive'
                            : 'polite'
                    "
                    :data-variant="toastNotification.variant"
                >
                    <span
                        class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full text-white shadow-sm"
                        :class="
                            toastNotification.variant === 'error'
                                ? 'bg-red-600'
                                : 'bg-emerald-600'
                        "
                    >
                        <svg
                            v-if="toastNotification.variant === 'error'"
                            class="h-4 w-4"
                            fill="none"
                            viewBox="0 0 24 24"
                            stroke="currentColor"
                            stroke-width="2.5"
                        >
                            <path
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                d="M12 9v4m0 4h.01M4.93 19h14.14a2 2 0 001.73-3L13.73 4a2 2 0 00-3.46 0L3.2 16a2 2 0 001.73 3z"
                            />
                        </svg>
                        <svg
                            v-else
                            class="h-4 w-4"
                            fill="none"
                            viewBox="0 0 24 24"
                            stroke="currentColor"
                            stroke-width="2.5"
                        >
                            <path
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                d="M5 13l4 4L19 7"
                            />
                        </svg>
                    </span>
                    <div class="min-w-0 flex-1">
                        <p
                            class="text-sm font-semibold"
                            :class="
                                toastNotification.variant === 'error'
                                    ? 'text-red-950'
                                    : 'text-emerald-950'
                            "
                        >
                            {{ toastNotification.title || 'Notification' }}
                        </p>
                        <p
                            v-if="toastNotification.message"
                            class="truncate text-xs"
                            :class="
                                toastNotification.variant === 'error'
                                    ? 'text-red-800'
                                    : 'text-emerald-800'
                            "
                        >
                            {{ toastNotification.message }}
                        </p>
                    </div>
                    <button
                        type="button"
                        class="ml-2 rounded p-1 focus:outline-none"
                        :class="
                            toastNotification.variant === 'error'
                                ? 'text-red-700 hover:bg-red-100 hover:text-red-950'
                                : 'text-emerald-700 hover:bg-emerald-100 hover:text-emerald-950'
                        "
                        aria-label="Close"
                        @click="dismissNotification"
                    >
                        <svg
                            class="h-4 w-4"
                            fill="none"
                            viewBox="0 0 24 24"
                            stroke="currentColor"
                            stroke-width="2"
                        >
                            <path
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                d="M6 18L18 6M6 6l12 12"
                            />
                        </svg>
                    </button>
                </div>
            </Transition>
        </div>

        <!-- Colours, logo and contacts come from Properties → Branding. -->
        <header
            class="flex flex-wrap items-center justify-between gap-3 px-6 py-4"
            :style="{
                backgroundColor: property.branding.header_color,
                color: property.branding.text_color,
            }"
        >
            <div class="flex items-center gap-3">
                <component
                    :is="property.branding.website_url ? 'a' : 'span'"
                    v-if="property.branding.logo_url"
                    :href="property.branding.website_url || undefined"
                    target="_blank"
                    rel="noopener"
                >
                    <img
                        :src="property.branding.logo_url"
                        :alt="property.name"
                        class="h-12 max-w-40 object-contain"
                    />
                </component>
                <div>
                    <p class="text-xs tracking-wide uppercase opacity-60">
                        Secure Booking
                    </p>
                    <h1
                        v-if="property.branding.show_property_name"
                        class="font-display text-lg font-semibold"
                    >
                        {{ property.name }}
                    </h1>
                    <p
                        v-if="
                            property.branding.phone_numbers.length ||
                            property.branding.support_emails.length
                        "
                        class="text-xs opacity-80"
                    >
                        {{
                            [
                                ...property.branding.phone_numbers,
                                ...property.branding.support_emails,
                            ].join(' · ')
                        }}
                    </p>
                </div>
            </div>
            <div class="flex items-center gap-3">
                <div
                    v-if="selectedRoomsList.length > 0"
                    class="flex items-center gap-1.5 rounded-full bg-white/10 px-3 py-1.5 text-xs font-medium text-white backdrop-blur-sm"
                >
                    <svg
                        class="h-4 w-4 text-emerald-400"
                        fill="none"
                        viewBox="0 0 24 24"
                        stroke="currentColor"
                        stroke-width="2"
                    >
                        <path
                            stroke-linecap="round"
                            stroke-linejoin="round"
                            d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 11-4 0 2 2 0 014 0z"
                        />
                    </svg>
                    <span>{{ selectedRoomsList.length }} in cart</span>
                </div>
                <span
                    class="rounded-md border border-white/30 px-3 py-1.5 text-sm"
                    >{{ property.currency }}</span
                >
            </div>
        </header>

        <img
            v-if="property.branding.banner_image_url"
            :src="property.branding.banner_image_url"
            :alt="property.name"
            class="aspect-[24/5] w-full object-cover"
        />

        <form
            class="flex flex-wrap items-end gap-3 border-b border-[#e3e3e0] bg-white px-6 py-4"
            @submit.prevent="handleSearchOrApply"
        >
            <BookingEngineDateRangePicker
                v-model:check-in="searchForm.check_in"
                v-model:check-out="searchForm.check_out"
                :calendar-url="calendarUrl"
                :currency="property.currency"
                @apply="handleSearchOrApply"
            />
            <RoomGuestSelector
                :model-value="searchForm.rooms"
                @apply="handleRoomGuestApply"
            />
            <button
                type="submit"
                :disabled="isFetchingRooms"
                class="flex items-center gap-2 rounded-sm bg-[#4a7c59] px-6 py-2 text-sm font-semibold tracking-wide text-white uppercase hover:bg-[#3f6d4d] disabled:opacity-60"
            >
                <svg
                    v-if="isFetchingRooms"
                    class="h-4 w-4 animate-spin text-white"
                    xmlns="http://www.w3.org/2000/svg"
                    fill="none"
                    viewBox="0 0 24 24"
                >
                    <circle
                        class="opacity-25"
                        cx="12"
                        cy="12"
                        r="10"
                        stroke="currentColor"
                        stroke-width="4"
                    ></circle>
                    <path
                        class="opacity-75"
                        fill="currentColor"
                        d="M4 12a8 8 0 018-8v8H4z"
                    ></path>
                </svg>
                <span>Search</span>
            </button>
        </form>

        <div
            class="mx-auto grid gap-6 px-6 py-6"
            :class="
                step === 'confirmed'
                    ? 'max-w-5xl grid-cols-1'
                    : 'max-w-6xl grid-cols-1 lg:grid-cols-[1fr_320px]'
            "
        >
            <div class="flex flex-col gap-4">
                <div
                    class="flex items-center justify-center gap-4 rounded-lg border border-[#e3e3e0] bg-white px-4 py-4 sm:gap-6 sm:px-6"
                >
                    <div class="flex items-center gap-2">
                        <span
                            class="flex h-8 w-8 items-center justify-center rounded-full border-2 text-sm font-semibold transition-colors"
                            :class="
                                step === 'select-room'
                                    ? 'border-[#4a7c59] bg-[#4a7c59] text-white'
                                    : step === 'guest-details' ||
                                        step === 'confirmed'
                                      ? 'border-[#4a7c59] bg-[#f2f7f4] text-[#4a7c59]'
                                      : 'border-[#e3e3e0] text-[#A1A09A]'
                            "
                        >
                            <svg
                                v-if="
                                    step === 'guest-details' ||
                                    step === 'confirmed'
                                "
                                class="h-4 w-4"
                                fill="none"
                                viewBox="0 0 24 24"
                                stroke="currentColor"
                                stroke-width="2.5"
                            >
                                <path
                                    stroke-linecap="round"
                                    stroke-linejoin="round"
                                    d="M5 13l4 4L19 7"
                                />
                            </svg>
                            <span v-else>1</span>
                        </span>
                        <span
                            class="text-sm font-medium"
                            :class="
                                step === 'select-room'
                                    ? 'font-bold text-[#1b1b18]'
                                    : step === 'guest-details' ||
                                        step === 'confirmed'
                                      ? 'text-[#4a7c59]'
                                      : 'text-[#A1A09A]'
                            "
                            >Select Room</span
                        >
                    </div>
                    <div class="h-px w-6 bg-[#e3e3e0] sm:w-10" />
                    <div class="flex items-center gap-2">
                        <span
                            class="flex h-8 w-8 items-center justify-center rounded-full border-2 text-sm font-semibold transition-colors"
                            :class="
                                step === 'guest-details'
                                    ? 'border-[#4a7c59] bg-[#4a7c59] text-white'
                                    : step === 'confirmed'
                                      ? 'border-[#4a7c59] bg-[#f2f7f4] text-[#4a7c59]'
                                      : 'border-[#e3e3e0] text-[#A1A09A]'
                            "
                        >
                            <svg
                                v-if="step === 'confirmed'"
                                class="h-4 w-4"
                                fill="none"
                                viewBox="0 0 24 24"
                                stroke="currentColor"
                                stroke-width="2.5"
                            >
                                <path
                                    stroke-linecap="round"
                                    stroke-linejoin="round"
                                    d="M5 13l4 4L19 7"
                                />
                            </svg>
                            <span v-else>2</span>
                        </span>
                        <span
                            class="text-sm font-medium"
                            :class="
                                step === 'guest-details'
                                    ? 'font-bold text-[#1b1b18]'
                                    : step === 'confirmed'
                                      ? 'text-[#4a7c59]'
                                      : 'text-[#A1A09A]'
                            "
                            >Guest &amp; Payment</span
                        >
                    </div>
                    <div class="h-px w-6 bg-[#e3e3e0] sm:w-10" />
                    <div class="flex items-center gap-2">
                        <span
                            class="flex h-8 w-8 items-center justify-center rounded-full border-2 text-sm font-semibold transition-colors"
                            :class="
                                step === 'confirmed'
                                    ? 'border-[#4a7c59] bg-[#4a7c59] text-white'
                                    : 'border-[#e3e3e0] text-[#A1A09A]'
                            "
                        >
                            3
                        </span>
                        <span
                            class="text-sm font-medium"
                            :class="
                                step === 'confirmed'
                                    ? 'font-bold text-[#1b1b18]'
                                    : 'text-[#A1A09A]'
                            "
                            >Confirmation</span
                        >
                    </div>
                </div>

                <template v-if="step === 'select-room'">
                    <div
                        v-if="!roomCategories.length"
                        class="rounded-lg border border-dashed border-[#e3e3e0] bg-white px-6 py-16 text-center text-sm text-[#706f6c]"
                    >
                        No room categories are configured for this property yet.
                    </div>

                    <div
                        v-if="roomCategories.length"
                        class="overflow-hidden rounded-lg border border-[#3b4b59]"
                    >
                        <div
                            class="flex items-center justify-between bg-[#3b4b59] px-6 py-3.5 text-white"
                        >
                            <div class="flex items-center gap-3">
                                <button
                                    v-if="activeRoomIndex > 0"
                                    type="button"
                                    class="flex items-center gap-1 text-sm font-medium text-white/80 transition-colors hover:text-white"
                                    @click="activeRoomIndex--"
                                >
                                    <svg
                                        xmlns="http://www.w3.org/2000/svg"
                                        class="h-4 w-4"
                                        fill="none"
                                        viewBox="0 0 24 24"
                                        stroke="currentColor"
                                    >
                                        <path
                                            stroke-linecap="round"
                                            stroke-linejoin="round"
                                            stroke-width="2"
                                            d="M15 19l-7-7 7-7"
                                        />
                                    </svg>
                                    Back to Room {{ activeRoomIndex }}
                                </button>
                                <h2
                                    class="font-display text-base font-semibold"
                                >
                                    Select Option for Room
                                    {{ activeRoomIndex + 1 }}
                                </h2>
                            </div>

                            <div
                                v-if="
                                    activeRoomIndex <
                                    searchForm.rooms.length - 1
                                "
                            >
                                <button
                                    type="button"
                                    class="flex items-center gap-1 text-sm font-medium text-white/90 transition-colors hover:text-white"
                                    @click="activeRoomIndex++"
                                >
                                    Go to Room {{ activeRoomIndex + 2 }}
                                    <svg
                                        xmlns="http://www.w3.org/2000/svg"
                                        class="h-4 w-4"
                                        fill="none"
                                        viewBox="0 0 24 24"
                                        stroke="currentColor"
                                    >
                                        <path
                                            stroke-linecap="round"
                                            stroke-linejoin="round"
                                            stroke-width="2"
                                            d="M9 5l7 7-7 7"
                                        />
                                    </svg>
                                </button>
                            </div>
                        </div>

                        <div
                            v-if="searchForm.rooms.length > 1"
                            class="flex flex-wrap items-center gap-2 border-t border-[#4a5c6d] bg-[#34424f] px-6 py-2.5 text-xs"
                        >
                            <span class="mr-1 font-medium text-white/60"
                                >Rooms:</span
                            >
                            <button
                                v-for="(r, rIdx) in searchForm.rooms"
                                :key="rIdx"
                                type="button"
                                class="flex items-center gap-1.5 rounded-md px-3 py-1 transition-colors"
                                :class="
                                    activeRoomIndex === rIdx
                                        ? 'bg-white font-semibold text-[#1b1b18] shadow-sm'
                                        : 'bg-white/10 text-white/85 hover:bg-white/20 hover:text-white'
                                "
                                @click="activeRoomIndex = rIdx"
                            >
                                <span>Room {{ rIdx + 1 }}</span>
                                <span
                                    class="text-white/50"
                                    :class="{
                                        '!text-gray-400':
                                            activeRoomIndex === rIdx,
                                    }"
                                >
                                    ({{ r.adults }}
                                    {{ r.adults === 1 ? 'Adult' : 'Adults'
                                    }}<template v-if="r.children"
                                        >, {{ r.children }}
                                        {{
                                            r.children === 1
                                                ? 'Child'
                                                : 'Children'
                                        }}</template
                                    ><template v-if="r.infants"
                                        >, {{ r.infants }}
                                        {{
                                            r.infants === 1
                                                ? 'Infant'
                                                : 'Infants'
                                        }}</template
                                    >)
                                </span>
                                <span
                                    v-if="roomSelections[rIdx]"
                                    class="font-medium text-green-400"
                                    :class="{
                                        '!text-green-700':
                                            activeRoomIndex === rIdx,
                                    }"
                                >
                                    ✓ {{ roomSelections[rIdx]!.room.name }} ({{
                                        roomSelections[rIdx]!.mealPlan.label
                                    }})
                                </span>
                            </button>
                        </div>
                    </div>

                    <!-- Loading Spinner when fetching rooms -->
                    <div
                        v-if="isFetchingRooms"
                        class="flex flex-col items-center justify-center rounded-lg border border-[#e3e3e0] bg-white px-6 py-16 text-center shadow-sm"
                    >
                        <svg
                            class="h-10 w-10 animate-spin text-[#4a7c59]"
                            xmlns="http://www.w3.org/2000/svg"
                            fill="none"
                            viewBox="0 0 24 24"
                        >
                            <circle
                                class="opacity-25"
                                cx="12"
                                cy="12"
                                r="10"
                                stroke="currentColor"
                                stroke-width="4"
                            ></circle>
                            <path
                                class="opacity-75"
                                fill="currentColor"
                                d="M4 12a8 8 0 018-8v8H4z"
                            ></path>
                        </svg>
                        <p class="mt-4 text-base font-semibold text-[#1b1b18]">
                            Fetching available rooms...
                        </p>
                        <p class="mt-1 text-sm text-[#706f6c]">
                            Please wait while we update room rates and
                            availability.
                        </p>
                    </div>

                    <div
                        v-else-if="
                            roomCategories.length &&
                            visibleRoomCategories.length === 0
                        "
                        class="rounded-lg border border-[#e3e3e0] bg-white p-8 text-center text-[#706f6c]"
                    >
                        <p class="text-base font-semibold text-[#1b1b18]">
                            No rooms available for the selected dates
                        </p>
                        <p class="mt-1 text-sm">
                            All rooms are sold out for these dates. Please try
                            selecting different dates or adjusting your room and
                            guest selection.
                        </p>
                        <ul
                            v-if="restrictedRoomCategories.length"
                            class="mt-3 text-sm"
                        >
                            <li
                                v-for="room in restrictedRoomCategories"
                                :key="room.id"
                            >
                                {{ room.name }}: {{ room.restriction }}
                            </li>
                        </ul>

                        <div
                            class="mx-auto mt-6 max-w-md rounded-lg border border-[#e3e3e0] bg-[#fafaf9] p-4 text-left"
                            data-test="waitlist"
                        >
                            <p class="text-sm font-semibold text-[#1b1b18]">
                                Join the waitlist
                            </p>
                            <p
                                v-if="waitlistState.status === 'done'"
                                class="mt-2 text-sm text-green-700"
                            >
                                {{ waitlistState.message }}
                            </p>
                            <form
                                v-else
                                class="mt-2 flex flex-col gap-2"
                                @submit.prevent="joinWaitlist"
                            >
                                <p class="text-xs text-[#706f6c]">
                                    Leave your details and we'll get in touch if
                                    rooms free up for these dates.
                                </p>
                                <input
                                    v-model="waitlistForm.name"
                                    type="text"
                                    required
                                    placeholder="Your name"
                                    aria-label="Your name"
                                    class="rounded-md border border-[#e3e3e0] bg-white px-3 py-2 text-sm"
                                />
                                <input
                                    v-model="waitlistForm.phone"
                                    type="tel"
                                    required
                                    placeholder="Phone number"
                                    aria-label="Phone number"
                                    class="rounded-md border border-[#e3e3e0] bg-white px-3 py-2 text-sm"
                                />
                                <input
                                    v-model="waitlistForm.email"
                                    type="email"
                                    placeholder="E-mail (optional)"
                                    aria-label="E-mail"
                                    class="rounded-md border border-[#e3e3e0] bg-white px-3 py-2 text-sm"
                                />
                                <p
                                    v-if="waitlistState.status === 'error'"
                                    class="text-xs text-red-600"
                                >
                                    {{ waitlistState.message }}
                                </p>
                                <button
                                    type="submit"
                                    :disabled="
                                        waitlistState.status === 'sending'
                                    "
                                    class="rounded-md bg-[#1b1b18] px-4 py-2 text-sm font-medium text-white disabled:opacity-60"
                                >
                                    {{
                                        waitlistState.status === 'sending'
                                            ? 'Adding…'
                                            : 'Notify me if rooms open up'
                                    }}
                                </button>
                            </form>
                        </div>
                    </div>

                    <template v-else>
                        <div
                            v-if="restrictedRoomCategories.length"
                            class="mb-3 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900"
                        >
                            <p
                                v-for="room in restrictedRoomCategories"
                                :key="room.id"
                            >
                                {{ room.name }} can't be booked for these dates:
                                {{ room.restriction }}
                            </p>
                        </div>
                        <div
                            v-for="room in visibleRoomCategories"
                            :key="room.id"
                            class="overflow-hidden rounded-lg border border-[#e3e3e0] bg-white"
                        >
                            <div class="flex flex-col gap-4 p-4 sm:flex-row">
                                <!-- Room Image Area -->
                                <div class="w-full shrink-0 sm:w-60">
                                    <!-- Multiple Images Layout (matching screenshot) -->
                                    <template v-if="room.images.length > 1">
                                        <div
                                            class="group relative h-40 w-full cursor-pointer overflow-hidden rounded-md bg-[#1b1b18]"
                                            @click="
                                                openRoomDetails(
                                                    room,
                                                    'images',
                                                    getActiveRoomImageIndex(
                                                        room.id,
                                                        room.images.length,
                                                    ),
                                                )
                                            "
                                        >
                                            <img
                                                :src="
                                                    room.images[
                                                        getActiveRoomImageIndex(
                                                            room.id,
                                                            room.images.length,
                                                        )
                                                    ].url
                                                "
                                                :alt="room.name"
                                                class="h-full w-full object-cover transition-transform duration-300 group-hover:scale-105"
                                            />

                                            <!-- Prev / Next Chevron Arrows on Main Image -->
                                            <button
                                                type="button"
                                                class="absolute top-1/2 left-1.5 flex h-7 w-7 -translate-y-1/2 items-center justify-center rounded-full bg-black/50 text-white transition hover:bg-black/80 focus:outline-none"
                                                aria-label="Previous image"
                                                @click.stop="
                                                    prevRoomImage(
                                                        room.id,
                                                        room.images.length,
                                                    )
                                                "
                                            >
                                                <svg
                                                    class="h-4 w-4"
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
                                            </button>
                                            <button
                                                type="button"
                                                class="absolute top-1/2 right-1.5 flex h-7 w-7 -translate-y-1/2 items-center justify-center rounded-full bg-black/50 text-white transition hover:bg-black/80 focus:outline-none"
                                                aria-label="Next image"
                                                @click.stop="
                                                    nextRoomImage(
                                                        room.id,
                                                        room.images.length,
                                                    )
                                                "
                                            >
                                                <svg
                                                    class="h-4 w-4"
                                                    fill="none"
                                                    viewBox="0 0 24 24"
                                                    stroke="currentColor"
                                                >
                                                    <path
                                                        stroke-linecap="round"
                                                        stroke-linejoin="round"
                                                        stroke-width="2.5"
                                                        d="M9 5l7 7-7 7"
                                                    />
                                                </svg>
                                            </button>
                                        </div>

                                        <!-- Thumbnails Row Directly Below -->
                                        <div
                                            class="no-scrollbar mt-1.5 flex items-center gap-1.5 overflow-x-auto"
                                        >
                                            <div
                                                v-for="(
                                                    img, idx
                                                ) in room.images"
                                                :key="img.id"
                                                class="shrink-0 cursor-pointer overflow-hidden rounded border transition-all"
                                                :class="
                                                    getActiveRoomImageIndex(
                                                        room.id,
                                                        room.images.length,
                                                    ) === idx
                                                        ? 'border-[var(--be-theme)] opacity-100 ring-1 ring-[var(--be-theme)]'
                                                        : 'border-[#e3e3e0] opacity-70 hover:opacity-100'
                                                "
                                                @click.stop="
                                                    setRoomImage(room.id, idx)
                                                "
                                            >
                                                <img
                                                    :src="img.url"
                                                    :alt="room.name"
                                                    class="h-9 w-11 object-cover"
                                                />
                                            </div>
                                        </div>
                                    </template>

                                    <!-- Single Image Layout -->
                                    <div
                                        v-else-if="room.images.length === 1"
                                        class="group relative h-40 w-full cursor-pointer overflow-hidden rounded-md bg-[#1b1b18]"
                                        @click="
                                            openRoomDetails(room, 'images', 0)
                                        "
                                    >
                                        <img
                                            :src="room.images[0].url"
                                            :alt="room.name"
                                            class="h-full w-full object-cover transition-transform duration-300 group-hover:scale-105"
                                        />
                                    </div>

                                    <!-- No Image Placeholder -->
                                    <div
                                        v-else
                                        class="flex h-40 w-full items-center justify-center rounded-md bg-[#f5f5f4] text-sm text-[#A1A09A]"
                                    >
                                        No image
                                    </div>
                                </div>

                                <div class="flex-1">
                                    <h2
                                        class="font-display text-lg font-semibold text-[#1b1b18]"
                                    >
                                        {{ room.name }}
                                    </h2>
                                    <div
                                        class="mt-2 flex flex-wrap gap-4 text-sm text-[#706f6c]"
                                    >
                                        <span
                                            >Max Occupancy:
                                            {{
                                                room.max_adults +
                                                room.max_children
                                            }}</span
                                        >
                                        <span
                                            >Adults: {{ room.max_adults }}</span
                                        >
                                        <span
                                            >Children:
                                            {{ room.max_children }}</span
                                        >
                                    </div>
                                    <p
                                        v-if="room.bed_types.length"
                                        class="mt-1 text-sm text-[#706f6c]"
                                    >
                                        Bed Type:
                                        <span
                                            v-for="(
                                                bed, index
                                            ) in room.bed_types"
                                            :key="bed.name"
                                        >
                                            {{ bed.name }} - {{ bed.quantity
                                            }}<span
                                                v-if="
                                                    index <
                                                    room.bed_types.length - 1
                                                "
                                                >,
                                            </span>
                                        </span>
                                    </p>
                                    <div class="mt-1">
                                        <button
                                            type="button"
                                            class="text-sm font-medium text-[var(--be-theme)] hover:underline"
                                            @click="
                                                openRoomDetails(
                                                    room,
                                                    'room_details',
                                                )
                                            "
                                        >
                                            More
                                        </button>
                                    </div>
                                    <p
                                        v-if="room.no_rate"
                                        class="mt-1 text-sm text-[#b45309]"
                                        data-test="no-rate"
                                    >
                                        Not available for these dates
                                    </p>
                                    <p
                                        v-else
                                        class="mt-1 text-sm text-green-700"
                                    >
                                        {{ remainingAvailable(room) }} room(s)
                                        available
                                    </p>
                                </div>
                            </div>

                            <div
                                class="divide-y divide-[#e3e3e0] border-t border-[#e3e3e0] bg-[#fafaf9]"
                            >
                                <div
                                    v-for="mp in getMealPlanOptions(room)"
                                    :key="optionKey(mp)"
                                    class="flex flex-col gap-3 px-4 py-3 sm:flex-row sm:items-center sm:justify-between"
                                >
                                    <div>
                                        <p class="font-medium text-[#1b1b18]">
                                            {{ mp.label }}
                                            <span
                                                v-if="mp.package_id"
                                                class="ml-1 rounded bg-violet-100 px-1.5 py-0.5 text-[10px] font-semibold tracking-wide text-violet-800 uppercase"
                                                >Package</span
                                            >
                                        </p>
                                        <p
                                            v-if="mp.inclusions"
                                            class="text-xs whitespace-pre-line text-[#4a4a45]"
                                        >
                                            {{ mp.inclusions }}
                                        </p>
                                        <p class="text-xs text-[#706f6c]">
                                            Ideal For
                                            {{ room.ideal_adults }} Adults |
                                            {{ room.ideal_children }} Children
                                        </p>
                                    </div>
                                    <div class="flex items-center gap-4">
                                        <div class="text-right">
                                            <p
                                                class="font-display text-lg font-semibold text-[#1b1b18]"
                                            >
                                                {{
                                                    formatCurrency(
                                                        mp.nightly_price,
                                                    )
                                                }}
                                            </p>
                                            <p class="text-xs text-[#706f6c]">
                                                Per night (incl. taxes)
                                            </p>
                                        </div>
                                        <button
                                            v-if="
                                                !isSelectedForCurrentRoom(
                                                    room.id,
                                                    optionKey(mp),
                                                )
                                            "
                                            :data-test="`add-${room.id}-${optionKey(mp)}`"
                                            type="button"
                                            :disabled="
                                                remainingAvailable(room) === 0
                                            "
                                            class="rounded-md border border-black bg-[var(--be-theme)] px-5 py-2 text-sm font-semibold text-white hover:bg-[#d92a02] disabled:cursor-not-allowed disabled:opacity-50"
                                            @click="selectRoomOption(room, mp)"
                                        >
                                            ADD
                                        </button>
                                        <div
                                            v-else
                                            class="flex items-center gap-2"
                                        >
                                            <span
                                                class="rounded-md border border-green-700 bg-green-50 px-3 py-1.5 text-xs font-semibold text-green-700"
                                            >
                                                SELECTED (ROOM
                                                {{ activeRoomIndex + 1 }})
                                            </span>
                                            <button
                                                type="button"
                                                class="rounded-md border border-[#e3e3e0] bg-white px-4 py-2 text-sm font-semibold text-[#1b1b18] hover:bg-[#f5f5f4]"
                                                @click="
                                                    removeRoomSelection(
                                                        activeRoomIndex,
                                                    )
                                                "
                                            >
                                                REMOVE
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </template>
                </template>

                <template v-else-if="step === 'guest-details'">
                    <div class="space-y-4">
                        <div
                            v-if="quoteError"
                            class="rounded-sm border border-red-300 bg-red-50 p-4 text-xs text-red-800"
                        >
                            {{ quoteError }}
                        </div>

                        <!-- Error Banner if payment failed -->
                        <div
                            v-if="paymentError"
                            class="flex items-start gap-2.5 rounded-sm border border-red-300 bg-red-50 p-4 text-xs text-red-800 shadow-sm"
                        >
                            <svg
                                class="mt-0.5 h-4 w-4 shrink-0 text-red-600"
                                fill="none"
                                viewBox="0 0 24 24"
                                stroke="currentColor"
                            >
                                <path
                                    stroke-linecap="round"
                                    stroke-linejoin="round"
                                    stroke-width="2"
                                    d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"
                                />
                            </svg>
                            <div class="flex-1">
                                <p class="font-semibold text-red-900">
                                    Payment Unsuccessful
                                </p>
                                <p class="mt-0.5">{{ paymentError }}</p>
                            </div>
                        </div>

                        <!-- Guest Contact Info -->
                        <div
                            class="rounded-sm border border-[#d1d5db] bg-white p-5"
                        >
                            <h2
                                class="font-display text-base font-semibold text-[#1b1b18]"
                            >
                                Guest Details
                            </h2>
                            <div
                                class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2"
                            >
                                <label
                                    class="flex flex-col gap-1 text-xs text-[#706f6c]"
                                >
                                    <span
                                        >Full Name<span
                                            class="ml-0.5 font-bold text-red-600"
                                            >*</span
                                        ></span
                                    >
                                    <input
                                        v-model="guestDetails.name"
                                        type="text"
                                        placeholder="Enter primary guest full name"
                                        class="rounded border px-3 py-2 text-sm text-[#1b1b18] focus:outline-none"
                                        :class="
                                            guestErrors.name
                                                ? 'border-red-500 bg-red-50/30 focus:border-red-500'
                                                : 'border-[#d1d5db] focus:border-[#4a7c59]'
                                        "
                                    />
                                    <span
                                        v-if="guestErrors.name"
                                        class="text-[11px] font-medium text-red-600"
                                        >{{ guestErrors.name }}</span
                                    >
                                </label>
                                <label
                                    class="flex flex-col gap-1 text-xs text-[#706f6c]"
                                >
                                    <span
                                        >Mobile Number<span
                                            class="ml-0.5 font-bold text-red-600"
                                            >*</span
                                        ></span
                                    >
                                    <input
                                        v-model="guestDetails.phone"
                                        type="tel"
                                        placeholder="e.g. +91 9876543210"
                                        class="rounded border px-3 py-2 text-sm text-[#1b1b18] focus:outline-none"
                                        :class="
                                            guestErrors.phone
                                                ? 'border-red-500 bg-red-50/30 focus:border-red-500'
                                                : 'border-[#d1d5db] focus:border-[#4a7c59]'
                                        "
                                    />
                                    <span
                                        v-if="guestErrors.phone"
                                        class="text-[11px] font-medium text-red-600"
                                        >{{ guestErrors.phone }}</span
                                    >
                                </label>
                                <label
                                    class="col-span-full flex flex-col gap-1 text-xs text-[#706f6c]"
                                >
                                    <span
                                        >E-mail<span
                                            class="ml-0.5 font-bold text-red-600"
                                            >*</span
                                        ></span
                                    >
                                    <input
                                        v-model="guestDetails.email"
                                        type="email"
                                        placeholder="booking.confirmation@example.com"
                                        class="rounded border px-3 py-2 text-sm text-[#1b1b18] focus:outline-none"
                                        :class="
                                            guestErrors.email
                                                ? 'border-red-500 bg-red-50/30 focus:border-red-500'
                                                : 'border-[#d1d5db] focus:border-[#4a7c59]'
                                        "
                                    />
                                    <span
                                        v-if="guestErrors.email"
                                        class="text-[11px] font-medium text-red-600"
                                        >{{ guestErrors.email }}</span
                                    >
                                </label>
                            </div>
                        </div>

                        <!-- Enter GST Details (Optional) -->
                        <div
                            class="rounded-sm border border-[#d1d5db] bg-white p-5"
                        >
                            <label
                                class="flex cursor-pointer items-center gap-2 text-sm font-medium text-[#1b1b18]"
                            >
                                <input
                                    v-model="enterGstDetails"
                                    type="checkbox"
                                    class="rounded text-[#4a7c59] focus:ring-[#4a7c59]"
                                />
                                <span
                                    >Enter GST Details
                                    <span
                                        class="text-xs font-normal text-[#706f6c]"
                                        >(Optional)</span
                                    ></span
                                >
                            </label>
                            <div
                                v-if="enterGstDetails"
                                class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2"
                            >
                                <label
                                    class="flex flex-col gap-1 text-xs text-[#706f6c]"
                                >
                                    GST Number
                                    <input
                                        v-model="gstForm.gst_number"
                                        type="text"
                                        placeholder="e.g. 29ABCDE1234F1Z5"
                                        class="rounded border border-[#d1d5db] px-3 py-2 text-sm text-[#1b1b18] focus:border-[#4a7c59] focus:outline-none"
                                    />
                                </label>
                                <label
                                    class="flex flex-col gap-1 text-xs text-[#706f6c]"
                                >
                                    Company Name
                                    <input
                                        v-model="gstForm.company_name"
                                        type="text"
                                        placeholder="Company Name"
                                        class="rounded border border-[#d1d5db] px-3 py-2 text-sm text-[#1b1b18] focus:border-[#4a7c59] focus:outline-none"
                                    />
                                </label>
                            </div>
                        </div>

                        <!-- Add-on services -->
                        <div
                            v-if="addons?.length"
                            class="rounded-sm border border-[#d1d5db] bg-white p-5"
                        >
                            <h3 class="text-sm font-semibold text-[#1b1b18]">
                                Add to your stay
                            </h3>
                            <div class="mt-3 divide-y divide-[#e5e7eb]">
                                <div
                                    v-for="addon in addons"
                                    :key="addon.id"
                                    class="flex flex-wrap items-center gap-3 py-2.5"
                                >
                                    <label
                                        class="flex min-w-0 flex-1 cursor-pointer items-start gap-3"
                                    >
                                        <input
                                            type="checkbox"
                                            :data-test="`addon-${addon.id}`"
                                            class="mt-0.5 text-[#4a7c59] focus:ring-[#4a7c59]"
                                            :checked="
                                                (addonQuantities[addon.id] ??
                                                    0) > 0
                                            "
                                            @change="toggleAddon(addon.id)"
                                        />
                                        <span class="min-w-0">
                                            <span
                                                class="block text-sm font-medium text-[#1b1b18]"
                                                >{{ addon.name }}</span
                                            >
                                            <span
                                                v-if="addon.description"
                                                class="block text-xs text-[#706f6c]"
                                                >{{ addon.description }}</span
                                            >
                                            <span
                                                class="block text-xs text-[#706f6c]"
                                                >{{
                                                    formatCurrency(addon.price)
                                                }}
                                                {{
                                                    addon.pricing_label.toLowerCase()
                                                }}<template
                                                    v-if="addon.tax_rate"
                                                >
                                                    + {{ addon.tax_rate }}%
                                                    tax</template
                                                ></span
                                            >
                                        </span>
                                    </label>
                                    <input
                                        v-if="
                                            (addonQuantities[addon.id] ?? 0) > 0
                                        "
                                        v-model.number="
                                            addonQuantities[addon.id]
                                        "
                                        type="number"
                                        min="1"
                                        max="99"
                                        :aria-label="`Quantity of ${addon.name}`"
                                        class="w-16 rounded border border-[#d1d5db] px-2 py-1 text-sm focus:border-[#4a7c59] focus:outline-none"
                                    />
                                </div>
                            </div>
                        </div>

                        <!-- Do you have any special request? -->
                        <div
                            class="rounded-sm border border-[#d1d5db] bg-white p-5"
                        >
                            <h3 class="text-sm font-semibold text-[#1b1b18]">
                                Do you have any special request?
                            </h3>
                            <textarea
                                v-model="guestDetails.special_requests"
                                rows="3"
                                placeholder="Enter your special request"
                                class="mt-3 w-full rounded border border-[#d1d5db] px-3 py-2 text-sm text-[#1b1b18] placeholder:text-gray-400 focus:border-[#4a7c59] focus:outline-none"
                            ></textarea>
                        </div>

                        <!-- Payment -->
                        <div
                            class="rounded-sm border border-[#d1d5db] bg-white p-5"
                        >
                            <h3 class="text-sm font-semibold text-[#1b1b18]">
                                Payment Options
                            </h3>
                            <div class="mt-3 space-y-3">
                                <!-- If Partial Payment is enabled in settings -->
                                <template v-if="allowPartialPayment">
                                    <label
                                        class="flex cursor-pointer items-start gap-3 rounded border p-3 transition-colors"
                                        :class="
                                            partialPayment
                                                ? 'border-[#4a7c59] bg-[#f2f7f4]'
                                                : 'border-[#e3e3e0] bg-white hover:bg-[#fafaf9]'
                                        "
                                    >
                                        <input
                                            type="radio"
                                            name="payment_choice"
                                            :value="true"
                                            v-model="partialPayment"
                                            class="mt-0.5 text-[#4a7c59] focus:ring-[#4a7c59]"
                                        />
                                        <div class="flex-1">
                                            <div
                                                class="flex items-center justify-between"
                                            >
                                                <span
                                                    class="text-sm font-semibold text-[#1b1b18]"
                                                    >Partial Payment ({{
                                                        partialPaymentPercentage
                                                    }}%)</span
                                                >
                                                <span
                                                    class="text-sm font-semibold text-[#4a7c59]"
                                                    >{{
                                                        formatCurrency(
                                                            partialPayNowAmount,
                                                        )
                                                    }}</span
                                                >
                                            </div>
                                            <p
                                                class="mt-0.5 text-xs text-[#706f6c]"
                                            >
                                                Pay
                                                {{
                                                    formatCurrency(
                                                        partialPayNowAmount,
                                                    )
                                                }}
                                                now to confirm your booking.
                                                Remaining
                                                {{
                                                    formatCurrency(
                                                        partialRemainingAmount,
                                                    )
                                                }}
                                                will be paid upon check-in at
                                                the hotel.
                                            </p>
                                        </div>
                                    </label>

                                    <label
                                        class="flex cursor-pointer items-start gap-3 rounded border p-3 transition-colors"
                                        :class="
                                            !partialPayment
                                                ? 'border-[#4a7c59] bg-[#f2f7f4]'
                                                : 'border-[#e3e3e0] bg-white hover:bg-[#fafaf9]'
                                        "
                                    >
                                        <input
                                            type="radio"
                                            name="payment_choice"
                                            :value="false"
                                            v-model="partialPayment"
                                            class="mt-0.5 text-[#4a7c59] focus:ring-[#4a7c59]"
                                        />
                                        <div class="flex-1">
                                            <div
                                                class="flex items-center justify-between"
                                            >
                                                <span
                                                    class="text-sm font-semibold text-[#1b1b18]"
                                                    >Full Payment (100%)</span
                                                >
                                                <div class="text-right">
                                                    <span
                                                        v-if="
                                                            totalDiscountAmount >
                                                            0
                                                        "
                                                        class="mr-2 text-xs font-normal text-[#706f6c] line-through"
                                                        >{{
                                                            formatCurrency(
                                                                originalTotalPayable,
                                                            )
                                                        }}</span
                                                    >
                                                    <span
                                                        class="text-sm font-semibold text-[#1b1b18]"
                                                        >{{
                                                            formatCurrency(
                                                                totalPayable,
                                                            )
                                                        }}</span
                                                    >
                                                </div>
                                            </div>
                                            <p
                                                class="mt-0.5 text-xs text-[#706f6c]"
                                            >
                                                Pay the full amount of
                                                {{
                                                    formatCurrency(totalPayable)
                                                }}
                                                online now.<span
                                                    v-if="
                                                        totalDiscountAmount > 0
                                                    "
                                                    class="ml-1 font-medium text-[#4a7c59]"
                                                    >You save
                                                    {{
                                                        formatCurrency(
                                                            totalSavedAmount,
                                                        )
                                                    }}!</span
                                                >
                                            </p>
                                        </div>
                                    </label>
                                </template>

                                <!-- If Partial Payment is disabled -->
                                <template v-else>
                                    <label
                                        class="flex cursor-pointer items-start gap-3 rounded border border-[#4a7c59] bg-[#f2f7f4] p-3"
                                    >
                                        <input
                                            type="radio"
                                            name="payment_choice"
                                            :value="false"
                                            v-model="partialPayment"
                                            class="mt-0.5 text-[#4a7c59] focus:ring-[#4a7c59]"
                                            checked
                                        />
                                        <div class="flex-1">
                                            <div
                                                class="flex items-center justify-between"
                                            >
                                                <span
                                                    class="text-sm font-semibold text-[#1b1b18]"
                                                    >Full Payment</span
                                                >
                                                <div class="text-right">
                                                    <span
                                                        v-if="
                                                            totalDiscountAmount >
                                                            0
                                                        "
                                                        class="mr-2 text-xs font-normal text-[#706f6c] line-through"
                                                        >{{
                                                            formatCurrency(
                                                                originalTotalPayable,
                                                            )
                                                        }}</span
                                                    >
                                                    <span
                                                        class="text-sm font-semibold text-[#1b1b18]"
                                                        >{{
                                                            formatCurrency(
                                                                totalPayable,
                                                            )
                                                        }}</span
                                                    >
                                                </div>
                                            </div>
                                            <p
                                                class="mt-0.5 text-xs text-[#706f6c]"
                                            >
                                                Pay
                                                {{
                                                    formatCurrency(totalPayable)
                                                }}
                                                now to confirm your
                                                reservation.<span
                                                    v-if="
                                                        totalDiscountAmount > 0
                                                    "
                                                    class="ml-1 font-medium text-[#4a7c59]"
                                                    >You save
                                                    {{
                                                        formatCurrency(
                                                            totalSavedAmount,
                                                        )
                                                    }}!</span
                                                >
                                            </p>
                                        </div>
                                    </label>
                                </template>

                                <!-- Select Payment Gateway -->
                                <div
                                    class="mt-4 border-t border-[#e3e3e0] pt-4"
                                >
                                    <div
                                        class="mb-3 flex items-center justify-between"
                                    >
                                        <span
                                            class="text-xs font-semibold tracking-wider text-[#1b1b18] uppercase"
                                            >Select Payment Gateway</span
                                        >
                                        <span
                                            class="inline-flex items-center gap-1 text-[11px] font-medium text-[#4a7c59]"
                                        >
                                            <svg
                                                class="h-3.5 w-3.5"
                                                fill="none"
                                                viewBox="0 0 24 24"
                                                stroke="currentColor"
                                            >
                                                <path
                                                    stroke-linecap="round"
                                                    stroke-linejoin="round"
                                                    stroke-width="2"
                                                    d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"
                                                />
                                            </svg>
                                            100% Secure Checkout
                                        </span>
                                    </div>

                                    <div
                                        class="grid grid-cols-1 gap-3"
                                        :class="
                                            testGatewayEnabled
                                                ? 'sm:grid-cols-3'
                                                : 'sm:grid-cols-2'
                                        "
                                    >
                                        <!-- Razorpay Option -->
                                        <label
                                            class="relative flex cursor-pointer flex-col justify-between rounded-lg border-2 p-3.5 transition-all"
                                            :class="
                                                selectedGateway === 'razorpay'
                                                    ? 'border-[#4a7c59] bg-[#f2f7f4] shadow-xs'
                                                    : 'border-[#e3e3e0] bg-white hover:border-[#cbd5e1] hover:bg-[#fafaf9]'
                                            "
                                        >
                                            <div
                                                class="flex items-start justify-between"
                                            >
                                                <div
                                                    class="flex items-center gap-2.5"
                                                >
                                                    <input
                                                        type="radio"
                                                        name="selected_gateway"
                                                        value="razorpay"
                                                        v-model="
                                                            selectedGateway
                                                        "
                                                        class="text-[#4a7c59] focus:ring-[#4a7c59]"
                                                    />
                                                    <div>
                                                        <div
                                                            class="flex items-center gap-1.5"
                                                        >
                                                            <span
                                                                class="text-sm font-bold text-[#1b1b18]"
                                                                >Razorpay</span
                                                            >
                                                            <span
                                                                class="rounded bg-[#0c2340] px-1.5 py-0.5 text-[10px] font-semibold text-[#528ff0]"
                                                                >PAY</span
                                                            >
                                                        </div>
                                                    </div>
                                                </div>
                                                <span
                                                    class="rounded-full bg-emerald-100 px-2 py-0.5 text-[10px] font-semibold text-emerald-800"
                                                    >Popular</span
                                                >
                                            </div>
                                            <div
                                                class="mt-2.5 border-t border-[#e3e3e0]/60 pt-2 text-[11px] text-[#706f6c]"
                                            >
                                                UPI (GPay, PhonePe, Paytm),
                                                Credit &amp; Debit Cards,
                                                NetBanking, Wallets
                                            </div>
                                        </label>

                                        <!-- Cashfree Option -->
                                        <label
                                            class="relative flex cursor-pointer flex-col justify-between rounded-lg border-2 p-3.5 transition-all"
                                            :class="
                                                selectedGateway === 'cashfree'
                                                    ? 'border-[#4a7c59] bg-[#f2f7f4] shadow-xs'
                                                    : 'border-[#e3e3e0] bg-white hover:border-[#cbd5e1] hover:bg-[#fafaf9]'
                                            "
                                        >
                                            <div
                                                class="flex items-start justify-between"
                                            >
                                                <div
                                                    class="flex items-center gap-2.5"
                                                >
                                                    <input
                                                        type="radio"
                                                        name="selected_gateway"
                                                        value="cashfree"
                                                        v-model="
                                                            selectedGateway
                                                        "
                                                        class="text-[#4a7c59] focus:ring-[#4a7c59]"
                                                    />
                                                    <div>
                                                        <div
                                                            class="flex items-center gap-1.5"
                                                        >
                                                            <span
                                                                class="text-sm font-bold text-[#1b1b18]"
                                                                >Cashfree</span
                                                            >
                                                            <span
                                                                class="rounded bg-[#2a0845] px-1.5 py-0.5 text-[10px] font-semibold text-[#ffbe00]"
                                                                >PAYMENTS</span
                                                            >
                                                        </div>
                                                    </div>
                                                </div>
                                                <span
                                                    class="rounded-full bg-blue-100 px-2 py-0.5 text-[10px] font-semibold text-blue-800"
                                                    >Fast Checkout</span
                                                >
                                            </div>
                                            <div
                                                class="mt-2.5 border-t border-[#e3e3e0]/60 pt-2 text-[11px] text-[#706f6c]"
                                            >
                                                UPI QR, Instant NetBanking (60+
                                                Banks), All Major Cards,
                                                PayLater
                                            </div>
                                        </label>

                                        <!-- Test Gateway Option (Only rendered when ENABLE_TEST_PAYMENT_GATEWAY is true in .env) -->
                                        <label
                                            v-if="testGatewayEnabled"
                                            class="relative flex cursor-pointer flex-col justify-between rounded-lg border-2 p-3.5 transition-all"
                                            :class="
                                                selectedGateway === 'test'
                                                    ? 'border-[#4a7c59] bg-[#f2f7f4] shadow-xs'
                                                    : 'border-[#e3e3e0] bg-white hover:border-[#cbd5e1] hover:bg-[#fafaf9]'
                                            "
                                        >
                                            <div
                                                class="flex items-start justify-between"
                                            >
                                                <div
                                                    class="flex items-center gap-2.5"
                                                >
                                                    <input
                                                        type="radio"
                                                        name="selected_gateway"
                                                        value="test"
                                                        v-model="
                                                            selectedGateway
                                                        "
                                                        class="text-[#4a7c59] focus:ring-[#4a7c59]"
                                                    />
                                                    <div>
                                                        <div
                                                            class="flex items-center gap-1.5"
                                                        >
                                                            <span
                                                                class="text-sm font-bold text-[#1b1b18]"
                                                                >Test
                                                                Gateway</span
                                                            >
                                                            <span
                                                                class="rounded bg-amber-700 px-1.5 py-0.5 text-[10px] font-semibold text-white"
                                                                >ENV</span
                                                            >
                                                        </div>
                                                    </div>
                                                </div>
                                                <span
                                                    class="rounded-full bg-amber-100 px-2 py-0.5 text-[10px] font-semibold text-amber-800"
                                                    >Simulator</span
                                                >
                                            </div>
                                            <div
                                                class="mt-2.5 border-t border-[#e3e3e0]/60 pt-2 text-[11px] text-[#706f6c]"
                                            >
                                                Instant simulated approval for
                                                local testing &amp; staging
                                                without API keys
                                            </div>
                                        </label>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <button
                            type="button"
                            class="text-sm font-medium text-[#4a7c59] underline underline-offset-4 hover:text-[#386749]"
                            @click="backToRooms"
                        >
                            ← Back to room selection
                        </button>
                    </div>
                </template>

                <template v-else-if="step === 'confirmed'">
                    <div class="space-y-4">
                        <!-- Confirmation Header Card -->
                        <div
                            class="rounded-sm border border-emerald-200 bg-emerald-50 p-6 text-center sm:p-8"
                        >
                            <div
                                class="mx-auto flex h-14 w-14 items-center justify-center rounded-full bg-emerald-100 text-emerald-600 shadow-inner"
                            >
                                <svg
                                    class="h-8 w-8"
                                    fill="none"
                                    viewBox="0 0 24 24"
                                    stroke="currentColor"
                                    stroke-width="2.5"
                                >
                                    <path
                                        stroke-linecap="round"
                                        stroke-linejoin="round"
                                        d="M5 13l4 4L19 7"
                                    />
                                </svg>
                            </div>
                            <h2
                                class="font-display mt-3 text-xl font-bold text-emerald-950 sm:text-2xl"
                            >
                                Reservation Confirmed!
                            </h2>
                            <p class="mt-1 text-xs text-emerald-800 sm:text-sm">
                                Thank you for booking with {{ property.name }}.
                            </p>
                            <div
                                class="mt-4 inline-flex items-center gap-2 rounded-full border border-emerald-300 bg-white px-4 py-1.5 shadow-xs"
                            >
                                <span class="text-xs font-medium text-[#706f6c]"
                                    >Booking Reference:</span
                                >
                                <span
                                    class="font-mono text-sm font-bold text-[#1b1b18]"
                                    >{{
                                        confirmedBooking?.booking_ref_id
                                    }}</span
                                >
                            </div>
                            <p class="mt-3 text-xs text-emerald-700">
                                A confirmation voucher has been recorded for
                                <strong class="text-emerald-900">{{
                                    confirmedBooking?.guest_email
                                }}</strong
                                >.
                            </p>
                        </div>

                        <!-- Booking Details Breakdown Card -->
                        <div
                            class="space-y-4 rounded-sm border border-[#d1d5db] bg-white p-5"
                        >
                            <div
                                class="flex items-center justify-between border-b border-[#e3e3e0] pb-3"
                            >
                                <h3
                                    class="font-display text-sm font-bold tracking-wide text-[#1b1b18] uppercase"
                                >
                                    Stay Details
                                </h3>
                                <span
                                    class="rounded border border-[#d2e4d8] bg-[#f2f7f4] px-2.5 py-0.5 text-xs font-semibold text-[#4a7c59]"
                                >
                                    Confirmed
                                </span>
                            </div>

                            <div
                                class="grid grid-cols-1 gap-4 text-xs sm:grid-cols-2"
                            >
                                <div>
                                    <span class="text-[#706f6c]">Hotel</span>
                                    <p
                                        class="mt-0.5 text-sm font-semibold text-[#1b1b18]"
                                    >
                                        {{ property.name }}
                                    </p>
                                    <p class="mt-0.5 text-[#706f6c]">
                                        {{ property.address }}
                                    </p>
                                </div>
                                <div
                                    class="grid grid-cols-2 gap-2 rounded border border-[#e3e3e0] bg-[#fafaf9] p-3"
                                >
                                    <div>
                                        <span class="text-[#706f6c]"
                                            >Check-In</span
                                        >
                                        <p
                                            class="mt-0.5 font-semibold text-[#1b1b18]"
                                        >
                                            {{ confirmedBooking?.check_in }}
                                        </p>
                                    </div>
                                    <div>
                                        <span class="text-[#706f6c]"
                                            >Check-Out</span
                                        >
                                        <p
                                            class="mt-0.5 font-semibold text-[#1b1b18]"
                                        >
                                            {{ confirmedBooking?.check_out }}
                                        </p>
                                    </div>
                                </div>
                            </div>

                            <div class="border-t border-[#e3e3e0] pt-4">
                                <h4
                                    class="mb-2 text-xs font-bold text-[#1b1b18] uppercase"
                                >
                                    Guest Information
                                </h4>
                                <div
                                    class="grid grid-cols-1 gap-3 text-xs sm:grid-cols-3"
                                >
                                    <div
                                        class="rounded border border-[#e3e3e0] bg-[#fafaf9] p-2.5"
                                    >
                                        <span class="text-[#706f6c]"
                                            >Name:</span
                                        >
                                        <p
                                            class="mt-0.5 font-medium text-[#1b1b18]"
                                        >
                                            {{ confirmedBooking?.guest_name }}
                                        </p>
                                    </div>
                                    <div
                                        class="rounded border border-[#e3e3e0] bg-[#fafaf9] p-2.5"
                                    >
                                        <span class="text-[#706f6c]"
                                            >Phone:</span
                                        >
                                        <p
                                            class="mt-0.5 font-medium text-[#1b1b18]"
                                        >
                                            {{ confirmedBooking?.guest_phone }}
                                        </p>
                                    </div>
                                    <div
                                        class="rounded border border-[#e3e3e0] bg-[#fafaf9] p-2.5"
                                    >
                                        <span class="text-[#706f6c]"
                                            >Email:</span
                                        >
                                        <p
                                            class="mt-0.5 truncate font-medium text-[#1b1b18]"
                                        >
                                            {{ confirmedBooking?.guest_email }}
                                        </p>
                                    </div>
                                </div>
                            </div>

                            <div class="border-t border-[#e3e3e0] pt-4">
                                <h4
                                    class="mb-2 text-xs font-bold text-[#1b1b18] uppercase"
                                >
                                    Payment Receipt
                                </h4>
                                <div
                                    class="space-y-2 rounded-lg border border-[#e3e3e0] bg-[#fafaf9] p-3 text-xs"
                                >
                                    <div class="flex justify-between">
                                        <span class="text-[#706f6c]"
                                            >Total Reservation Cost:</span
                                        >
                                        <span
                                            class="font-semibold text-[#1b1b18]"
                                            >{{
                                                formatCurrency(
                                                    confirmedBooking?.total ??
                                                        0,
                                                )
                                            }}</span
                                        >
                                    </div>
                                    <div
                                        class="flex justify-between font-medium text-emerald-700"
                                    >
                                        <span class="flex items-center gap-1">
                                            <svg
                                                class="h-3.5 w-3.5"
                                                fill="none"
                                                viewBox="0 0 24 24"
                                                stroke="currentColor"
                                            >
                                                <path
                                                    stroke-linecap="round"
                                                    stroke-linejoin="round"
                                                    stroke-width="2"
                                                    d="M5 13l4 4L19 7"
                                                />
                                            </svg>
                                            <span
                                                >Paid Online via
                                                {{
                                                    confirmedBooking?.gateway ===
                                                    'razorpay'
                                                        ? 'Razorpay'
                                                        : confirmedBooking?.gateway ===
                                                            'cashfree'
                                                          ? 'Cashfree'
                                                          : 'Test Gateway'
                                                }}:</span
                                            >
                                        </span>
                                        <span class="font-bold">{{
                                            formatCurrency(
                                                confirmedBooking?.paid_now ?? 0,
                                            )
                                        }}</span>
                                    </div>
                                    <div
                                        v-if="
                                            (confirmedBooking?.outstanding ??
                                                0) > 0
                                        "
                                        class="flex justify-between rounded border border-amber-200 bg-amber-50 p-2 text-amber-700"
                                    >
                                        <span class="font-medium"
                                            >Due at Check-in (Hotel Front
                                            Desk):</span
                                        >
                                        <span class="font-bold">{{
                                            formatCurrency(
                                                confirmedBooking?.outstanding ??
                                                    0,
                                            )
                                        }}</span>
                                    </div>
                                    <div
                                        class="flex justify-between border-t border-[#e3e3e0] pt-2 text-[11px] text-[#706f6c]"
                                    >
                                        <span>Transaction Reference:</span>
                                        <span
                                            class="font-mono text-[#1b1b18]"
                                            >{{
                                                confirmedBooking?.transaction_id
                                            }}</span
                                        >
                                    </div>
                                </div>
                            </div>

                            <!-- Actions -->
                            <div class="flex flex-col gap-3 pt-2 sm:flex-row">
                                <a
                                    v-if="confirmedBooking?.web_checkin_url"
                                    :href="confirmedBooking.web_checkin_url"
                                    target="_blank"
                                    rel="noopener"
                                    class="flex flex-1 items-center justify-center gap-2 rounded bg-emerald-600 py-2.5 text-xs font-semibold text-white uppercase hover:bg-emerald-700"
                                    data-test="web-checkin-link"
                                >
                                    Check in online
                                </a>
                                <button
                                    type="button"
                                    class="flex flex-1 items-center justify-center gap-2 rounded bg-[#4a7c59] py-2.5 text-xs font-semibold text-white uppercase hover:bg-[#3f6d4d]"
                                    @click="printBookingReceipt"
                                >
                                    <svg
                                        class="h-4 w-4"
                                        fill="none"
                                        viewBox="0 0 24 24"
                                        stroke="currentColor"
                                    >
                                        <path
                                            stroke-linecap="round"
                                            stroke-linejoin="round"
                                            stroke-width="2"
                                            d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"
                                        />
                                    </svg>
                                    <span>Print Confirmation</span>
                                </button>
                                <button
                                    type="button"
                                    class="flex-1 rounded border border-[#e3e3e0] bg-white py-2.5 text-xs font-semibold text-[#1b1b18] uppercase hover:bg-[#fafaf9]"
                                    @click="startNewBooking"
                                >
                                    Book Another Stay
                                </button>
                            </div>
                        </div>
                    </div>
                </template>
            </div>

            <aside
                v-if="step !== 'confirmed'"
                class="no-scrollbar max-h-[calc(100vh-2rem)] space-y-2.5 self-start overflow-y-auto pr-0.5 lg:sticky lg:top-4"
            >
                <!-- Booking Summary Header -->
                <div class="flex items-center justify-between pb-0.5">
                    <h2
                        class="font-display text-base font-bold tracking-tight text-[#1b1b18]"
                    >
                        Booking Summary
                    </h2>
                </div>

                <!-- Check-In / Check-Out box -->
                <div
                    class="grid grid-cols-2 divide-x divide-[#e3e3e0] rounded-sm border border-[#d1d5db] bg-white p-3 text-xs"
                >
                    <div>
                        <p class="text-[#706f6c]">Check-In :</p>
                        <p class="mt-0.5 font-medium text-[#1b1b18]">
                            {{ formatDateWithOrdinal(search.check_in) }}
                        </p>
                    </div>
                    <div class="pl-4">
                        <p class="text-[#706f6c]">Check-Out :</p>
                        <p class="mt-0.5 font-medium text-[#1b1b18]">
                            {{ formatDateWithOrdinal(search.check_out) }}
                        </p>
                    </div>
                </div>

                <!-- Empty State if no room selected -->
                <div
                    v-if="selectedRoomsWithIndex.length === 0"
                    class="rounded-sm border border-[#d1d5db] bg-white p-4 text-center text-xs text-[#706f6c]"
                >
                    Please select a room to view booking summary
                </div>

                <!-- Room item cards -->
                <div
                    v-for="item in selectedRoomsWithIndex"
                    :key="item.index"
                    class="overflow-hidden rounded-sm border border-[#d1d5db] bg-white text-xs"
                >
                    <div class="p-3">
                        <div class="flex items-start justify-between gap-2">
                            <span
                                class="font-bold tracking-tight text-[#1b1b18] uppercase"
                            >
                                ROOM {{ item.index + 1 }} :
                                {{ item.choice.room.name }}
                            </span>
                            <span
                                v-if="
                                    appliedDiscountPercent > 0 ||
                                    (item.choice.mealPlan.rack_rate &&
                                        item.choice.mealPlan.rack_rate >
                                            item.choice.mealPlan.nightly_price)
                                "
                                class="shrink-0 text-[11px] text-[#706f6c] line-through"
                            >
                                {{
                                    formatCurrency(
                                        getRoomOriginalTotal(item.choice),
                                    )
                                }}
                            </span>
                        </div>
                        <div
                            class="mt-1 flex items-baseline justify-between gap-2"
                        >
                            <span class="text-[#555]">{{
                                item.choice.mealPlan.label
                            }}</span>
                            <span
                                class="shrink-0 text-sm font-semibold text-[#1b1b18]"
                            >
                                {{
                                    formatCurrency(getRoomNetTotal(item.choice))
                                }}
                            </span>
                        </div>
                        <div
                            v-if="appliedDiscountPercent > 0"
                            class="mt-1 flex items-center justify-between rounded border border-[#d2e4d8] bg-[#f2f7f4] px-2 py-0.5 text-[11px] text-[#4a7c59]"
                        >
                            <span>{{
                                appliedPromo?.code
                                    ? appliedPromo.code +
                                      ' (' +
                                      appliedDiscountPercent +
                                      '%)'
                                    : appliedDiscountPercent + '% Discount'
                            }}</span>
                            <span class="font-semibold"
                                >-
                                {{
                                    formatCurrency(
                                        getRoomOriginalTotal(item.choice) -
                                            getRoomNetTotal(item.choice),
                                    )
                                }}</span
                            >
                        </div>
                        <p
                            v-if="appliedDiscountPercent > 0"
                            class="mt-1 text-[11px] text-[#706f6c]"
                        >
                            Promo applied on {{ search.nights }} of
                            {{ search.nights }} nights
                        </p>
                        <p class="mt-1 text-[11px] text-[#706f6c]">
                            Adult:
                            {{ searchForm.rooms[item.index]?.adults ?? 1 }} |
                            Child:
                            {{ searchForm.rooms[item.index]?.children ?? 0 }} |
                            Infant:
                            {{ searchForm.rooms[item.index]?.infants ?? 0 }}
                        </p>
                    </div>
                    <div
                        class="flex items-center justify-between border-t border-[#e5e7eb] bg-[#fafaf9] px-3 py-2 text-[11px] font-semibold"
                    >
                        <button
                            type="button"
                            class="flex items-center gap-1 text-[#4a7c59] hover:underline"
                            @click="modifyRoom(item.index)"
                        >
                            <svg
                                class="h-3.5 w-3.5"
                                fill="none"
                                viewBox="0 0 24 24"
                                stroke="currentColor"
                                stroke-width="2.5"
                            >
                                <path
                                    stroke-linecap="round"
                                    stroke-linejoin="round"
                                    d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"
                                />
                            </svg>
                            <span>MODIFY</span>
                        </button>
                        <button
                            type="button"
                            class="flex items-center gap-1 text-[#dc2626] hover:underline"
                            @click="removeRoomSelection(item.index)"
                        >
                            <svg
                                class="h-3.5 w-3.5"
                                fill="none"
                                viewBox="0 0 24 24"
                                stroke="currentColor"
                                stroke-width="2.5"
                            >
                                <path
                                    stroke-linecap="round"
                                    stroke-linejoin="round"
                                    d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"
                                />
                            </svg>
                            <span>REMOVE ROOM</span>
                        </button>
                    </div>
                </div>

                <!-- Discount Accordion -->
                <div
                    class="overflow-hidden rounded-sm border border-[#d1d5db] bg-white text-xs"
                >
                    <button
                        type="button"
                        class="flex w-full items-center justify-between p-3 font-semibold text-[#1b1b18] hover:bg-[#fafaf9]"
                        @click="discountOpen = !discountOpen"
                    >
                        <span>Discount</span>
                        <div class="flex items-center gap-1.5 font-medium">
                            <span
                                v-if="totalDiscountAmount > 0"
                                class="rounded border border-[#d2e4d8] bg-[#f2f7f4] px-2 py-0.5 text-xs font-semibold text-[#4a7c59]"
                            >
                                - {{ formatCurrency(totalDiscountAmount) }}
                            </span>
                            <svg
                                class="h-4 w-4 text-[#706f6c] transition-transform duration-200"
                                :class="discountOpen ? 'rotate-180' : ''"
                                fill="none"
                                viewBox="0 0 24 24"
                                stroke="currentColor"
                                stroke-width="2"
                            >
                                <path
                                    stroke-linecap="round"
                                    stroke-linejoin="round"
                                    d="M19 9l-7 7-7-7"
                                />
                            </svg>
                        </div>
                    </button>

                    <div
                        v-show="discountOpen"
                        class="space-y-3 border-t border-[#e5e7eb] p-3"
                    >
                        <!-- Applied Discount Breakdown Card -->
                        <div
                            v-if="appliedPromo && totalDiscountAmount > 0"
                            class="rounded border border-[#d2e4d8] bg-[#f2f7f4] p-3 text-xs"
                        >
                            <div class="flex items-center justify-between">
                                <span
                                    class="flex items-center gap-1.5 font-bold text-[#2f573c]"
                                >
                                    <svg
                                        class="h-4 w-4 text-[#4a7c59]"
                                        fill="none"
                                        viewBox="0 0 24 24"
                                        stroke="currentColor"
                                    >
                                        <path
                                            stroke-linecap="round"
                                            stroke-linejoin="round"
                                            stroke-width="2"
                                            d="M5 13l4 4L19 7"
                                        />
                                    </svg>
                                    <span
                                        >Deal Applied:
                                        {{ appliedPromo.code }}</span
                                    >
                                </span>
                                <span class="text-sm font-bold text-[#2f573c]">
                                    - {{ formatCurrency(totalDiscountAmount) }}
                                </span>
                            </div>
                            <div
                                class="mt-2 grid grid-cols-3 gap-2 border-t border-[#d2e4d8] pt-2 text-center text-[11px]"
                            >
                                <div>
                                    <div class="text-[#706f6c]">
                                        Original Charges
                                    </div>
                                    <div
                                        class="font-semibold text-[#1b1b18] line-through"
                                    >
                                        {{ formatCurrency(totalRoomCharges) }}
                                    </div>
                                </div>
                                <div>
                                    <div class="text-[#706f6c]">Discount</div>
                                    <div class="font-semibold text-[#4a7c59]">
                                        {{ appliedDiscountPercent }}% OFF
                                    </div>
                                </div>
                                <div>
                                    <div class="text-[#706f6c]">
                                        After Discount
                                    </div>
                                    <div class="font-semibold text-[#1b1b18]">
                                        {{ formatCurrency(netRoomCharges) }}
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div>
                            <div class="flex items-center gap-2">
                                <input
                                    type="radio"
                                    name="promo"
                                    id="promo-custom"
                                    :checked="
                                        isCustomPromoActive &&
                                        appliedPromo !== null
                                    "
                                    class="cursor-pointer text-[#4a7c59] focus:ring-[#4a7c59]"
                                    @click.prevent="toggleCustomPromoRadio"
                                />
                                <div class="flex flex-1 items-center gap-2">
                                    <input
                                        v-model="customPromoInput"
                                        type="text"
                                        placeholder="Enter Promo Code"
                                        class="w-full rounded border border-dashed border-[#9ca3af] px-2.5 py-1 text-xs text-[#1b1b18] placeholder:text-gray-400 focus:border-[#4a7c59] focus:outline-none"
                                        @keydown.enter.prevent="
                                            applyEnteredPromoCode
                                        "
                                    />
                                    <button
                                        type="button"
                                        class="rounded bg-[#4a7c59] px-3 py-1 text-[11px] font-semibold text-white uppercase hover:bg-[#3f6d4d] disabled:opacity-60"
                                        :disabled="isCheckingPromo"
                                        @click="applyEnteredPromoCode"
                                    >
                                        APPLY
                                    </button>
                                </div>
                            </div>
                            <p
                                v-if="customPromoError"
                                class="mt-1 ml-6 text-[11px] font-medium text-red-600"
                            >
                                {{ customPromoError }}
                            </p>
                            <p
                                v-if="customPromoSuccess"
                                class="mt-1 ml-6 text-[11px] font-medium text-[#4a7c59]"
                            >
                                {{ customPromoSuccess }}
                            </p>
                        </div>

                        <template v-if="availablePublicPromos.length > 0">
                            <div
                                v-for="promo in availablePublicPromos"
                                :key="promo.id"
                                class="border-t border-gray-100 pt-2 pb-1"
                            >
                                <div
                                    class="flex cursor-pointer items-center justify-between"
                                    @click="selectPublicPromo(promo)"
                                >
                                    <div class="flex items-center gap-2">
                                        <input
                                            type="radio"
                                            name="promo"
                                            :value="promo.code"
                                            :checked="
                                                appliedPromo?.code ===
                                                    promo.code &&
                                                !isCustomPromoActive
                                            "
                                            :disabled="
                                                !isPromoConditionFulfilled(
                                                    promo,
                                                ).valid
                                            "
                                            class="cursor-pointer text-[#4a7c59] focus:ring-[#4a7c59] disabled:cursor-not-allowed disabled:opacity-60"
                                            @click.stop.prevent="
                                                selectPublicPromo(promo)
                                            "
                                        />
                                        <span class="text-xs text-[#1b1b18]">
                                            <span class="font-bold">{{
                                                promo.code
                                            }}</span>
                                            -
                                            <span class="text-[#706f6c]">{{
                                                promo.tag_line ||
                                                'Avail ' +
                                                    (promo.discount_type ===
                                                    'percentage'
                                                        ? promo.discount_value +
                                                          '% Discount'
                                                        : formatCurrency(
                                                              promo.discount_value,
                                                          ) + ' Discount')
                                            }}</span>
                                        </span>
                                    </div>
                                    <div class="flex items-center gap-2">
                                        <span
                                            v-if="
                                                appliedPromo?.code ===
                                                    promo.code &&
                                                !isCustomPromoActive
                                            "
                                            class="rounded bg-green-100 px-2 py-0.5 text-[10px] font-bold text-green-800"
                                        >
                                            Applied<template
                                                v-if="totalDiscountAmount > 0"
                                            >
                                                (-{{
                                                    formatCurrency(
                                                        totalDiscountAmount,
                                                    )
                                                }})</template
                                            >
                                        </span>
                                        <span
                                            v-else-if="
                                                totalRoomCharges > 0 &&
                                                isPromoConditionFulfilled(promo)
                                                    .valid
                                            "
                                            class="text-[11px] font-semibold text-[#4a7c59]"
                                        >
                                            Save
                                            {{
                                                formatCurrency(
                                                    getPromoSavings(promo),
                                                )
                                            }}
                                        </span>
                                        <span
                                            class="cursor-help text-gray-400 hover:text-gray-600"
                                            :title="
                                                promo.description ||
                                                promo.terms_and_conditions ||
                                                promo.tag_line
                                            "
                                            @click.stop
                                        >
                                            <svg
                                                class="h-3.5 w-3.5"
                                                fill="currentColor"
                                                viewBox="0 0 20 20"
                                            >
                                                <path
                                                    fill-rule="evenodd"
                                                    d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z"
                                                    clip-rule="evenodd"
                                                />
                                            </svg>
                                        </span>
                                    </div>
                                </div>
                                <p
                                    v-if="
                                        !isPromoConditionFulfilled(promo).valid
                                    "
                                    class="mt-1 ml-6 text-[11px] font-medium text-red-600"
                                >
                                    {{
                                        isPromoConditionFulfilled(promo).reason
                                    }}
                                </p>
                            </div>
                        </template>
                        <p v-else class="text-[11px] text-[#706f6c]">
                            No public deals available for the selected dates.
                        </p>

                        <p
                            class="border-t border-gray-100 pt-1 text-[11px] text-[#706f6c] italic"
                        >
                            *One promo will be applicable at a time
                        </p>
                    </div>
                </div>

                <!-- Total Taxes Box -->
                <div
                    class="overflow-hidden rounded-sm border border-[#d1d5db] bg-white text-xs"
                >
                    <button
                        type="button"
                        class="flex w-full items-center justify-between p-3 text-sm font-medium text-[#1b1b18] hover:bg-[#fafaf9]"
                        @click="taxesOpen = !taxesOpen"
                    >
                        <span>Total Taxes</span>
                        <div class="flex items-center gap-1 font-medium">
                            <span>{{ formatCurrency(calculatedTaxes) }}</span>
                            <svg
                                class="h-3.5 w-3.5 text-[#706f6c] transition-transform duration-200"
                                :class="taxesOpen ? 'rotate-180' : ''"
                                fill="none"
                                viewBox="0 0 24 24"
                                stroke="currentColor"
                                stroke-width="2"
                            >
                                <path
                                    stroke-linecap="round"
                                    stroke-linejoin="round"
                                    d="M19 9l-7 7-7-7"
                                />
                            </svg>
                        </div>
                    </button>
                    <div
                        v-show="taxesOpen"
                        class="space-y-1 border-t border-[#e5e7eb] bg-[#fafaf9] px-3 py-2 text-[11px] text-[#706f6c]"
                    >
                        <div
                            v-for="t in taxBreakdown"
                            :key="t.label"
                            class="flex justify-between"
                        >
                            <span>{{ t.label }}</span>
                            <span>{{ formatCurrency(t.amount) }}</span>
                        </div>
                    </div>
                </div>

                <div
                    v-if="addonLines.length"
                    class="rounded-sm border border-[#d1d5db] bg-white p-3 text-xs"
                >
                    <div class="mb-1 text-sm font-medium text-[#1b1b18]">
                        Add-ons
                    </div>
                    <div
                        v-for="line in addonLines"
                        :key="line.addon_id"
                        class="flex justify-between text-[11px] text-[#706f6c]"
                    >
                        <span>{{ line.name }} × {{ line.quantity }}</span>
                        <span>{{ formatCurrency(line.total) }}</span>
                    </div>
                </div>

                <!-- Total Payable & Checkout Actions -->
                <div
                    class="rounded-sm border border-[#d1d5db] bg-white p-3 text-xs"
                >
                    <div class="flex items-baseline justify-between">
                        <span class="text-base font-semibold text-[#1b1b18]"
                            >Total Payable</span
                        >
                        <div class="text-right">
                            <span
                                v-if="totalDiscountAmount > 0"
                                class="mr-2 text-xs text-[#706f6c] line-through"
                            >
                                {{ formatCurrency(originalTotalPayable) }}
                            </span>
                            <span class="text-lg font-bold text-[#1b1b18]">{{
                                formatCurrency(totalPayable)
                            }}</span>
                        </div>
                    </div>

                    <!-- Discount summary callout if discount is active -->
                    <div
                        v-if="totalDiscountAmount > 0"
                        class="mt-2 rounded border border-[#d2e4d8] bg-[#f2f7f4] p-2.5 text-[11px] text-[#2f573c]"
                    >
                        <div
                            class="flex items-center justify-between font-semibold"
                        >
                            <span class="flex items-center gap-1">
                                <svg
                                    class="h-3.5 w-3.5 text-[#4a7c59]"
                                    fill="none"
                                    viewBox="0 0 24 24"
                                    stroke="currentColor"
                                >
                                    <path
                                        stroke-linecap="round"
                                        stroke-linejoin="round"
                                        stroke-width="2"
                                        d="M5 13l4 4L19 7"
                                    />
                                </svg>
                                <span
                                    >Discount ({{
                                        appliedPromo?.code
                                            ? appliedPromo.code + ' - '
                                            : ''
                                    }}{{ appliedDiscountPercent }}%):</span
                                >
                            </span>
                            <span class="text-xs font-bold text-[#2f573c]"
                                >-
                                {{ formatCurrency(totalDiscountAmount) }}</span
                            >
                        </div>
                        <div
                            class="mt-1.5 flex justify-between border-t border-[#d2e4d8] pt-1.5 text-[#4a7c59]"
                        >
                            <span
                                >Room Charges:
                                <span class="text-[#706f6c] line-through">{{
                                    formatCurrency(totalRoomCharges)
                                }}</span>
                                &rarr;
                                <strong class="text-[#2f573c]">{{
                                    formatCurrency(netRoomCharges)
                                }}</strong></span
                            >
                            <span class="font-bold"
                                >Total Saved:
                                {{ formatCurrency(totalSavedAmount) }}</span
                            >
                        </div>
                    </div>

                    <!-- Partial Payment summary breakdown if partial payment is selected -->
                    <div
                        v-if="allowPartialPayment && partialPayment"
                        class="mt-2 space-y-1 rounded border border-[#d2e4d8] bg-[#f2f7f4] p-2 text-[11px]"
                    >
                        <div
                            class="flex justify-between font-semibold text-[#2f573c]"
                        >
                            <span
                                >Due Now ({{
                                    partialPaymentPercentage
                                }}%):</span
                            >
                            <span>{{ formatCurrency(payNowAmount) }}</span>
                        </div>
                        <div class="flex justify-between text-[#706f6c]">
                            <span
                                >Due at Check-in ({{
                                    100 - partialPaymentPercentage
                                }}%):</span
                            >
                            <span>{{
                                formatCurrency(remainingBalanceAmount)
                            }}</span>
                        </div>
                    </div>

                    <button
                        type="button"
                        class="mt-2 text-[11px] font-semibold tracking-wide text-[#4a7c59] uppercase hover:underline"
                        @click="showMoreDetails = !showMoreDetails"
                    >
                        {{
                            showMoreDetails
                                ? 'HIDE PRICE DETAILS'
                                : 'VIEW PRICE DETAILS'
                        }}
                    </button>

                    <div
                        v-if="showMoreDetails"
                        class="mt-2 space-y-1.5 rounded border border-[#e5e7eb] bg-[#fafaf9] p-2.5 text-[11px] text-[#706f6c]"
                    >
                        <div class="flex justify-between">
                            <span>Total Room Charges</span>
                            <span class="font-medium text-[#1b1b18]">{{
                                formatCurrency(totalRoomCharges)
                            }}</span>
                        </div>
                        <div
                            v-if="totalDiscountAmount > 0"
                            class="flex justify-between font-medium text-[#4a7c59]"
                        >
                            <span
                                >Discount ({{
                                    appliedPromo?.code
                                        ? appliedPromo.code + ' - '
                                        : ''
                                }}{{ appliedDiscountPercent }}%)</span
                            >
                            <span
                                >-
                                {{ formatCurrency(totalDiscountAmount) }}</span
                            >
                        </div>
                        <div
                            v-if="totalDiscountAmount > 0"
                            class="flex justify-between border-t border-dashed border-[#d1d5db] pt-1 font-medium text-[#1b1b18]"
                        >
                            <span>Room Charges after Discount</span>
                            <span>{{ formatCurrency(netRoomCharges) }}</span>
                        </div>
                        <div class="flex justify-between">
                            <span
                                >Taxes &amp; Fees ({{
                                    Math.round(taxRate * 100)
                                }}%)</span
                            >
                            <span class="font-medium text-[#1b1b18]">{{
                                formatCurrency(calculatedTaxes)
                            }}</span>
                        </div>
                        <div
                            v-if="addonsTotal > 0"
                            class="flex justify-between"
                        >
                            <span>Add-ons (incl. tax)</span>
                            <span class="font-medium text-[#1b1b18]">{{
                                formatCurrency(addonsTotal)
                            }}</span>
                        </div>
                        <div
                            class="flex justify-between border-t border-[#e3e3e0] pt-1.5 text-xs font-bold text-[#1b1b18]"
                        >
                            <span>Total Payable</span>
                            <span class="text-[#1b1b18]">{{
                                formatCurrency(totalPayable)
                            }}</span>
                        </div>
                    </div>

                    <!-- Terms and Pay CTA (the sidebar is hidden once confirmed) -->
                    <!-- Terms & Conditions Checkbox -->
                    <div class="mt-4 flex items-start gap-2">
                        <input
                            v-model="agreeTerms"
                            type="checkbox"
                            id="agree-terms"
                            class="mt-0.5 rounded text-[#4a7c59] focus:ring-[#4a7c59]"
                        />
                        <label
                            for="agree-terms"
                            class="text-[11px] leading-snug text-[#706f6c]"
                        >
                            I agree to the
                            <button
                                type="button"
                                class="text-blue-600 underline hover:text-blue-800"
                                @click="openTermsModal('terms')"
                            >
                                Terms &amp; Conditions
                            </button>
                            and
                            <button
                                type="button"
                                class="text-blue-600 underline hover:text-blue-800"
                                @click="openTermsModal('cancellation')"
                            >
                                Refund &amp; Cancellation Policy</button
                            ><template v-if="legal"
                                >, the
                                <a
                                    :href="legal.terms_url"
                                    target="_blank"
                                    rel="noopener"
                                    class="text-blue-600 underline hover:text-blue-800"
                                    >Terms of Booking</a
                                >
                                and the
                                <a
                                    :href="legal.privacy_url"
                                    target="_blank"
                                    rel="noopener"
                                    class="text-blue-600 underline hover:text-blue-800"
                                    >Privacy Policy</a
                                >, and consent to my details being used for this
                                booking
                            </template>
                        </label>
                    </div>
                    <div
                        v-if="legal?.marketing_opt_in"
                        class="mt-2 flex items-start gap-2"
                    >
                        <input
                            v-model="agreeMarketing"
                            type="checkbox"
                            id="agree-marketing"
                            class="mt-0.5 rounded text-[#4a7c59] focus:ring-[#4a7c59]"
                        />
                        <label
                            for="agree-marketing"
                            class="text-[11px] leading-snug text-[#706f6c]"
                            >Send me offers and news from
                            {{ property.name }} (optional; you can stop them any
                            time)</label
                        >
                    </div>

                    <!-- Pay / Proceed CTA Button -->
                    <button
                        type="button"
                        :disabled="
                            selectedRoomsWithIndex.length === 0 ||
                            (step !== 'select-room' && !agreeTerms) ||
                            isProcessingPayment
                        "
                        class="mt-4 flex w-full items-center justify-center gap-2 rounded bg-[#4a7c59] py-3 text-sm font-semibold tracking-wide text-white uppercase shadow transition-colors hover:bg-[#3f6d4d] disabled:cursor-not-allowed disabled:opacity-50"
                        @click="onPayOrProceed"
                    >
                        <svg
                            v-if="isProcessingPayment"
                            class="h-4 w-4 animate-spin text-white"
                            xmlns="http://www.w3.org/2000/svg"
                            fill="none"
                            viewBox="0 0 24 24"
                        >
                            <circle
                                class="opacity-25"
                                cx="12"
                                cy="12"
                                r="10"
                                stroke="currentColor"
                                stroke-width="4"
                            ></circle>
                            <path
                                class="opacity-75"
                                fill="currentColor"
                                d="M4 12a8 8 0 018-8v8H4z"
                            ></path>
                        </svg>
                        <span v-if="isProcessingPayment"
                            >Processing Payment...</span
                        >
                        <span v-else-if="step === 'select-room'">PROCEED</span>
                        <span v-else-if="allowPartialPayment && partialPayment"
                            >PAY PARTIAL {{ formatCurrency(payNowAmount) }} WITH
                            {{
                                selectedGateway === 'razorpay'
                                    ? 'RAZORPAY'
                                    : selectedGateway === 'cashfree'
                                      ? 'CASHFREE'
                                      : 'TEST GATEWAY'
                            }}</span
                        >
                        <span v-else
                            >PAY {{ formatCurrency(totalPayable) }} WITH
                            {{
                                selectedGateway === 'razorpay'
                                    ? 'RAZORPAY'
                                    : selectedGateway === 'cashfree'
                                      ? 'CASHFREE'
                                      : 'TEST GATEWAY'
                            }}</span
                        >
                    </button>
                </div>
            </aside>
        </div>

        <footer
            class="border-t border-[#e3e3e0] bg-white px-6 py-6 text-sm text-[#706f6c]"
        >
            <p class="font-medium text-[#1b1b18]">ADDRESS</p>
            <p>{{ property.address || '—' }}</p>
            <p v-if="property.contact_number" class="mt-3">
                <span class="font-medium text-[#1b1b18]">CONTACT</span>
                {{ property.contact_number }}
            </p>
        </footer>

        <RoomDetailsModal
            :show="detailsModalRoom !== null"
            :room="detailsModalRoom"
            :initial-tab="detailsModalTab"
            :initial-image-index="detailsModalImageIndex"
            :cancellation-policy="cancellationPolicy"
            :terms-and-conditions="termsAndConditions"
            @close="detailsModalRoom = null"
        />

        <Modal
            :show="showTermsModal"
            :title="
                termsModalTab === 'terms'
                    ? 'Terms & Conditions'
                    : 'Refund & Cancellation Policy'
            "
            @close="showTermsModal = false"
        >
            <div class="mb-4 flex gap-4 border-b border-[#e3e3e0]">
                <button
                    type="button"
                    class="cursor-pointer border-b-2 pb-2 text-xs font-semibold transition-colors"
                    :class="
                        termsModalTab === 'terms'
                            ? 'border-[#4a7c59] text-[#4a7c59]'
                            : 'border-transparent text-[#706f6c] hover:text-[#1b1b18]'
                    "
                    @click="termsModalTab = 'terms'"
                >
                    Terms &amp; Conditions
                </button>
                <button
                    type="button"
                    class="cursor-pointer border-b-2 pb-2 text-xs font-semibold transition-colors"
                    :class="
                        termsModalTab === 'cancellation'
                            ? 'border-[#4a7c59] text-[#4a7c59]'
                            : 'border-transparent text-[#706f6c] hover:text-[#1b1b18]'
                    "
                    @click="termsModalTab = 'cancellation'"
                >
                    Refund &amp; Cancellation Policy
                </button>
            </div>

            <!-- Terms & Conditions Tab -->
            <div
                v-show="termsModalTab === 'terms'"
                class="max-h-96 overflow-y-auto pr-1 text-xs text-[#1b1b18]"
            >
                <div
                    v-if="termsAndConditions"
                    class="ql-editor-content leading-relaxed text-[#1b1b18]"
                    v-html="termsAndConditions"
                ></div>
                <div v-else class="space-y-2 text-[#706f6c]">
                    <p>
                        Primary guest must be at least 18 years of age. A valid
                        government photo ID is mandatory at check-in.
                    </p>
                    <p>
                        Standard check-in time is 14:00 hrs and check-out is
                        11:00 hrs. Early check-in or late check-out is subject
                        to availability.
                    </p>
                </div>
            </div>

            <!-- Cancellation & Refund Policy Tab -->
            <div
                v-show="termsModalTab === 'cancellation'"
                class="max-h-96 space-y-4 overflow-y-auto pr-1 text-xs text-[#1b1b18]"
            >
                <div
                    v-if="
                        cancellationRuleSummary &&
                        cancellationRuleSummary.length
                    "
                >
                    <p class="mb-3 text-[#706f6c]">
                        Cancellation charges and refund schedule for bookings at
                        <span class="font-medium text-[#1b1b18]">{{
                            property.name
                        }}</span
                        >:
                    </p>

                    <!-- Summary Table -->
                    <div
                        class="overflow-x-auto rounded-lg border border-[#e3e3e0]"
                    >
                        <table
                            class="min-w-full divide-y divide-[#e3e3e0] text-xs"
                        >
                            <thead class="bg-[#f5f5f4]">
                                <tr>
                                    <th
                                        class="px-3 py-2 text-left font-semibold text-[#1b1b18]"
                                    >
                                        Cancellation Window
                                    </th>
                                    <th
                                        class="px-3 py-2 text-center font-semibold text-[#1b1b18]"
                                    >
                                        Cancellation Fee
                                    </th>
                                    <th
                                        class="px-3 py-2 text-right font-semibold text-[#1b1b18]"
                                    >
                                        Refund Amount
                                    </th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-[#e3e3e0] bg-white">
                                <tr
                                    v-for="(
                                        item, idx
                                    ) in cancellationRuleSummary"
                                    :key="idx"
                                    class="hover:bg-[#fafaf9]"
                                >
                                    <td
                                        class="px-3 py-2.5 font-medium text-[#1b1b18]"
                                    >
                                        {{ item.window }}
                                    </td>
                                    <td
                                        class="px-3 py-2.5 text-center font-semibold text-[var(--be-theme)]"
                                    >
                                        {{ item.penalty }}
                                    </td>
                                    <td
                                        class="px-3 py-2.5 text-right font-semibold text-[#4a7c59]"
                                    >
                                        {{ item.refund }}
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div
                    v-else-if="cancellationPolicy && cancellationPolicy.length"
                    class="space-y-2"
                >
                    <h4 class="font-semibold text-[#1b1b18]">
                        Cancellation Policy
                    </h4>
                    <ul class="list-disc space-y-1 pl-4 text-[#706f6c]">
                        <li v-for="line in cancellationPolicy" :key="line">
                            {{ line }}
                        </li>
                    </ul>
                </div>

                <div v-else class="space-y-2 text-[#706f6c]">
                    <p>Free cancellation up to 48 hours prior to check-in.</p>
                    <p>
                        Cancellations within 48 hours of check-in are subject to
                        a 100% cancellation charge.
                    </p>
                </div>

                <div
                    class="space-y-1 border-t border-[#e3e3e0] pt-3 text-[11px] text-[#706f6c]"
                >
                    <p>
                        • Cancellation charges are calculated based on the
                        check-in date and local time of the property.
                    </p>
                    <p>
                        • Eligible refunds will be credited back to the original
                        source of payment within 5–7 business days.
                    </p>
                </div>
            </div>

            <div class="mt-6 flex justify-end">
                <button
                    type="button"
                    class="rounded bg-[#4a7c59] px-4 py-1.5 text-xs font-semibold text-white hover:bg-[#3f6d4d]"
                    @click="showTermsModal = false"
                >
                    Close
                </button>
            </div>
        </Modal>

        <!-- Reset Cart Confirmation Modal -->
        <Modal
            :show="showResetConfirmModal"
            title="Reset Current Selection?"
            @close="cancelResetConfirm"
        >
            <div class="space-y-4">
                <div class="flex items-start gap-3">
                    <span
                        class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-amber-100 text-amber-700"
                    >
                        <svg
                            class="h-5 w-5"
                            fill="none"
                            viewBox="0 0 24 24"
                            stroke="currentColor"
                            stroke-width="2"
                        >
                            <path
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"
                            />
                        </svg>
                    </span>
                    <div class="text-sm text-[#374151]">
                        <p class="font-medium text-[#1b1b18]">
                            Changing your search dates or room/guest count will
                            reset your current room selections.
                        </p>
                        <p class="mt-1 text-xs text-[#706f6c]">
                            You currently have
                            {{ selectedRoomsList.length }} room(s) in your cart.
                            Do you want to continue and clear your selection?
                        </p>
                    </div>
                </div>

                <div
                    class="flex justify-end gap-3 border-t border-[#e5e7eb] pt-3"
                >
                    <button
                        type="button"
                        class="rounded-md border border-[#d1d5db] bg-white px-4 py-2 text-xs font-semibold text-[#374151] hover:bg-[#f9fafb]"
                        @click="cancelResetConfirm"
                    >
                        Cancel
                    </button>
                    <button
                        type="button"
                        class="rounded-md bg-[var(--be-theme)] px-4 py-2 text-xs font-semibold tracking-wide text-white uppercase hover:bg-[#d92a02]"
                        @click="confirmResetAndSearch"
                    >
                        Continue
                    </button>
                </div>
            </div>
        </Modal>

        <!-- Payment Gateway Checkout / Sandbox Modal -->
        <Modal
            :show="showSimulatedPaymentModal"
            :title="
                simulatedPaymentData?.gateway === 'razorpay'
                    ? 'Razorpay Payment Gateway (Test Mode)'
                    : simulatedPaymentData?.gateway === 'cashfree'
                      ? 'Cashfree Payment Gateway (Test Mode)'
                      : 'Test Payment Gateway (Simulator)'
            "
            @close="cancelSimulatedPayment"
        >
            <div
                v-if="simulatedPaymentData"
                class="space-y-4 text-sm text-[#1b1b18]"
            >
                <!-- Gateway branding banner -->
                <div
                    class="flex items-center justify-between rounded-lg border p-3.5"
                    :class="
                        simulatedPaymentData.gateway === 'razorpay'
                            ? 'border-[#b9d5fc] bg-[#f0f6ff]'
                            : simulatedPaymentData.gateway === 'cashfree'
                              ? 'border-[#fde68a] bg-[#fffbeb]'
                              : 'border-[#fed7aa] bg-[#fff7ed]'
                    "
                >
                    <div class="flex items-center gap-2">
                        <span
                            class="rounded px-2 py-0.5 text-xs font-bold"
                            :class="
                                simulatedPaymentData.gateway === 'razorpay'
                                    ? 'bg-[#0c2340] text-[#528ff0]'
                                    : simulatedPaymentData.gateway ===
                                        'cashfree'
                                      ? 'bg-[#2a0845] text-[#ffbe00]'
                                      : 'bg-amber-700 text-white'
                            "
                        >
                            {{
                                simulatedPaymentData.gateway === 'razorpay'
                                    ? 'RAZORPAY'
                                    : simulatedPaymentData.gateway ===
                                        'cashfree'
                                      ? 'CASHFREE'
                                      : 'TEST GATEWAY'
                            }}
                        </span>
                        <span class="text-xs font-semibold text-[#1b1b18]">{{
                            simulatedPaymentData.gateway === 'test'
                                ? 'Zero-Credential Simulator'
                                : 'Test Mode Checkout'
                        }}</span>
                    </div>
                    <span class="font-mono text-xs font-bold text-[#4a7c59]">{{
                        formatCurrency(payNowAmount)
                    }}</span>
                </div>

                <div
                    class="space-y-2 rounded-lg border border-[#e3e3e0] bg-[#fafaf9] p-3.5 text-xs"
                >
                    <div class="flex justify-between">
                        <span class="text-[#706f6c]">Booking Ref:</span>
                        <span class="font-mono font-semibold">{{
                            simulatedPaymentData.booking_ref_id
                        }}</span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-[#706f6c]">Guest Name:</span>
                        <span class="font-medium">{{ guestDetails.name }}</span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-[#706f6c]">Email:</span>
                        <span class="font-medium">{{
                            guestDetails.email
                        }}</span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-[#706f6c]">Phone:</span>
                        <span class="font-medium">{{
                            guestDetails.phone
                        }}</span>
                    </div>
                    <div
                        class="flex justify-between border-t border-[#e3e3e0] pt-2 font-semibold"
                    >
                        <span>Amount to Charge Now:</span>
                        <span class="text-sm font-bold text-[#4a7c59]">{{
                            formatCurrency(payNowAmount)
                        }}</span>
                    </div>
                </div>

                <p class="text-[11px] text-[#706f6c]">
                    This checkout is running in test sandbox mode. Click below
                    to simulate an immediate successful payment and complete
                    your reservation, or cancel to abort.
                </p>

                <div class="flex flex-col gap-2 border-t border-[#e5e7eb] pt-2">
                    <button
                        type="button"
                        :disabled="isProcessingPayment"
                        class="flex w-full items-center justify-center gap-2 rounded bg-[#4a7c59] py-2.5 text-xs font-semibold text-white uppercase shadow transition-colors hover:bg-[#3f6d4d] disabled:opacity-50"
                        @click="simulateSuccessfulPayment"
                    >
                        <svg
                            v-if="isProcessingPayment"
                            class="h-4 w-4 animate-spin text-white"
                            xmlns="http://www.w3.org/2000/svg"
                            fill="none"
                            viewBox="0 0 24 24"
                        >
                            <circle
                                class="opacity-25"
                                cx="12"
                                cy="12"
                                r="10"
                                stroke="currentColor"
                                stroke-width="4"
                            ></circle>
                            <path
                                class="opacity-75"
                                fill="currentColor"
                                d="M4 12a8 8 0 018-8v8H4z"
                            ></path>
                        </svg>
                        <span
                            >Simulate Successful Payment ({{
                                formatCurrency(payNowAmount)
                            }})</span
                        >
                    </button>
                    <button
                        type="button"
                        :disabled="isProcessingPayment"
                        class="w-full rounded border border-[#e3e3e0] bg-white py-2 text-xs font-medium text-[#706f6c] hover:bg-gray-50"
                        @click="cancelSimulatedPayment"
                    >
                        Cancel / Simulate Failed Payment
                    </button>
                </div>
            </div>
        </Modal>
    </div>
</template>

<style scoped>
.ql-editor-content :deep(p) {
    margin-bottom: 0.5rem;
}
.ql-editor-content :deep(p:last-child) {
    margin-bottom: 0;
}
.ql-editor-content :deep(ul) {
    list-style-type: disc;
    padding-left: 1.25rem;
    margin-bottom: 0.5rem;
}
.ql-editor-content :deep(ol) {
    list-style-type: decimal;
    padding-left: 1.25rem;
    margin-bottom: 0.5rem;
}
.ql-editor-content :deep(li) {
    margin-bottom: 0.25rem;
}
.ql-editor-content :deep(h1) {
    font-size: 1.25rem;
    font-weight: 700;
    margin-top: 0.75rem;
    margin-bottom: 0.5rem;
}
.ql-editor-content :deep(h2) {
    font-size: 1.1rem;
    font-weight: 600;
    margin-top: 0.75rem;
    margin-bottom: 0.5rem;
}
.ql-editor-content :deep(h3) {
    font-size: 1rem;
    font-weight: 600;
    margin-top: 0.5rem;
    margin-bottom: 0.25rem;
}
.ql-editor-content :deep(blockquote) {
    border-left: 3px solid #cbd5e1;
    padding-left: 0.75rem;
    color: #64748b;
    margin: 0.5rem 0;
}
.ql-editor-content :deep(a) {
    color: #2563eb;
    text-decoration: underline;
}
.ql-editor-content :deep(.ql-align-center) {
    text-align: center;
}
.ql-editor-content :deep(.ql-align-right) {
    text-align: right;
}
.ql-editor-content :deep(.ql-align-justify) {
    text-align: justify;
}
.ql-editor-content :deep(.ql-indent-1) {
    padding-left: 1.5rem;
}
.ql-editor-content :deep(.ql-indent-2) {
    padding-left: 3rem;
}

.no-scrollbar {
    -ms-overflow-style: none;
    scrollbar-width: none;
}
.no-scrollbar::-webkit-scrollbar {
    display: none;
}
</style>
