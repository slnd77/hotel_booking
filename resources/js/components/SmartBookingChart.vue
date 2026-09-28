<script setup lang="ts">
import BookingSummaryCard from '@/components/BookingSummaryCard.vue';
import DateRangePicker from '@/components/DateRangePicker.vue';
import HolidayMarker from '@/components/HolidayMarker.vue';
import { useCan } from '@/lib/useCan';
import { usePropertyTime } from '@/lib/usePropertyTime';
import type { Holiday, HolidayDay } from '@/lib/holidays';
import admin from '@/routes/admin';
import { Link, router, usePage } from '@inertiajs/vue3';
import { useCurrency } from '@/lib/useCurrency';
import {
    computed,
    nextTick,
    onBeforeUnmount,
    onMounted,
    ref,
    watch,
} from 'vue';

const { amount } = useCurrency();

// Teleports to <body> render nothing on the server (Inertia SSR keeps only the app's markup),
// so they mount in the browser only: both sides render the same placeholder when hydrating.
const isClientMounted = ref(false);
onMounted(() => (isClientMounted.value = true));

export type ChartDate = {
    date: string;
    day_name: string;
    day_number: string;
    month_name: string;
    formatted: string;
    is_today: boolean;
    is_past: boolean;
    year: number;
    /** From Properties → Policies → Weekends & Holidays. */
    is_weekend?: boolean;
    holiday?: HolidayDay | null;
    /** Open enquiries checking in that day. */
    enquiries_count?: number;
    notes?: ChartNote[];
};

export type ChartNote = {
    id: number;
    user_id: number;
    user_name: string;
    body: string;
    updated_at: string | null;
};

export type ChartBooking = {
    booking_id: number;
    sub_booking_id: number;
    /** Rooms in the whole booking; a date drag on a multi-room booking asks "this room or all?". */
    booking_rooms_count: number;
    rooms_split: boolean;
    booking_ref_id: string;
    guest_name: string;
    check_in: string;
    check_out: string;
    status: string;
    status_label: string;
    check_in_status: string | null;
    type: string | null;
    is_emergency: boolean;
    is_blocked: boolean;
    total: number;
    outstanding: number;
    /** A dorm stay's bed number (its lane in the dorm room's row); null for a whole room. */
    bed?: number | null;
};

export type ChartBlock = {
    id: number;
    block_reason_id: number | null;
    reason_label: string;
    is_maintenance: boolean;
    name: string | null;
    remarks: string;
    start_date: string;
    end_date: string;
    blocked_by: string | null;
    blocked_at: string | null;
};

export type ChartRoom = {
    id: number;
    name: string;
    display_order: number;
    room_category_id: number;
    category_abbreviation: string;
    /** Housekeeping status (Booking Settings → Housekeeping); the room name is shown in its colour. */
    housekeeping: HousekeepingStatus | null;
    bookings: ChartBooking[];
    blocks: ChartBlock[];
    /** Dorm rooms: their beds, one lane each (0 for a room sold whole). */
    beds?: number;
};

export type HousekeepingStatus = { name: string; color: string };

export type ChartCategory = {
    id: number;
    name: string;
    abbreviation: string;
    rooms_count: number;
    daily_availability: Record<
        string,
        { available: number; is_sold_out: boolean }
    >;
    rooms: ChartRoom[];
};

export type ChartData = {
    property_id: number;
    dates: ChartDate[];
    holidays?: Holiday[];
    start_date: string;
    end_date: string;
    today: string;
    can_go_prev: boolean;
    /** Application Settings → Smart Booking Chart → auto-refresh time. */
    auto_refresh_minutes: number;
    daily_totals: Record<
        string,
        { available: number; booked: number; total: number }
    >;
    categories: ChartCategory[];
    block_reasons: { id: number; name: string }[];
    housekeeping_statuses: HousekeepingStatus[];
};

const props = defineProps<{
    property: { id: number; name: string; abbreviation: string };
    initialData: ChartData | null;
}>();

/** Fixed column widths (px) so the grid scrolls horizontally inside its own container. */
const LABEL_COLUMN_WIDTH = 144;
const DATE_COLUMN_WIDTH = 128;
/** The arrows move by the number of days shown (Application Settings → Smart Booking Chart). */
const navigationStepDays = computed(() => chartData.value?.dates.length || 10);

const chartData = ref<ChartData | null>(props.initialData);
const loading = ref(false);
const expandedCategories = ref<Record<number, boolean>>({});
const scrollContainerRef = ref<HTMLElement | null>(null);

const tableWidth = computed(
    () =>
        LABEL_COLUMN_WIDTH +
        (chartData.value?.dates.length ?? 0) * DATE_COLUMN_WIDTH,
);

/** Categories start collapsed; a category the user opened stays open across refreshes and date changes. */
watch(
    () => chartData.value?.categories,
    (categories) => {
        categories?.forEach((category) => {
            if (expandedCategories.value[category.id] === undefined) {
                expandedCategories.value[category.id] = false;
            }
        });
    },
    { immediate: true },
);

watch(
    () => props.initialData,
    (newData) => {
        if (newData) {
            chartData.value = newData;
        }
    },
);

/** Roles and Permissions on the chart (the server checks every action again). */
const can = useCan();

function toggleCategory(categoryId: number): void {
    if (!can('smart_chart.expand_categories')) {
        return;
    }

    expandedCategories.value[categoryId] =
        !expandedCategories.value[categoryId];
}

/** The Total row opens every category when any is closed, and closes them all when all are open. */
const allCategoriesExpanded = computed(() => {
    const categories = chartData.value?.categories ?? [];

    return (
        categories.length > 0 &&
        categories.every((category) => expandedCategories.value[category.id])
    );
});

function toggleAllCategories(): void {
    if (!can('smart_chart.expand_categories')) {
        return;
    }

    const expand = !allCategoriesExpanded.value;

    chartData.value?.categories.forEach((category) => {
        expandedCategories.value[category.id] = expand;
    });
}

type VisibleRoom = { room: ChartRoom; category: ChartCategory };

const visibleRooms = computed<VisibleRoom[]>(() => {
    const result: VisibleRoom[] = [];

    chartData.value?.categories.forEach((category) => {
        if (expandedCategories.value[category.id]) {
            category.rooms.forEach((room) => result.push({ room, category }));
        }
    });

    return result;
});

const roomIdToVisibleIndex = computed<Record<number, number>>(() =>
    Object.fromEntries(
        visibleRooms.value.map((visibleRoom, index) => [
            visibleRoom.room.id,
            index,
        ]),
    ),
);

const allRooms = computed<ChartRoom[]>(
    () =>
        chartData.value?.categories.flatMap((category) => category.rooms) ?? [],
);

function toIso(date: Date): string {
    return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;
}

function addDaysIso(iso: string, days: number): string {
    const date = new Date(`${iso}T00:00:00`);
    date.setDate(date.getDate() + days);

    return toIso(date);
}

// ── Excel-like cell selection: rows = rooms, columns = nights ──
const isSelecting = ref(false);
const selectionStart = ref<{ roomIndex: number; dayIndex: number } | null>(
    null,
);
const selectionCurrent = ref<{ roomIndex: number; dayIndex: number } | null>(
    null,
);

const selectionBounds = computed(() => {
    if (!selectionStart.value || !selectionCurrent.value) {
        return null;
    }

    return {
        minRoom: Math.min(
            selectionStart.value.roomIndex,
            selectionCurrent.value.roomIndex,
        ),
        maxRoom: Math.max(
            selectionStart.value.roomIndex,
            selectionCurrent.value.roomIndex,
        ),
        minDay: Math.min(
            selectionStart.value.dayIndex,
            selectionCurrent.value.dayIndex,
        ),
        maxDay: Math.max(
            selectionStart.value.dayIndex,
            selectionCurrent.value.dayIndex,
        ),
    };
});

type Selection = {
    checkIn: string;
    checkOut: string;
    nights: number;
    rooms: { id: number; room_category_id: number }[];
};

const selection = ref<Selection | null>(null);
const showActionPopover = ref(false);
const actionPopoverPosition = ref({ x: 200, y: 150 });

function isCellSelected(roomIndex: number, dayIndex: number): boolean {
    if (!selectionBounds.value) {
        return false;
    }

    const { minRoom, maxRoom, minDay, maxDay } = selectionBounds.value;

    return (
        roomIndex >= minRoom &&
        roomIndex <= maxRoom &&
        dayIndex >= minDay &&
        dayIndex <= maxDay
    );
}

function selectionEdgeClasses(roomIndex: number, dayIndex: number): string[] {
    const bounds = selectionBounds.value;

    if (!bounds || !isCellSelected(roomIndex, dayIndex)) {
        return [];
    }

    return [
        roomIndex === bounds.minRoom ? 'border-t-2 border-t-sky-500' : '',
        roomIndex === bounds.maxRoom ? 'border-b-2 border-b-sky-500' : '',
        dayIndex === bounds.minDay ? 'border-l-2 border-l-sky-500' : '',
        dayIndex === bounds.maxDay ? 'border-r-2 border-r-sky-500' : '',
    ];
}

/** Past days can be viewed but not booked, held or blocked. */
function isPastDay(dayIndex: number): boolean {
    return chartData.value?.dates[dayIndex]?.is_past ?? false;
}

/** A past night, or a night the room already has a booking, hold or block on (a full dorm). */
function isCellFilled(roomIndex: number, dayIndex: number): boolean {
    const room = visibleRooms.value[roomIndex]?.room;
    const date = chartData.value?.dates[dayIndex]?.date;

    if (!room || !date) {
        return true;
    }

    return (
        isPastDay(dayIndex) ||
        roomConflict(room, date, addDaysIso(date, 1)) !== null
    );
}

/**
 * Where a selection dragged towards the target may end: it grows along the starting
 * row up to the cell before the first filled one, then across rows up to the row
 * before the first one with a filled cell in those nights. Filled cells are never crossed.
 */
function clampedSelectionEnd(
    start: { roomIndex: number; dayIndex: number },
    target: { roomIndex: number; dayIndex: number },
): { roomIndex: number; dayIndex: number } {
    const dayStep = Math.sign(target.dayIndex - start.dayIndex);
    let dayIndex = start.dayIndex;

    while (
        dayIndex !== target.dayIndex &&
        !isCellFilled(start.roomIndex, dayIndex + dayStep)
    ) {
        dayIndex += dayStep;
    }

    const minDay = Math.min(start.dayIndex, dayIndex);
    const maxDay = Math.max(start.dayIndex, dayIndex);
    const rowIsFree = (row: number): boolean => {
        for (let day = minDay; day <= maxDay; day++) {
            if (isCellFilled(row, day)) {
                return false;
            }
        }

        return true;
    };

    const roomStep = Math.sign(target.roomIndex - start.roomIndex);
    let roomIndex = start.roomIndex;

    while (roomIndex !== target.roomIndex && rowIsFree(roomIndex + roomStep)) {
        roomIndex += roomStep;
    }

    return { roomIndex, dayIndex };
}

function onCellMouseDown(
    roomIndex: number,
    dayIndex: number,
    event: MouseEvent,
): void {
    if (event.button !== 0 || isCellFilled(roomIndex, dayIndex)) {
        return;
    }

    event.preventDefault();
    showActionPopover.value = false;
    blockDetails.value = null;
    isSelecting.value = true;
    selectionStart.value = { roomIndex, dayIndex };
    selectionCurrent.value = { roomIndex, dayIndex };
}

function onCellMouseEnter(roomIndex: number, dayIndex: number): void {
    if (isSelecting.value && selectionStart.value) {
        selectionCurrent.value = clampedSelectionEnd(selectionStart.value, {
            roomIndex,
            dayIndex,
        });
    }
}

/**
 * Number of rooms = rows spanned; check-in = date of the first column,
 * check-out = the morning after the last selected night.
 */
function onGlobalMouseUp(event: MouseEvent): void {
    if (barDrag.value) {
        finishBarDrag();

        return;
    }

    if (!isSelecting.value) {
        return;
    }

    isSelecting.value = false;

    if (!selectionBounds.value || !chartData.value) {
        return;
    }

    const { minRoom, maxRoom, minDay, maxDay } = selectionBounds.value;
    const checkIn = chartData.value.dates[minDay].date;
    const checkOut = addDaysIso(chartData.value.dates[maxDay].date, 1);

    // A selection dragged over cells that are already booked or on hold can't become a new booking there.
    // Only booked / on-hold cells are refused here; blocked cells are left to the Block/Unblock actions.
    const taken = visibleRooms.value
        .slice(minRoom, maxRoom + 1)
        .map(({ room }) => ({
            room,
            conflict: roomConflict(room, checkIn, checkOut),
        }))
        .find(({ conflict }) => conflict !== null && conflict.kind !== 'block');

    if (taken?.conflict) {
        clearSelection();
        showToast(
            `${taken.room.name} is already ${taken.conflict.kind === 'hold' ? 'on hold' : 'booked'} (${taken.conflict.label}) on those dates — select free cells only.`,
            true,
        );

        return;
    }

    selection.value = {
        checkIn,
        checkOut,
        nights: maxDay - minDay + 1,
        rooms: visibleRooms.value
            .slice(minRoom, maxRoom + 1)
            .map(({ room }) => ({
                id: room.id,
                room_category_id: room.room_category_id,
            })),
    };

    if (scrollContainerRef.value) {
        const bounds = scrollContainerRef.value.getBoundingClientRect();
        actionPopoverPosition.value = {
            x: Math.max(
                8,
                Math.min(event.clientX - bounds.left - 100, bounds.width - 300),
            ),
            y: Math.max(40, event.clientY - bounds.top + 12),
        };
    }

    showActionPopover.value = true;
}

function clearSelection(): void {
    showActionPopover.value = false;
    selection.value = null;
    selectionStart.value = null;
    selectionCurrent.value = null;
}

function openQuickBook(options: { hold: boolean }): void {
    if (!selection.value) {
        return;
    }

    router.visit(
        admin.bookings.create(props.property, {
            query: {
                check_in: selection.value.checkIn,
                check_out: selection.value.checkOut,
                room_ids: selection.value.rooms
                    .map((room) => room.id)
                    .join(','),
                ...(options.hold ? { hold: 1 } : {}),
            },
        }).url,
    );
}

function handleBook(): void {
    openQuickBook({ hold: false });
}

/** Hold opens Quick Book in hold mode, which adds the Reminder section and saves the booking as On Hold. */
function handleHold(): void {
    openQuickBook({ hold: true });
}

/** Block forms start on the first reason from Application Settings → Block Unblock Config. */
function defaultBlockReasonId(): number | null {
    return chartData.value?.block_reasons[0]?.id ?? null;
}

// ── Success notification ──
const toastMessage = ref('');
const toastIsError = ref(false);
let toastTimer: ReturnType<typeof setTimeout> | undefined;

function showToast(message: string, isError = false): void {
    toastMessage.value = message;
    toastIsError.value = isError;
    clearTimeout(toastTimer);
    toastTimer = setTimeout(
        () => (toastMessage.value = ''),
        isError ? 5000 : 3500,
    );
}

// ── Block (confirmation for exactly the selected cells) ──
const showBlockConfirm = ref(false);
const blockConfirmForm = ref({
    block_reason_id: null as number | null,
    name: '',
    remarks: '',
});
const blockConfirmErrors = ref<Record<string, string>>({});
const blockConfirmSubmitting = ref(false);

function openBlockConfirm(): void {
    showActionPopover.value = false;
    blockConfirmForm.value = {
        block_reason_id: defaultBlockReasonId(),
        name: '',
        remarks: '',
    };
    blockConfirmErrors.value = {};
    showBlockConfirm.value = true;
}

function closeBlockConfirm(): void {
    showBlockConfirm.value = false;
    clearSelection();
}

async function confirmBlock(): Promise<void> {
    if (!selection.value || blockConfirmSubmitting.value) {
        return;
    }

    blockConfirmSubmitting.value = true;

    const result = await postJson(admin.roomBlocks.store(props.property).url, {
        block_reason_id: blockConfirmForm.value.block_reason_id,
        start_date: selection.value.checkIn,
        end_date: selection.value.checkOut,
        room_ids: selection.value.rooms.map((room) => room.id),
        name: blockConfirmForm.value.name || null,
        remarks: blockConfirmForm.value.remarks,
    });

    blockConfirmSubmitting.value = false;
    blockConfirmErrors.value = result.errors;

    if (result.ok) {
        const count = selection.value.rooms.length;
        closeBlockConfirm();
        showToast(
            `${count} ${count === 1 ? 'room' : 'rooms'} blocked successfully.`,
        );
        refreshChart();
    }
}

// ── Blocked bar details popover + unblock ──
type BlockDetails = {
    block: ChartBlock;
    room: ChartRoom;
    x: number;
    y: number;
};

const blockDetails = ref<BlockDetails | null>(null);
const showUnblockConfirm = ref(false);
const unblockSubmitting = ref(false);
const unblockError = ref('');

function formatBlockedAt(iso: string | null): string {
    if (!iso) {
        return '';
    }

    const date = new Date(iso);
    const months = [
        'Jan',
        'Feb',
        'Mar',
        'Apr',
        'May',
        'Jun',
        'Jul',
        'Aug',
        'Sep',
        'Oct',
        'Nov',
        'Dec',
    ];
    const pad = (value: number): string => String(value).padStart(2, '0');
    const hours = date.getHours() % 12 || 12;

    return `${pad(date.getDate())}-${months[date.getMonth()]}-${date.getFullYear()} ${hours}:${pad(date.getMinutes())}:${pad(date.getSeconds())} ${date.getHours() < 12 ? 'am' : 'pm'}`;
}

function openBlockDetails(
    block: ChartBlock,
    room: ChartRoom,
    event: MouseEvent,
): void {
    clearSelection();

    const container = scrollContainerRef.value?.getBoundingClientRect();
    const barBounds = (
        event.currentTarget as HTMLElement
    ).getBoundingClientRect();

    blockDetails.value = {
        block,
        room,
        x: container
            ? Math.max(
                  8,
                  Math.min(
                      barBounds.left - container.left,
                      container.width - 260,
                  ),
              )
            : 8,
        y: container ? barBounds.bottom - container.top + 8 : 40,
    };
}

function closeBlockDetails(): void {
    blockDetails.value = null;
    showUnblockConfirm.value = false;
    unblockError.value = '';
}

async function confirmUnblock(): Promise<void> {
    if (!blockDetails.value || unblockSubmitting.value) {
        return;
    }

    const { block, room } = blockDetails.value;
    unblockSubmitting.value = true;

    const result = await postJson(
        admin.roomBlocks.unblock(props.property).url,
        {
            start_date: block.start_date,
            end_date: block.end_date,
            room_ids: [room.id],
        },
    );

    unblockSubmitting.value = false;

    if (!result.ok) {
        unblockError.value =
            Object.values(result.errors)[0] ?? 'Could not unblock the room.';

        return;
    }

    closeBlockDetails();
    showToast(`${room.name} unblocked successfully.`);
    refreshChart();
}

/** Bulk Unblock opens the bulk form pre-filled with this block's room and dates. */
function openBulkUnblock(): void {
    if (!blockDetails.value) {
        return;
    }

    const { block, room } = blockDetails.value;
    selection.value = {
        checkIn: block.start_date,
        checkOut: block.end_date,
        nights: 0,
        rooms: [{ id: room.id, room_category_id: room.room_category_id }],
    };
    closeBlockDetails();
    openBulkBlockModal();
}

// ── Bulk Block modal ──
const showBulkBlockModal = ref(false);
const bulkBlockSubmitting = ref(false);
const bulkBlockErrors = ref<Record<string, string>>({});
const bulkBlockForm = ref({
    block_reason_id: null as number | null,
    start_date: '',
    end_date: '',
    category_ids: [] as number[],
    room_ids: [] as number[],
    name: '',
    remarks: '',
});

const openDropdown = ref<'categories' | 'rooms' | null>(null);
const categorySearch = ref('');
const roomSearch = ref('');

const filteredCategories = computed(() => {
    const query = categorySearch.value.trim().toLowerCase();

    return (chartData.value?.categories ?? []).filter(
        (category) =>
            !query ||
            category.name.toLowerCase().includes(query) ||
            category.abbreviation.toLowerCase().includes(query),
    );
});

const roomsInSelectedCategories = computed(() =>
    allRooms.value.filter((room) =>
        bulkBlockForm.value.category_ids.includes(room.room_category_id),
    ),
);

const filteredRooms = computed(() => {
    const query = roomSearch.value.trim().toLowerCase();

    return roomsInSelectedCategories.value.filter(
        (room) => !query || room.name.toLowerCase().includes(query),
    );
});

const selectedCategoriesLabel = computed(() =>
    (chartData.value?.categories ?? [])
        .filter((category) =>
            bulkBlockForm.value.category_ids.includes(category.id),
        )
        .map((category) => category.name)
        .join(', '),
);

const selectedRoomsLabel = computed(() =>
    allRooms.value
        .filter((room) => bulkBlockForm.value.room_ids.includes(room.id))
        .map((room) => room.name)
        .join(', '),
);

/** Unblock is only offered when a selected room has a block inside the chosen dates. */
const canUnblock = computed(() => {
    const {
        start_date: startDate,
        end_date: endDate,
        room_ids: roomIds,
    } = bulkBlockForm.value;

    return allRooms.value.some(
        (room) =>
            roomIds.includes(room.id) &&
            room.blocks.some(
                (block) =>
                    block.start_date < endDate && block.end_date > startDate,
            ),
    );
});

function openBulkBlockModal(): void {
    if (!selection.value) {
        return;
    }

    showActionPopover.value = false;
    bulkBlockErrors.value = {};
    bulkBlockForm.value = {
        block_reason_id: defaultBlockReasonId(),
        start_date: selection.value.checkIn,
        end_date: selection.value.checkOut,
        category_ids: Array.from(
            new Set(selection.value.rooms.map((room) => room.room_category_id)),
        ),
        room_ids: selection.value.rooms.map((room) => room.id),
        name: '',
        remarks: '',
    };
    openDropdown.value = null;
    categorySearch.value = '';
    roomSearch.value = '';
    showBulkBlockModal.value = true;
}

function closeBulkBlockModal(): void {
    showBulkBlockModal.value = false;
    openDropdown.value = null;
    clearSelection();
}

function toggleDropdown(dropdown: 'categories' | 'rooms'): void {
    openDropdown.value = openDropdown.value === dropdown ? null : dropdown;
}

function roomIdsForCategory(categoryId: number): number[] {
    return allRooms.value
        .filter((room) => room.room_category_id === categoryId)
        .map((room) => room.id);
}

/** Picking a category selects all of its rooms; dropping it removes them. */
function toggleBulkBlockCategory(categoryId: number): void {
    const form = bulkBlockForm.value;
    const categoryRoomIds = roomIdsForCategory(categoryId);

    if (form.category_ids.includes(categoryId)) {
        form.category_ids = form.category_ids.filter((id) => id !== categoryId);
        form.room_ids = form.room_ids.filter(
            (id) => !categoryRoomIds.includes(id),
        );

        return;
    }

    form.category_ids = [...form.category_ids, categoryId];
    form.room_ids = Array.from(new Set([...form.room_ids, ...categoryRoomIds]));
}

function selectAllCategories(): void {
    bulkBlockForm.value.category_ids = (chartData.value?.categories ?? []).map(
        (category) => category.id,
    );
    bulkBlockForm.value.room_ids = allRooms.value.map((room) => room.id);
}

function deselectAllCategories(): void {
    bulkBlockForm.value.category_ids = [];
    bulkBlockForm.value.room_ids = [];
}

function toggleRoom(roomId: number): void {
    const form = bulkBlockForm.value;
    form.room_ids = form.room_ids.includes(roomId)
        ? form.room_ids.filter((id) => id !== roomId)
        : [...form.room_ids, roomId];
}

function selectAllRooms(): void {
    bulkBlockForm.value.room_ids = roomsInSelectedCategories.value.map(
        (room) => room.id,
    );
}

function deselectAllRooms(): void {
    bulkBlockForm.value.room_ids = [];
}

function getXsrfToken(): string {
    const match = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]*)/);

    return match ? decodeURIComponent(match[1]) : '';
}

async function postJson(
    url: string,
    payload: Record<string, unknown>,
    method: 'POST' | 'PATCH' = 'POST',
): Promise<{ ok: boolean; errors: Record<string, string> }> {
    try {
        const response = await fetch(url, {
            method,
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-XSRF-TOKEN': getXsrfToken(),
            },
            credentials: 'same-origin',
            body: JSON.stringify(payload),
        });

        if (response.status === 422) {
            const data = (await response.json()) as {
                errors: Record<string, string[]>;
            };

            return {
                ok: false,
                errors: Object.fromEntries(
                    Object.entries(data.errors).map(([key, messages]) => [
                        key.split('.')[0],
                        messages[0],
                    ]),
                ),
            };
        }

        return response.ok
            ? { ok: true, errors: {} }
            : {
                  ok: false,
                  errors: {
                      general: 'Something went wrong. Please try again.',
                  },
              };
    } catch {
        return {
            ok: false,
            errors: { general: 'Network error. Please try again.' },
        };
    }
}

async function submitRoomBlocks(action: 'block' | 'unblock'): Promise<void> {
    if (bulkBlockSubmitting.value) {
        return;
    }

    const form = bulkBlockForm.value;
    const url =
        action === 'block'
            ? admin.roomBlocks.store(props.property).url
            : admin.roomBlocks.unblock(props.property).url;
    const payload =
        action === 'block'
            ? {
                  block_reason_id: form.block_reason_id,
                  start_date: form.start_date,
                  end_date: form.end_date,
                  room_ids: form.room_ids,
                  name: form.name || null,
                  remarks: form.remarks,
              }
            : {
                  start_date: form.start_date,
                  end_date: form.end_date,
                  room_ids: form.room_ids,
              };

    bulkBlockSubmitting.value = true;

    const result = await postJson(url, payload);

    bulkBlockSubmitting.value = false;
    bulkBlockErrors.value = result.errors;

    if (result.ok) {
        const count = form.room_ids.length;
        closeBulkBlockModal();
        showToast(
            `${count} ${count === 1 ? 'room' : 'rooms'} ${action === 'block' ? 'blocked' : 'unblocked'} successfully.`,
        );
        refreshChart();
    }
}

// ── Fetch / navigation ──
/** Where the horizontal scroll lands after a load: the first day, the last day, or unchanged (refresh). */
type ScrollLanding = 'start' | 'end' | 'keep';

/** Pixels kept from an edge after an edge-triggered load, so landing there doesn't immediately trigger another load. */
const EDGE_GAP = 24;
let ignoreScrollUntil = 0;

function scrollChartTo(left: number): void {
    const container = scrollContainerRef.value;

    if (!container) {
        return;
    }

    // Scrolls the chart makes itself must not count as the user reaching an edge.
    ignoreScrollUntil = Date.now() + 300;
    container.scrollLeft = left;
}

async function fetchChartData(
    startDate: string,
    landing: ScrollLanding = 'start',
): Promise<void> {
    if (loading.value) {
        return;
    }

    loading.value = true;

    try {
        const response = await fetch(
            admin.smartBookingChartData({
                query: {
                    property_id: props.property.id,
                    start_date: startDate,
                },
            }).url,
            {
                headers: { Accept: 'application/json' },
            },
        );

        if (response.ok) {
            const previousScrollLeft =
                scrollContainerRef.value?.scrollLeft ?? 0;
            chartData.value = await response.json();
            lastRefreshedAt.value = Date.now();
            clearSelection();
            await nextTick();

            const container = scrollContainerRef.value;

            if (container) {
                const maxScroll = container.scrollWidth - container.clientWidth;
                scrollChartTo(
                    landing === 'keep'
                        ? Math.min(previousScrollLeft, maxScroll)
                        : landing === 'end'
                          ? Math.max(0, maxScroll - EDGE_GAP)
                          : 0,
                );
            }
        }
    } finally {
        loading.value = false;
    }
}

/**
 * Dragging the scrollbar to the far right loads the next range; to the far
 * left, the previous one — never earlier than today.
 */
function onChartScroll(): void {
    closeBookingSummary();
    closeNotesPopover();

    const container = scrollContainerRef.value;

    if (
        !container ||
        !chartData.value ||
        loading.value ||
        Date.now() < ignoreScrollUntil
    ) {
        return;
    }

    const maxScroll = container.scrollWidth - container.clientWidth;

    if (maxScroll <= 0) {
        return;
    }

    if (container.scrollLeft >= maxScroll - 2) {
        fetchChartData(
            addDaysIso(chartData.value.start_date, navigationStepDays.value),
            'start',
        ).then(() => {
            if (scrollContainerRef.value?.scrollLeft === 0) {
                scrollChartTo(EDGE_GAP);
            }
        });
    } else if (container.scrollLeft <= 2 && chartData.value.can_go_prev) {
        const previousStart = addDaysIso(
            chartData.value.start_date,
            -navigationStepDays.value,
        );
        fetchChartData(
            previousStart < chartData.value.today
                ? chartData.value.today
                : previousStart,
            'end',
        );
    }
}

// ── Go to date / reservation search / Quick Create ──
const datePickerRef = ref<HTMLInputElement | null>(null);

function openDatePicker(): void {
    const input = datePickerRef.value;

    if (!input) {
        return;
    }

    try {
        input.showPicker();
    } catch {
        input.focus();
        input.click();
    }
}

/** The picked day becomes the first column; past days can be viewed too. */
function onDatePicked(event: Event): void {
    const picked = (event.target as HTMLInputElement).value;

    if (!picked || !chartData.value) {
        return;
    }

    fetchChartData(picked);
}

const reservationSearch = ref('');

function searchReservation(): void {
    const term = reservationSearch.value.trim();

    if (!term) {
        return;
    }

    router.visit(
        admin.databases.bookings.index({
            query: { property: props.property.id, q: term },
        }).url,
    );
}

const quickCreateOpen = ref(false);
const quickCreateRef = ref<HTMLElement | null>(null);

function closeQuickCreateOnOutsideClick(event: MouseEvent): void {
    if (
        quickCreateOpen.value &&
        quickCreateRef.value &&
        !quickCreateRef.value.contains(event.target as Node)
    ) {
        quickCreateOpen.value = false;
    }
}

function goToToday(): void {
    if (chartData.value) {
        fetchChartData(chartData.value.today);
    }
}

function shiftDays(days: number): void {
    if (!chartData.value) {
        return;
    }

    fetchChartData(addDaysIso(chartData.value.start_date, days));
}

// ── "Last refreshed … ago" beside the refresh button ──
// Both clocks start on the client (onMounted): a time taken during SSR would differ from
// the browser's and make hydration mismatch.
const lastRefreshedAt = ref<number | null>(null);
const clockNow = ref(0);
let clockTimer: ReturnType<typeof setInterval> | undefined;
let lastAutoRefreshAttemptAt = 0;

/**
 * Something the user is in the middle of that a reload would throw away:
 * a selection, its Book/Hold/Block popover, a bar being moved or a dialog.
 */
function isUserBusyOnChart(): boolean {
    return (
        isSelecting.value ||
        showActionPopover.value ||
        barDrag.value !== null ||
        pendingMove.value !== null ||
        showBlockConfirm.value ||
        blockDetails.value !== null ||
        showBulkBlockModal.value
    );
}

/** Reloads the chart once the auto-refresh time has passed, waiting while the user is busy on it or the tab is hidden. */
function autoRefreshIfDue(): void {
    const minutes = chartData.value?.auto_refresh_minutes ?? 0;

    if (
        minutes <= 0 ||
        lastRefreshedAt.value === null ||
        loading.value ||
        document.hidden ||
        isUserBusyOnChart() ||
        clockNow.value - lastRefreshedAt.value < minutes * 60_000 ||
        // A failed reload is retried after 30 seconds, not every second.
        clockNow.value - lastAutoRefreshAttemptAt < 30_000
    ) {
        return;
    }

    lastAutoRefreshAttemptAt = clockNow.value;
    refreshChart();
}

onMounted(() => {
    clockNow.value = Date.now();
    lastRefreshedAt.value = props.initialData ? clockNow.value : null;
    clockTimer = setInterval(() => {
        clockNow.value = Date.now();
        autoRefreshIfDue();
    }, 1000);
});
onBeforeUnmount(() => clearInterval(clockTimer));

const lastRefreshedLabel = computed(() => {
    if (lastRefreshedAt.value === null) {
        return '';
    }

    const seconds = Math.max(
        0,
        Math.floor((clockNow.value - lastRefreshedAt.value) / 1000),
    );
    const plural = (count: number, unit: string): string =>
        `${count} ${unit}${count === 1 ? '' : 's'}`;

    if (seconds < 60) {
        return `Last refreshed ${plural(seconds, 'second')} ago`;
    }

    if (seconds < 3600) {
        return `Last refreshed ${plural(Math.floor(seconds / 60), 'minute')} ago`;
    }

    return `Last refreshed ${plural(Math.floor(seconds / 3600), 'hour')} ago`;
});

function refreshChart(): void {
    if (chartData.value) {
        fetchChartData(chartData.value.start_date, 'keep');
    }
}

// ── Bars (bookings and blocks) ──
type Bar = {
    key: string;
    startIndex: number;
    span: number;
    label: string;
    title: string;
    style: Record<string, string>;
    bookingId: number | null;
    block: ChartBlock | null;
    /** Real stay dates; startIndex/span are clipped to the visible window. */
    startDate: string;
    endDate: string;
    subBookingId: number | null;
    reference: string;
    movable: boolean;
    roomsCount: number;
};

const hatched = (light: string, dark: string): string =>
    `repeating-linear-gradient(45deg, ${light}, ${light} 6px, ${dark} 6px, ${dark} 12px)`;

/** Places a [start, end) stay on the visible columns; null when it falls outside the window. */
function barPlacement(
    start: string,
    end: string,
): { startIndex: number; span: number } | null {
    const dates = chartData.value?.dates ?? [];

    if (!dates.length) {
        return null;
    }

    const windowStart = dates[0].date;
    const windowEnd = addDaysIso(dates[dates.length - 1].date, 1);

    if (end <= windowStart || start >= windowEnd) {
        return null;
    }

    const startIndex =
        start <= windowStart ? 0 : dates.findIndex((d) => d.date === start);
    const endIndex =
        end >= windowEnd
            ? dates.length
            : dates.findIndex((d) => d.date === end);

    return { startIndex, span: Math.max(1, endIndex - startIndex) };
}

/** Height of one bed's lane in a dorm room's row. */
const BED_LANE_HEIGHT = 26;

/** A dorm room's row is one lane per bed tall; other rooms keep the standard height. */
function roomRowStyle(room: ChartRoom): Record<string, string> {
    return room.beds ? { height: `${room.beds * BED_LANE_HEIGHT}px` } : {};
}

function barsForRoom(room: ChartRoom): Bar[] {
    const bars: Bar[] = [];

    room.bookings.forEach((booking) => {
        const placement = barPlacement(booking.check_in, booking.check_out);

        if (!placement) {
            return;
        }

        // In a dorm room each stay sits in its bed's lane.
        const lane = room.beds
            ? Math.min(room.beds, Math.max(1, booking.bed ?? 1)) - 1
            : null;
        const laneStyle: Record<string, string> =
            lane === null
                ? {}
                : {
                      top: `${lane * BED_LANE_HEIGHT + 2}px`,
                      height: `${BED_LANE_HEIGHT - 4}px`,
                      bottom: 'auto',
                  };

        bars.push({
            key: `booking-${booking.sub_booking_id}`,
            ...placement,
            label: booking.is_emergency
                ? 'EMERGENCY'
                : booking.is_blocked
                  ? 'BLOCKED'
                  : `${lane === null ? '' : `B${lane + 1} · `}${booking.guest_name.toUpperCase()}${booking.status === 'on_hold' ? ' (ON HOLD)' : ''}`,
            title: `${booking.guest_name} (${booking.booking_ref_id})${lane === null ? '' : ` | Bed ${lane + 1}`} | ${booking.check_in} → ${booking.check_out} | ${booking.status_label}`,
            style: {
                ...laneStyle,
                background: booking.is_emergency
                    ? hatched('#94a3b8', '#64748b')
                    : booking.is_blocked
                      ? hatched('#cbd5e1', '#94a3b8')
                      : booking.status === 'checked_in'
                        ? '#16a34a'
                        : booking.status === 'on_hold'
                          ? '#d97706'
                          : '#ea580c',
            },
            bookingId: booking.booking_id,
            block: null,
            startDate: booking.check_in,
            endDate: booking.check_out,
            subBookingId: booking.sub_booking_id,
            reference: booking.booking_ref_id,
            movable: !['cancelled', 'checked_out', 'no_show'].includes(
                booking.status,
            ),
            roomsCount: booking.booking_rooms_count ?? 1,
        });
    });

    room.blocks.forEach((block) => {
        const placement = barPlacement(block.start_date, block.end_date);

        if (!placement) {
            return;
        }

        bars.push({
            key: `block-${block.id}`,
            ...placement,
            label: (block.name || block.reason_label).toUpperCase(),
            title: `${block.reason_label} block | ${block.start_date} → ${block.end_date}${block.name ? ` | ${block.name}` : ''} | ${block.remarks}`,
            style: {
                background: block.is_maintenance
                    ? hatched('#94a3b8', '#64748b')
                    : hatched('#fcd34d', '#f59e0b'),
                color: '#1b1b18',
            },
            bookingId: null,
            block,
            startDate: block.start_date,
            endDate: block.end_date,
            subBookingId: null,
            reference: block.reason_label,
            movable: true,
            roomsCount: 1,
        });
    });

    return bars;
}

const barsByRoomAndStart = computed<Record<number, Record<number, Bar[]>>>(
    () => {
        const result: Record<number, Record<number, Bar[]>> = {};

        allRooms.value.forEach((room) => {
            result[room.id] = {};
            barsForRoom(room).forEach((bar) => {
                (result[room.id][bar.startIndex] ??= []).push(bar);
            });
        });

        return result;
    },
);

// ── Drag a bar to move it: vertically = another room, horizontally = other dates; length is kept ──
type BarDrag = {
    bar: Bar;
    room: ChartRoom;
    /** Which visible day of the bar was grabbed, so the bar keeps its position under the pointer. */
    grabOffset: number;
    startX: number;
    startY: number;
    moved: boolean;
    targetRoomIndex: number;
    targetDayIndex: number;
};

const barDrag = ref<BarDrag | null>(null);
/** Set when a drag ends, so the click that follows mouseup doesn't also open the bar. */
let suppressBarClick = false;

function onBarMouseDown(bar: Bar, room: ChartRoom, event: MouseEvent): void {
    if (event.button !== 0 || !bar.movable) {
        return;
    }

    event.preventDefault();
    clearSelection();
    blockDetails.value = null;

    const barLeft = (event.currentTarget as HTMLElement).getBoundingClientRect()
        .left;
    const grabOffset = Math.min(
        bar.span - 1,
        Math.max(0, Math.floor((event.clientX - barLeft) / DATE_COLUMN_WIDTH)),
    );

    barDrag.value = {
        bar,
        room,
        grabOffset,
        startX: event.clientX,
        startY: event.clientY,
        moved: false,
        targetRoomIndex: roomIdToVisibleIndex.value[room.id],
        targetDayIndex: bar.startIndex + grabOffset,
    };
}

function onGlobalMouseMove(event: MouseEvent): void {
    const drag = barDrag.value;

    if (
        drag &&
        !drag.moved &&
        Math.hypot(event.clientX - drag.startX, event.clientY - drag.startY) > 4
    ) {
        drag.moved = true;
    }
}

function onCellDragEnter(roomIndex: number, dayIndex: number): void {
    if (barDrag.value?.moved) {
        barDrag.value.targetRoomIndex = roomIndex;
        barDrag.value.targetDayIndex = dayIndex;
    }
}

function isDragPreviewCell(roomIndex: number, dayIndex: number): boolean {
    const drag = barDrag.value;

    if (!drag?.moved || roomIndex !== drag.targetRoomIndex) {
        return false;
    }

    const firstDay = drag.targetDayIndex - drag.grabOffset;

    return dayIndex >= firstDay && dayIndex < firstDay + drag.bar.span;
}

/**
 * Another booking, hold or block on this room overlapping [start, end) — ignoring the bar
 * being moved. Only what the chart has loaded (the visible dates) is known; the server
 * checks the rest.
 */
/** A dorm room's stays on one night (each holds one bed), leaving out the one being moved. */
function bedsTakenOn(room: ChartRoom, date: string, ignore?: Bar): number {
    return room.bookings.filter(
        (entry) =>
            !(ignore && ignore.subBookingId === entry.sub_booking_id) &&
            entry.check_in <= date &&
            entry.check_out > date,
    ).length;
}

function isBlockedOn(room: ChartRoom, date: string, ignore?: Bar): boolean {
    return room.blocks.some(
        (entry) =>
            !(ignore && ignore.block?.id === entry.id) &&
            entry.start_date <= date &&
            entry.end_date > date,
    );
}

/** A dorm room's free beds on one night: none while it is blocked. */
function freeBedsOn(room: ChartRoom, date: string): number {
    return isBlockedOn(room, date)
        ? 0
        : Math.max(0, (room.beds ?? 0) - bedsTakenOn(room, date));
}

/**
 * A dorm room only clashes on a night when every bed is taken (or it is blocked); until then
 * a new stay takes a free bed, which the server assigns.
 */
function dormConflict(
    room: ChartRoom,
    start: string,
    end: string,
    ignore?: Bar,
): { kind: 'booking' | 'hold' | 'block'; label: string } | null {
    for (let night = start; night < end; night = addDaysIso(night, 1)) {
        if (isBlockedOn(room, night, ignore)) {
            const block = room.blocks.find(
                (entry) => entry.start_date <= night && entry.end_date > night,
            );

            return { kind: 'block', label: block?.reason_label ?? 'blocked' };
        }

        if (bedsTakenOn(room, night, ignore) >= (room.beds ?? 0)) {
            return {
                kind: 'booking',
                label: `all ${room.beds} beds taken on ${night}`,
            };
        }
    }

    return null;
}

function roomConflict(
    room: ChartRoom,
    start: string,
    end: string,
    ignore?: Bar,
): { kind: 'booking' | 'hold' | 'block'; label: string } | null {
    if (room.beds) {
        return dormConflict(room, start, end, ignore);
    }

    const booking = room.bookings.find(
        (entry) =>
            !(ignore && ignore.subBookingId === entry.sub_booking_id) &&
            entry.check_in < end &&
            entry.check_out > start,
    );

    if (booking) {
        return {
            kind: booking.status === 'on_hold' ? 'hold' : 'booking',
            label: `${booking.guest_name}, ${booking.booking_ref_id}`,
        };
    }

    const block = room.blocks.find(
        (entry) =>
            !(ignore && ignore.block?.id === entry.id) &&
            entry.start_date < end &&
            entry.end_date > start,
    );

    return block ? { kind: 'block', label: block.reason_label } : null;
}

/** The drop target of the bar being dragged is taken by someone else (shown in red). */
const dragConflict = computed(() => {
    const drag = barDrag.value;

    if (!drag?.moved) {
        return null;
    }

    const toRoom = visibleRooms.value[drag.targetRoomIndex]?.room;
    const dayDelta =
        drag.targetDayIndex - drag.grabOffset - drag.bar.startIndex;

    return toRoom
        ? roomConflict(
              toRoom,
              addDaysIso(drag.bar.startDate, dayDelta),
              addDaysIso(drag.bar.endDate, dayDelta),
              drag.bar,
          )
        : null;
});

type PriceChange = {
    current_total: number;
    new_total: number;
    difference: number;
};

type PendingMove = {
    bar: Bar;
    fromRoom: ChartRoom;
    toRoom: ChartRoom;
    startDate: string;
    endDate: string;
    /** For a date move of a multi-room booking: just this room (splits the booking) or all rooms. */
    scope: 'booking' | 'room' | null;
    price: PriceChange | null;
    priceLoading: boolean;
};

const pendingMove = ref<PendingMove | null>(null);
const moveSubmitting = ref(false);
const moveError = ref('');

function finishBarDrag(): void {
    const drag = barDrag.value;
    barDrag.value = null;

    if (!drag?.moved) {
        return;
    }

    suppressBarClick = true;
    setTimeout(() => (suppressBarClick = false), 0);

    const toRoom = visibleRooms.value[drag.targetRoomIndex]?.room;
    const dayDelta =
        drag.targetDayIndex - drag.grabOffset - drag.bar.startIndex;

    if (!toRoom || (toRoom.id === drag.room.id && dayDelta === 0)) {
        return;
    }

    // Stays can't be swapped: the target cells must be free.
    const conflict = roomConflict(
        toRoom,
        addDaysIso(drag.bar.startDate, dayDelta),
        addDaysIso(drag.bar.endDate, dayDelta),
        drag.bar,
    );

    if (conflict) {
        const taken =
            conflict.kind === 'block'
                ? 'blocked'
                : conflict.kind === 'hold'
                  ? 'on hold'
                  : 'booked';
        showToast(
            `Can't move here: ${toRoom.name} is already ${taken} (${conflict.label}) on those dates. Bookings can't be swapped — free the cells first.`,
            true,
        );

        return;
    }

    moveError.value = '';
    pendingMove.value = {
        bar: drag.bar,
        fromRoom: drag.room,
        toRoom,
        startDate: addDaysIso(drag.bar.startDate, dayDelta),
        endDate: addDaysIso(drag.bar.endDate, dayDelta),
        scope: needsScopeChoice(drag.bar, dayDelta) ? null : 'booking',
        price: null,
        priceLoading: false,
    };
}

/** Moving the dates of one room of a multi-room booking: ask whether just this room moves. */
function needsScopeChoice(bar: Bar, dayDelta: number): boolean {
    return !bar.block && dayDelta !== 0 && bar.roomsCount > 1;
}

function formatMoney(value: number): string {
    return amount(value);
}

/** Picks the scope; for "only this room", shows what the booking total becomes. */
async function chooseMoveScope(scope: 'booking' | 'room'): Promise<void> {
    const move = pendingMove.value;

    if (!move) {
        return;
    }

    move.scope = scope;
    move.price = null;

    if (scope !== 'room' || !move.bar.bookingId || !move.bar.subBookingId) {
        return;
    }

    move.priceLoading = true;

    try {
        const response = await fetch(
            admin.bookings.rooms.datesPreview({
                property: props.property.id,
                booking: move.bar.bookingId,
                subBooking: move.bar.subBookingId,
            }).url,
            {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-XSRF-TOKEN': getXsrfToken(),
                },
                credentials: 'same-origin',
                body: JSON.stringify({
                    check_in: move.startDate,
                    check_out: move.endDate,
                }),
            },
        );

        if (response.ok && pendingMove.value === move) {
            move.price = (await response.json()) as PriceChange;
        }
    } finally {
        move.priceLoading = false;
    }
}

function priceNote(price: PriceChange | null): string {
    if (!price) {
        return '';
    }

    return Math.abs(price.difference) < 0.01
        ? ' The total is unchanged.'
        : ` Total changed from ${formatMoney(price.current_total)} to ${formatMoney(price.new_total)}.`;
}

function formatShortDate(iso: string): string {
    return new Date(`${iso}T00:00:00`).toLocaleDateString('en-GB', {
        day: '2-digit',
        month: 'short',
        year: 'numeric',
    });
}

async function confirmMove(): Promise<void> {
    const move = pendingMove.value;

    if (!move || moveSubmitting.value || move.scope === null) {
        return;
    }

    moveSubmitting.value = true;

    const result = move.bar.block
        ? await postJson(
              admin.roomBlocks.move({
                  property: props.property.id,
                  roomBlock: move.bar.block.id,
              }).url,
              { room_id: move.toRoom.id, start_date: move.startDate },
              'PATCH',
          )
        : await postJson(
              admin.bookings.move({
                  property: props.property.id,
                  booking: move.bar.bookingId!,
              }).url,
              {
                  sub_booking_id: move.bar.subBookingId,
                  room_id: move.toRoom.id,
                  check_in: move.startDate,
                  scope: move.scope,
              },
              'PATCH',
          );

    moveSubmitting.value = false;

    if (!result.ok) {
        moveError.value =
            Object.values(result.errors)[0] ?? 'Could not move it.';

        return;
    }

    pendingMove.value = null;
    showToast(
        move.bar.block
            ? 'Block moved successfully.'
            : move.scope === 'room'
              ? `Room moved; booking ${move.bar.reference} is now split.${priceNote(move.price)}`
              : `Booking ${move.bar.reference} moved successfully.`,
    );
    refreshChart();
}

function onBarClick(bar: Bar, room: ChartRoom, event: MouseEvent): void {
    if (suppressBarClick) {
        return;
    }

    if (bar.block) {
        openBlockDetails(bar.block, room, event);

        return;
    }

    if (bar.bookingId !== null) {
        openBookingSummary(bar.bookingId, event);
    }
}

// ── Booking summary popover (booked / on-hold bars) ──
type BookingSummary = {
    id: number;
    booking_ref_id: string;
    status: string;
    status_label: string;
    check_in: string;
    check_out: string;
    nights: number;
    room_lines: string[];
    guest: { name: string; phone: string | null; email: string | null } | null;
    source: string;
    total: number;
    outstanding: number;
    editable: boolean;
};

const BOOKING_POPOVER_WIDTH = 320;
const BOOKING_POPOVER_HEIGHT = 530;

const bookingPopover = ref<{
    bookingId: number;
    summary: BookingSummary | null;
    error: string;
    x: number;
    y: number;
} | null>(null);
const bookingPopoverRef = ref<HTMLElement | null>(null);

/** Opens beside the clicked bar (right, or left when there's no room), kept inside the window. */
async function openBookingSummary(
    bookingId: number,
    event: MouseEvent,
): Promise<void> {
    clearSelection();
    closeBlockDetails();

    const bar = (event.currentTarget as HTMLElement).getBoundingClientRect();
    const fitsRight =
        bar.right + 8 + BOOKING_POPOVER_WIDTH <= window.innerWidth - 8;
    const x = fitsRight
        ? bar.right + 8
        : Math.max(8, bar.left - 8 - BOOKING_POPOVER_WIDTH);
    const y = Math.max(
        8,
        Math.min(bar.top - 40, window.innerHeight - BOOKING_POPOVER_HEIGHT - 8),
    );

    bookingPopover.value = { bookingId, summary: null, error: '', x, y };
    confirmingCancel.value = false;
    bookingActionError.value = '';

    try {
        const response = await fetch(
            admin.bookings.summary({
                property: props.property.id,
                booking: bookingId,
            }).url,
            {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
            },
        );

        if (bookingPopover.value?.bookingId !== bookingId) {
            return;
        }

        if (!response.ok) {
            bookingPopover.value.error = 'Could not load this booking.';

            return;
        }

        bookingPopover.value.summary =
            (await response.json()) as BookingSummary;
    } catch {
        if (bookingPopover.value?.bookingId === bookingId) {
            bookingPopover.value.error = 'Network error. Please try again.';
        }
    }
}

function closeBookingSummary(): void {
    bookingPopover.value = null;
    confirmingCancel.value = false;
    bookingActionError.value = '';
}

// ── Popover actions: Cancel Booking (confirmed), Check In (confirmed, arriving today), Undo Check In ──
const confirmingCancel = ref(false);
const bookingActionBusy = ref(false);
const bookingActionError = ref('');

/** Guests can arrive from the check-in date until the day before check-out (as on the booking page). */
const canCheckInToday = computed(() => {
    const summary = bookingPopover.value?.summary;
    const today = chartData.value?.today;

    return (
        summary?.status === 'confirmed' &&
        !!today &&
        summary.check_in <= today &&
        today < summary.check_out
    );
});

async function changeBookingStatus(
    status: 'cancelled' | 'checked_in' | 'confirmed',
    successMessage: string,
): Promise<void> {
    const summary = bookingPopover.value?.summary;

    if (!summary || bookingActionBusy.value) {
        return;
    }

    bookingActionBusy.value = true;
    bookingActionError.value = '';

    try {
        const response = await fetch(
            admin.bookings.updateStatus({
                property: props.property.id,
                booking: summary.id,
            }).url,
            {
                method: 'PATCH',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-XSRF-TOKEN': getXsrfToken(),
                },
                credentials: 'same-origin',
                body: JSON.stringify({ status }),
            },
        );
        const data = (await response.json().catch(() => ({}))) as {
            message?: string;
        };

        if (!response.ok) {
            bookingActionError.value =
                data.message ||
                'The booking could not be updated. Please try again.';

            return;
        }

        closeBookingSummary();
        showToast(successMessage);
        refreshChart();
    } catch {
        bookingActionError.value = 'Network error. Please try again.';
    } finally {
        bookingActionBusy.value = false;
    }
}

// ── Housekeeping: room name → "Mark as Clean / Dirty / …" ──
const housekeepingMenu = ref<{
    room: ChartRoom;
    top: number;
    left: number;
} | null>(null);
const housekeepingBusy = ref(false);

function openHousekeepingMenu(room: ChartRoom, event: MouseEvent): void {
    const rect = (event.currentTarget as HTMLElement).getBoundingClientRect();
    housekeepingMenu.value = { room, top: rect.bottom + 4, left: rect.left };
}

async function markHousekeeping(status: HousekeepingStatus): Promise<void> {
    const menu = housekeepingMenu.value;

    if (!menu || housekeepingBusy.value) {
        return;
    }

    housekeepingBusy.value = true;

    try {
        const response = await fetch(
            admin.rooms.housekeeping({
                property: props.property.id,
                room: menu.room.id,
            }).url,
            {
                method: 'PATCH',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-XSRF-TOKEN': getXsrfToken(),
                },
                credentials: 'same-origin',
                body: JSON.stringify({ status: status.name }),
            },
        );
        const data = (await response.json().catch(() => ({}))) as {
            message?: string;
            housekeeping?: HousekeepingStatus | null;
        };

        if (!response.ok) {
            showToast(
                data.message || 'The housekeeping status could not be changed.',
                true,
            );

            return;
        }

        menu.room.housekeeping = data.housekeeping ?? status;
        housekeepingMenu.value = null;
        showToast(
            data.message || `${menu.room.name} marked as ${status.name}.`,
        );
    } catch {
        showToast('Network error. Please try again.', true);
    } finally {
        housekeepingBusy.value = false;
    }
}

/** Readable text on a housekeeping colour: dark on light colours, white on dark ones. */
function textColorOn(hex: string | undefined): string {
    const match = hex?.match(/^#?([0-9a-f]{2})([0-9a-f]{2})([0-9a-f]{2})$/i);

    if (!match) {
        return '#ffffff';
    }

    const [red, green, blue] = match.slice(1).map((part) => parseInt(part, 16));

    return red * 0.299 + green * 0.587 + blue * 0.114 > 160
        ? '#1b1b18'
        : '#ffffff';
}

function closeHousekeepingMenuOnOutsideClick(event: MouseEvent): void {
    if (
        housekeepingMenu.value &&
        !(event.target as HTMLElement).closest(
            '[data-housekeeping-menu], [data-housekeeping-trigger]',
        )
    ) {
        housekeepingMenu.value = null;
    }
}

onMounted(() =>
    document.addEventListener('mousedown', closeHousekeepingMenuOnOutsideClick),
);
onBeforeUnmount(() =>
    document.removeEventListener(
        'mousedown',
        closeHousekeepingMenuOnOutsideClick,
    ),
);

// ── Date notes ──
const page = usePage();
const propertyTime = usePropertyTime();
const currentUserId = computed(() => page.props.auth.user?.id ?? null);
const notesPopover = ref<{ date: ChartDate; top: number; left: number } | null>(
    null,
);
const newNoteBody = ref('');
const editingNoteId = ref<number | null>(null);
const editingNoteBody = ref('');
const noteSaving = ref(false);
const noteError = ref('');

function openNotesPopover(date: ChartDate, event: MouseEvent): void {
    const rect = (event.currentTarget as HTMLElement).getBoundingClientRect();
    const width = 288;

    notesPopover.value = {
        date,
        top: rect.bottom + 6,
        left: Math.max(8, Math.min(rect.left, window.innerWidth - width - 8)),
    };
    newNoteBody.value = '';
    editingNoteId.value = null;
    noteError.value = '';
}

function closeNotesPopover(): void {
    notesPopover.value = null;
}

function startEditingNote(note: ChartNote): void {
    editingNoteId.value = note.id;
    editingNoteBody.value = note.body;
    noteError.value = '';
}

/** Adds a note to the open date, or saves the note being edited. */
async function saveNote(): Promise<void> {
    const popover = notesPopover.value;
    const editing = editingNoteId.value;
    const body = (editing ? editingNoteBody.value : newNoteBody.value).trim();

    if (!popover || noteSaving.value || !body) {
        return;
    }

    noteSaving.value = true;
    noteError.value = '';

    try {
        const response = await fetch(
            editing
                ? admin.chartNotes.update({
                      property: props.property.id,
                      chartNote: editing,
                  }).url
                : admin.chartNotes.store(props.property.id).url,
            {
                method: editing ? 'PUT' : 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-XSRF-TOKEN': getXsrfToken(),
                },
                credentials: 'same-origin',
                body: JSON.stringify(
                    editing ? { body } : { date: popover.date.date, body },
                ),
            },
        );
        const data = (await response.json().catch(() => ({}))) as {
            notes?: ChartNote[];
            message?: string;
            errors?: Record<string, string[]>;
        };

        if (!response.ok) {
            noteError.value =
                Object.values(data.errors ?? {})[0]?.[0] ??
                data.message ??
                'The note could not be saved.';

            return;
        }

        popover.date.notes = data.notes ?? [];
        newNoteBody.value = '';
        editingNoteId.value = null;
    } catch {
        noteError.value = 'Network error. Please try again.';
    } finally {
        noteSaving.value = false;
    }
}

function closeNotesPopoverOnOutsideClick(event: MouseEvent): void {
    if (
        notesPopover.value &&
        !(event.target as HTMLElement).closest(
            '[data-chart-notes], [data-chart-notes-trigger]',
        )
    ) {
        closeNotesPopover();
    }
}

function closeNotesPopoverOnEscape(event: KeyboardEvent): void {
    if (event.key === 'Escape') {
        closeNotesPopover();
    }
}

onMounted(() => {
    document.addEventListener('mousedown', closeNotesPopoverOnOutsideClick);
    document.addEventListener('keydown', closeNotesPopoverOnEscape);
    window.addEventListener('resize', closeNotesPopover);
});
onBeforeUnmount(() => {
    document.removeEventListener('mousedown', closeNotesPopoverOnOutsideClick);
    document.removeEventListener('keydown', closeNotesPopoverOnEscape);
    window.removeEventListener('resize', closeNotesPopover);
});

function closeBookingSummaryOnOutsideClick(event: MouseEvent): void {
    if (
        bookingPopover.value &&
        bookingPopoverRef.value &&
        !bookingPopoverRef.value.contains(event.target as Node)
    ) {
        closeBookingSummary();
    }
}

function closeBookingSummaryOnEscape(event: KeyboardEvent): void {
    if (event.key === 'Escape') {
        closeBookingSummary();
    }
}

onMounted(() => {
    window.addEventListener('mouseup', onGlobalMouseUp);
    window.addEventListener('mousemove', onGlobalMouseMove);
    document.addEventListener('click', closeQuickCreateOnOutsideClick);
    document.addEventListener('mousedown', closeBookingSummaryOnOutsideClick);
    document.addEventListener('keydown', closeBookingSummaryOnEscape);
    window.addEventListener('resize', closeBookingSummary);
});
onBeforeUnmount(() => {
    window.removeEventListener('mouseup', onGlobalMouseUp);
    window.removeEventListener('mousemove', onGlobalMouseMove);
    document.removeEventListener('click', closeQuickCreateOnOutsideClick);
    document.removeEventListener(
        'mousedown',
        closeBookingSummaryOnOutsideClick,
    );
    document.removeEventListener('keydown', closeBookingSummaryOnEscape);
    window.removeEventListener('resize', closeBookingSummary);
});
</script>

<template>
    <div class="flex min-w-0 flex-col gap-4 select-none">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h2
                class="font-display text-2xl font-semibold text-[#1b1b18] dark:text-[#EDEDEC]"
            >
                Smart Booking Chart
            </h2>
        </div>

        <div class="flex flex-wrap items-center gap-1.5">
            <div class="relative">
                <button
                    type="button"
                    title="Go to date"
                    class="rounded-md border border-[#e3e3e0] bg-white px-3 py-1.5 text-sm text-[#1b1b18] hover:bg-gray-50 dark:border-[#3E3E3A] dark:bg-[#161615] dark:text-[#EDEDEC]"
                    @click="openDatePicker"
                >
                    <svg
                        class="h-4 w-4"
                        fill="none"
                        viewBox="0 0 24 24"
                        stroke="currentColor"
                        aria-hidden="true"
                    >
                        <path
                            stroke-linecap="round"
                            stroke-linejoin="round"
                            stroke-width="2"
                            d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"
                        />
                    </svg>
                </button>
                <!-- Native picker opened by the button; sits under it so the popup anchors there. -->
                <input
                    ref="datePickerRef"
                    type="date"
                    tabindex="-1"
                    aria-label="Go to date"
                    :value="chartData?.start_date"
                    class="pointer-events-none absolute inset-0 h-full w-full opacity-0"
                    @change="onDatePicked"
                />
            </div>
            <button
                type="button"
                class="rounded-md border border-[#e3e3e0] bg-white px-3 py-1.5 text-sm font-medium text-[#1b1b18] hover:bg-gray-50 dark:border-[#3E3E3A] dark:bg-[#161615] dark:text-[#EDEDEC]"
                @click="goToToday"
            >
                Today
            </button>
            <button
                type="button"
                :title="`Previous ${navigationStepDays} days`"
                :disabled="loading"
                class="rounded-md border border-[#e3e3e0] bg-white px-3 py-1.5 text-sm font-bold text-[#1b1b18] hover:bg-gray-50 disabled:cursor-not-allowed disabled:opacity-40 dark:border-[#3E3E3A] dark:bg-[#161615] dark:text-[#EDEDEC]"
                @click="shiftDays(-navigationStepDays)"
            >
                ‹
            </button>
            <button
                type="button"
                :title="`Next ${navigationStepDays} days`"
                :disabled="loading"
                class="rounded-md border border-[#e3e3e0] bg-white px-3 py-1.5 text-sm font-bold text-[#1b1b18] hover:bg-gray-50 disabled:cursor-not-allowed disabled:opacity-40 dark:border-[#3E3E3A] dark:bg-[#161615] dark:text-[#EDEDEC]"
                @click="shiftDays(navigationStepDays)"
            >
                ›
            </button>
            <button
                type="button"
                title="Refresh"
                :disabled="loading"
                class="rounded-md border border-[#e3e3e0] bg-white px-3 py-1.5 text-sm hover:bg-gray-50 disabled:opacity-50 dark:border-[#3E3E3A] dark:bg-[#161615] dark:text-[#EDEDEC]"
                @click="refreshChart"
            >
                <span :class="{ 'inline-block animate-spin': loading }">⟳</span>
            </button>
            <span
                v-if="lastRefreshedLabel"
                class="text-xs text-[#706f6c] dark:text-[#A1A09A]"
                data-test="chart-last-refreshed"
                >{{ lastRefreshedLabel }}</span
            >

            <div class="ml-auto flex items-center gap-2">
                <form
                    class="flex items-center"
                    role="search"
                    title="Search for reservation"
                    @submit.prevent="searchReservation"
                >
                    <input
                        v-model="reservationSearch"
                        type="search"
                        placeholder="Reservation ID"
                        aria-label="Search for reservation"
                        title="Search for reservation"
                        class="w-56 rounded-l-md border border-[#e3e3e0] bg-white px-3 py-1.5 text-sm text-[#1b1b18] focus:border-[#f53003] focus:ring-1 focus:ring-[#f53003] focus:outline-none dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]"
                    />
                    <button
                        type="submit"
                        title="Search for reservation"
                        class="rounded-r-md border border-l-0 border-[#e3e3e0] bg-white px-3 py-1.5 text-sm text-[#1b1b18] hover:bg-gray-50 dark:border-[#3E3E3A] dark:bg-[#161615] dark:text-[#EDEDEC]"
                    >
                        <svg
                            class="h-4 w-4"
                            fill="none"
                            viewBox="0 0 24 24"
                            stroke="currentColor"
                            aria-hidden="true"
                        >
                            <path
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                stroke-width="2"
                                d="M21 21l-4.35-4.35M11 18a7 7 0 100-14 7 7 0 000 14z"
                            />
                        </svg>
                    </button>
                </form>
                <button
                    type="button"
                    class="rounded-md border border-[#e3e3e0] bg-white px-4 py-1.5 text-sm font-medium text-[#1b1b18] hover:bg-gray-50 dark:border-[#3E3E3A] dark:bg-[#161615] dark:text-[#EDEDEC]"
                >
                    Manage Channels
                </button>
                <div ref="quickCreateRef" class="relative">
                    <button
                        type="button"
                        aria-haspopup="menu"
                        :aria-expanded="quickCreateOpen"
                        class="rounded-md border border-black bg-[#1b1b18] px-4 py-1.5 text-sm font-medium text-white hover:bg-black dark:border-[#eeeeec] dark:bg-[#eeeeec] dark:text-[#1C1C1A] dark:hover:bg-white"
                        @click="quickCreateOpen = !quickCreateOpen"
                    >
                        Quick Create ▾
                    </button>
                    <div
                        v-if="quickCreateOpen"
                        role="menu"
                        class="absolute right-0 z-50 mt-1 w-40 overflow-hidden rounded-md border border-[#e3e3e0] bg-white py-1 shadow-lg dark:border-[#3E3E3A] dark:bg-[#161615]"
                    >
                        <Link
                            :href="admin.bookings.create(props.property).url"
                            role="menuitem"
                            class="block px-4 py-2 text-sm text-[#1b1b18] hover:bg-[#f5f5f4] dark:text-[#EDEDEC] dark:hover:bg-[#1D1D1B]"
                        >
                            Quick Book
                        </Link>
                        <Link
                            :href="
                                admin.bookings.create(props.property, {
                                    query: { hold: 1 },
                                }).url
                            "
                            role="menuitem"
                            class="block px-4 py-2 text-sm text-[#1b1b18] hover:bg-[#f5f5f4] dark:text-[#EDEDEC] dark:hover:bg-[#1D1D1B]"
                        >
                            Quick Hold
                        </Link>
                        <Link
                            :href="
                                admin.bookings.create(props.property, {
                                    query: { enquiry: 1 },
                                }).url
                            "
                            role="menuitem"
                            class="block px-4 py-2 text-sm text-[#1b1b18] hover:bg-[#f5f5f4] dark:text-[#EDEDEC] dark:hover:bg-[#1D1D1B]"
                        >
                            Log Enquiry
                        </Link>
                    </div>
                </div>
            </div>
        </div>

        <div class="relative">
            <div
                v-if="loading"
                class="absolute inset-0 z-40 flex items-center justify-center bg-white/60 dark:bg-black/60"
            >
                <div
                    class="flex items-center gap-2 rounded-md bg-[#1e293b] px-4 py-2 text-sm text-white shadow-lg"
                >
                    <span class="animate-spin">⟳</span> Loading chart data...
                </div>
            </div>

            <!-- The only horizontal scrollbar: the grid has a fixed pixel width and scrolls inside this box. -->
            <div
                ref="scrollContainerRef"
                @scroll.passive="onChartScroll"
                class="smart-chart-scroll relative overflow-x-auto overflow-y-hidden rounded-lg border border-[#3e4854] bg-white shadow-sm dark:border-[#3E3E3A] dark:bg-[#161615]"
            >
                <table
                    class="border-collapse text-sm"
                    :style="{ width: `${tableWidth}px`, tableLayout: 'fixed' }"
                >
                    <colgroup>
                        <col :style="{ width: `${LABEL_COLUMN_WIDTH}px` }" />
                        <col
                            v-for="d in chartData?.dates ?? []"
                            :key="d.date"
                            :style="{ width: `${DATE_COLUMN_WIDTH}px` }"
                        />
                    </colgroup>

                    <thead>
                        <tr class="bg-[#3f4a56] text-white">
                            <th
                                class="sticky left-0 z-30 border-r border-[#526070] bg-[#3f4a56] px-3 py-2.5 text-left text-sm font-bold tracking-wider"
                            >
                                {{
                                    chartData?.dates[0]?.year ??
                                    new Date().getFullYear()
                                }}
                            </th>
                            <th
                                v-for="d in chartData?.dates ?? []"
                                :key="d.date"
                                class="border-r border-[#526070] px-2 py-2 text-center text-sm font-semibold whitespace-nowrap"
                                :class="
                                    d.is_today
                                        ? 'bg-[#c2621c]'
                                        : d.is_weekend
                                          ? 'bg-[#55606d]'
                                          : 'bg-[#3f4a56]'
                                "
                            >
                                <span class="inline-flex items-center gap-1">
                                    {{ d.formatted }}
                                    <HolidayMarker
                                        v-if="d.holiday"
                                        :holiday="d.holiday"
                                        class="rounded-sm bg-white p-0.5"
                                    />
                                </span>
                                <span
                                    class="mt-1 flex items-center justify-center gap-2 text-[11px] font-semibold"
                                >
                                    <Link
                                        :href="
                                            admin.databases.enquiries.index({
                                                query: {
                                                    property: props.property.id,
                                                    arrival_from: d.date,
                                                    arrival_to: d.date,
                                                },
                                            }).url
                                        "
                                        class="inline-flex items-center gap-0.5 rounded px-1 py-0.5 hover:bg-white/15"
                                        :title="`${d.enquiries_count ?? 0} ${(d.enquiries_count ?? 0) === 1 ? 'enquiry' : 'enquiries'} checking in`"
                                    >
                                        <svg
                                            class="h-3.5 w-3.5"
                                            viewBox="0 0 24 24"
                                            fill="none"
                                            stroke="currentColor"
                                            stroke-width="2"
                                            aria-hidden="true"
                                        >
                                            <path
                                                stroke-linecap="round"
                                                stroke-linejoin="round"
                                                d="M8 10h.01M12 10h.01M16 10h.01M21 12c0 4.4-4 8-9 8a9.9 9.9 0 0 1-4.3-1L3 20l1.4-3.7A7.4 7.4 0 0 1 3 12c0-4.4 4-8 9-8s9 3.6 9 8Z"
                                            />
                                        </svg>
                                        {{ d.enquiries_count ?? 0 }}
                                    </Link>
                                    <button
                                        type="button"
                                        data-chart-notes-trigger
                                        class="inline-flex items-center gap-0.5 rounded px-1 py-0.5 hover:bg-white/15"
                                        :title="`${d.notes?.length ?? 0} ${(d.notes?.length ?? 0) === 1 ? 'note' : 'notes'}`"
                                        @click="openNotesPopover(d, $event)"
                                    >
                                        <svg
                                            class="h-3.5 w-3.5"
                                            viewBox="0 0 24 24"
                                            fill="none"
                                            stroke="currentColor"
                                            stroke-width="2"
                                            aria-hidden="true"
                                        >
                                            <path
                                                stroke-linecap="round"
                                                stroke-linejoin="round"
                                                d="M9 4h6M8 3h8a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2Zm1 7h6m-6 4h6m-6 4h3"
                                            />
                                        </svg>
                                        {{ d.notes?.length ?? 0 }}
                                    </button>
                                </span>
                            </th>
                        </tr>
                    </thead>

                    <tbody>
                        <tr
                            class="cursor-pointer border-b border-gray-300 bg-[#e2e8f0] font-bold hover:bg-[#d7dfea] dark:border-[#3E3E3A] dark:bg-[#1e293b] dark:text-[#EDEDEC]"
                            :title="
                                allCategoriesExpanded
                                    ? 'Collapse all rooms'
                                    : 'Expand all rooms'
                            "
                            :aria-expanded="allCategoriesExpanded"
                            @click="toggleAllCategories"
                        >
                            <td
                                class="sticky left-0 z-30 border-r border-gray-300 bg-[#e2e8f0] px-3 py-2 text-sm font-bold dark:border-[#3E3E3A] dark:bg-[#1e293b]"
                            >
                                <span
                                    class="mr-1.5 inline-block text-gray-500"
                                    >{{
                                        allCategoriesExpanded ? '▾' : '▸'
                                    }}</span
                                >
                                Total
                            </td>
                            <td
                                v-for="d in chartData?.dates ?? []"
                                :key="d.date"
                                class="border-r border-gray-300 px-2 py-2 text-center text-sm dark:border-[#3E3E3A]"
                            >
                                {{
                                    chartData?.daily_totals[d.date]
                                        ?.available ?? 0
                                }}
                                AVL.
                            </td>
                        </tr>

                        <template
                            v-for="category in chartData?.categories ?? []"
                            :key="category.id"
                        >
                            <tr
                                class="cursor-pointer border-b border-gray-200 bg-[#f1f5f9] hover:bg-[#e2e8f0] dark:border-[#2a2a28] dark:bg-[#1c1c1b] dark:hover:bg-[#262624]"
                                @click="toggleCategory(category.id)"
                            >
                                <td
                                    class="sticky left-0 z-30 border-r border-gray-200 bg-[#f1f5f9] px-3 py-2 text-sm font-bold text-[#1b1b18] dark:border-[#2a2a28] dark:bg-[#1c1c1b] dark:text-[#EDEDEC]"
                                >
                                    <span
                                        class="mr-1.5 inline-block text-gray-500"
                                        >{{
                                            expandedCategories[category.id]
                                                ? '▾'
                                                : '▸'
                                        }}</span
                                    >
                                    {{ category.abbreviation || category.name }}
                                </td>
                                <td
                                    v-for="d in chartData?.dates ?? []"
                                    :key="d.date"
                                    class="border-r border-gray-200 px-2 py-2 text-center text-sm font-semibold dark:border-[#2a2a28]"
                                >
                                    <span
                                        v-if="
                                            category.daily_availability[d.date]
                                                ?.is_sold_out
                                        "
                                        class="font-bold text-red-600 dark:text-red-400"
                                        >SOLD OUT</span
                                    >
                                    <span
                                        v-else
                                        class="inline-flex items-center gap-1 text-slate-800 dark:text-slate-200"
                                    >
                                        {{
                                            category.daily_availability[d.date]
                                                ?.available ?? 0
                                        }}
                                        AVL.
                                        <svg
                                            class="h-3.5 w-3.5 text-green-600 dark:text-green-500"
                                            viewBox="0 0 20 20"
                                            fill="currentColor"
                                            aria-label="Available"
                                        >
                                            <path
                                                fill-rule="evenodd"
                                                d="M10 18a8 8 0 1 0 0-16 8 8 0 0 0 0 16Zm3.857-9.809a.75.75 0 0 0-1.214-.882l-3.483 4.79-1.88-1.88a.75.75 0 1 0-1.06 1.061l2.5 2.5a.75.75 0 0 0 1.137-.089l4-5.5Z"
                                                clip-rule="evenodd"
                                            />
                                        </svg>
                                    </span>
                                </td>
                            </tr>

                            <template v-if="expandedCategories[category.id]">
                                <tr
                                    v-for="room in category.rooms"
                                    :key="room.id"
                                    class="border-b border-gray-200 bg-white dark:border-[#262625] dark:bg-[#161615]"
                                >
                                    <td
                                        class="sticky left-0 z-30 border-r border-gray-200 bg-white px-2 py-1.5 dark:border-[#262625] dark:bg-[#161615]"
                                    >
                                        <!-- Coloured by its housekeeping status; click to mark it Clean / Dirty / … -->
                                        <button
                                            type="button"
                                            data-housekeeping-trigger
                                            class="inline-flex cursor-pointer items-center gap-1 rounded px-2 py-0.5 text-sm font-bold"
                                            :style="{
                                                backgroundColor:
                                                    room.housekeeping?.color ??
                                                    '#ef4444',
                                                color: textColorOn(
                                                    room.housekeeping?.color ??
                                                        '#ef4444',
                                                ),
                                            }"
                                            :title="
                                                room.housekeeping
                                                    ? `${room.name}: ${room.housekeeping.name} — click to change`
                                                    : room.name
                                            "
                                            :aria-label="`${room.name}, housekeeping ${room.housekeeping?.name ?? 'not set'}`"
                                            @click="
                                                openHousekeepingMenu(
                                                    room,
                                                    $event,
                                                )
                                            "
                                        >
                                            {{ room.name }}
                                            <span class="text-[9px] opacity-70"
                                                >▾</span
                                            >
                                        </button>
                                        <span
                                            v-if="room.beds"
                                            class="ml-1 text-[10px] text-gray-500 dark:text-gray-400"
                                            >{{ room.beds }} beds</span
                                        >
                                    </td>

                                    <td
                                        v-for="(
                                            d, dayIndex
                                        ) in chartData?.dates ?? []"
                                        :key="d.date"
                                        class="relative h-10 border-r border-gray-200 p-0 dark:border-[#262625]"
                                        :style="roomRowStyle(room)"
                                        :class="[
                                            isDragPreviewCell(
                                                roomIdToVisibleIndex[room.id],
                                                dayIndex,
                                            )
                                                ? dragConflict
                                                    ? 'cursor-not-allowed bg-red-100 outline-2 -outline-offset-2 outline-red-500 outline-dashed dark:bg-red-950/60'
                                                    : 'bg-emerald-100 outline-2 -outline-offset-2 outline-emerald-500 outline-dashed dark:bg-emerald-950/60'
                                                : isCellSelected(
                                                        roomIdToVisibleIndex[
                                                            room.id
                                                        ],
                                                        dayIndex,
                                                    )
                                                  ? 'bg-sky-100/70 dark:bg-sky-950/60'
                                                  : d.is_past
                                                    ? 'cursor-not-allowed bg-gray-100 dark:bg-[#1b1b19]'
                                                    : 'hover:bg-gray-50 dark:hover:bg-[#1f1f1d]',
                                            ...selectionEdgeClasses(
                                                roomIdToVisibleIndex[room.id],
                                                dayIndex,
                                            ),
                                        ]"
                                        @mousedown="
                                            onCellMouseDown(
                                                roomIdToVisibleIndex[room.id],
                                                dayIndex,
                                                $event,
                                            )
                                        "
                                        @mouseenter="
                                            onCellMouseEnter(
                                                roomIdToVisibleIndex[room.id],
                                                dayIndex,
                                            );
                                            onCellDragEnter(
                                                roomIdToVisibleIndex[room.id],
                                                dayIndex,
                                            );
                                        "
                                    >
                                        <span
                                            v-if="room.beds && !d.is_past"
                                            class="pointer-events-none absolute right-1 bottom-0.5 text-[10px] leading-none"
                                            :class="
                                                freeBedsOn(room, d.date) === 0
                                                    ? 'font-semibold text-red-500'
                                                    : 'text-gray-400 dark:text-[#706f6c]'
                                            "
                                            :title="`${freeBedsOn(room, d.date)} of ${room.beds} beds free`"
                                            >{{ freeBedsOn(room, d.date) }}/{{
                                                room.beds
                                            }}</span
                                        >
                                        <div
                                            v-for="bar in barsByRoomAndStart[
                                                room.id
                                            ]?.[dayIndex] ?? []"
                                            :key="bar.key"
                                            class="absolute top-1.5 bottom-1.5 left-0.5 z-10 flex items-center overflow-hidden rounded px-2 text-sm font-bold tracking-wide text-white shadow-xs hover:opacity-90"
                                            :class="[
                                                bar.movable
                                                    ? 'cursor-grab'
                                                    : 'cursor-pointer',
                                                barDrag?.moved
                                                    ? 'pointer-events-none'
                                                    : '',
                                                barDrag?.moved &&
                                                barDrag.bar.key === bar.key
                                                    ? 'opacity-40'
                                                    : '',
                                            ]"
                                            :style="{
                                                ...bar.style,
                                                width: `${bar.span * DATE_COLUMN_WIDTH - 4}px`,
                                            }"
                                            :title="bar.title"
                                            @mousedown.stop="
                                                onBarMouseDown(
                                                    bar,
                                                    room,
                                                    $event,
                                                )
                                            "
                                            @click.stop="
                                                onBarClick(bar, room, $event)
                                            "
                                        >
                                            <span class="truncate">{{
                                                bar.label
                                            }}</span>
                                        </div>
                                    </td>
                                </tr>
                            </template>
                        </template>
                    </tbody>
                </table>
            </div>

            <!-- Blocked bar details -->
            <div
                v-if="blockDetails"
                class="absolute z-50 w-60 rounded-md border-2 border-[#1b1b18] bg-white p-3 text-sm shadow-xl dark:border-[#EDEDEC] dark:bg-[#1e1e1d] dark:text-[#EDEDEC]"
                :style="{
                    left: `${blockDetails.x}px`,
                    top: `${blockDetails.y}px`,
                }"
            >
                <div class="flex items-start justify-between gap-2">
                    <p class="font-semibold">
                        Blocked by :
                        <span class="text-[#f59e0b]">{{
                            blockDetails.block.blocked_by ?? 'Unknown'
                        }}</span>
                    </p>
                    <button
                        type="button"
                        class="text-xs text-gray-400 hover:text-gray-700 dark:hover:text-gray-200"
                        @click="closeBlockDetails"
                    >
                        ✕
                    </button>
                </div>
                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                    {{ blockDetails.block.reason_label
                    }}<template v-if="blockDetails.block.name">
                        · {{ blockDetails.block.name }}</template
                    >
                </p>
                <p class="mt-2 font-semibold">Remarks*</p>
                <p class="break-words">{{ blockDetails.block.remarks }}</p>
                <p class="mt-2 text-xs text-gray-600 dark:text-gray-400">
                    {{ formatBlockedAt(blockDetails.block.blocked_at) }}
                </p>
                <div class="mt-3 flex items-center justify-between">
                    <button
                        type="button"
                        class="text-sm underline hover:text-blue-700"
                        @click="openBulkUnblock"
                    >
                        Bulk Unblock
                    </button>
                    <button
                        type="button"
                        class="rounded bg-[#1b1b18] px-3 py-1.5 text-sm text-white hover:bg-black dark:bg-[#EDEDEC] dark:text-[#1b1b18]"
                        @click="showUnblockConfirm = true"
                    >
                        Unblock
                    </button>
                </div>
            </div>

            <!-- Floating action popover (X Rooms / Y Nights) -->
            <div
                v-if="showActionPopover && selection"
                class="absolute z-50 w-72 rounded-lg border border-gray-300 bg-white p-3 shadow-xl dark:border-[#3E3E3A] dark:bg-[#1e1e1d]"
                :style="{
                    left: `${actionPopoverPosition.x}px`,
                    top: `${actionPopoverPosition.y}px`,
                }"
            >
                <div
                    class="flex items-center justify-between border-b border-gray-200 pb-2 dark:border-[#333]"
                >
                    <span
                        class="text-sm font-bold text-gray-800 dark:text-gray-100"
                    >
                        {{ selection.rooms.length }}
                        {{ selection.rooms.length === 1 ? 'Room' : 'Rooms' }} /
                        {{ selection.nights }}
                        {{ selection.nights === 1 ? 'Night' : 'Nights' }}
                    </span>
                    <button
                        type="button"
                        class="text-sm font-bold text-gray-400 hover:text-gray-700 dark:hover:text-gray-200"
                        @click="clearSelection"
                    >
                        ✕
                    </button>
                </div>
                <div class="mt-3 flex items-center justify-between gap-2">
                    <button
                        type="button"
                        class="text-sm font-medium text-blue-600 underline hover:text-blue-800 dark:text-blue-400"
                        @click="openBulkBlockModal"
                    >
                        Bulk Block
                    </button>
                    <div class="flex items-center gap-1.5">
                        <button
                            type="button"
                            class="rounded border border-gray-300 bg-white px-2.5 py-1 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-[#3E3E3A] dark:bg-[#252524] dark:text-[#EDEDEC]"
                            @click="openBlockConfirm"
                        >
                            Block
                        </button>
                        <button
                            v-if="can('smart_chart.quick_create')"
                            type="button"
                            class="rounded bg-[#f59e0b] px-3 py-1 text-sm font-semibold text-white shadow-xs hover:bg-[#d97706]"
                            @click="handleHold"
                        >
                            Hold
                        </button>
                        <button
                            v-if="can('smart_chart.quick_create')"
                            type="button"
                            class="rounded bg-[#22c55e] px-3.5 py-1 text-sm font-semibold text-white shadow-xs hover:bg-[#16a34a]"
                            @click="handleBook"
                        >
                            Book
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Block confirmation (Block button) -->
        <div
            v-if="showBlockConfirm && selection"
            class="fixed inset-0 z-[100] flex items-start justify-center bg-black/40 p-4 pt-24"
            @mousedown.self="closeBlockConfirm"
        >
            <div
                class="w-full max-w-xl rounded-md bg-white shadow-2xl dark:bg-[#1e1e1d]"
            >
                <div
                    class="flex items-center justify-between border-b border-gray-200 px-4 py-3 dark:border-[#3E3E3A]"
                >
                    <h3 class="text-lg text-[#1b1b18] dark:text-[#EDEDEC]">
                        Remarks for Blocking Room(s)
                    </h3>
                    <button
                        type="button"
                        class="text-lg leading-none text-gray-400 hover:text-gray-700 dark:hover:text-gray-200"
                        @click="closeBlockConfirm"
                    >
                        ×
                    </button>
                </div>
                <div
                    class="flex flex-col gap-2 px-4 py-4 text-sm text-[#1b1b18] dark:text-[#EDEDEC]"
                >
                    <p>
                        Are you sure you want to block
                        {{
                            selection.rooms.length === 1 &&
                            selection.nights === 1
                                ? 'this room night'
                                : `${selection.rooms.length} room(s) for ${selection.nights} night(s)`
                        }}?
                    </p>
                    <p
                        v-if="
                            blockConfirmErrors.general ||
                            blockConfirmErrors.room_ids ||
                            blockConfirmErrors.block_reason_id
                        "
                        class="text-xs text-red-600"
                    >
                        {{
                            blockConfirmErrors.general ||
                            blockConfirmErrors.room_ids ||
                            blockConfirmErrors.block_reason_id
                        }}
                    </p>
                    <label class="mt-1 flex flex-col gap-1">
                        <span>Select reason for blocking</span>
                        <select
                            v-model="blockConfirmForm.block_reason_id"
                            class="rounded border border-gray-300 px-3 py-2 dark:border-[#3E3E3A] dark:bg-[#252524]"
                        >
                            <option
                                v-for="reason in chartData?.block_reasons ?? []"
                                :key="reason.id"
                                :value="reason.id"
                            >
                                {{ reason.name }}
                            </option>
                        </select>
                    </label>
                    <label class="flex flex-col gap-1">
                        <span>Name</span>
                        <input
                            v-model="blockConfirmForm.name"
                            type="text"
                            class="rounded border border-gray-300 px-3 py-2 dark:border-[#3E3E3A] dark:bg-[#252524]"
                        />
                    </label>
                    <label class="flex flex-col gap-1">
                        <span class="font-semibold"
                            >Remarks: <span class="text-red-600">*</span></span
                        >
                        <textarea
                            v-model="blockConfirmForm.remarks"
                            rows="2"
                            placeholder="Remarks"
                            class="rounded border border-gray-300 px-3 py-2 dark:border-[#3E3E3A] dark:bg-[#252524]"
                        ></textarea>
                        <span
                            v-if="blockConfirmErrors.remarks"
                            class="text-xs text-red-600"
                            >{{ blockConfirmErrors.remarks }}</span
                        >
                    </label>
                </div>
                <div
                    class="flex justify-end gap-2 border-t border-gray-200 px-4 py-3 dark:border-[#3E3E3A]"
                >
                    <button
                        type="button"
                        :disabled="blockConfirmSubmitting"
                        class="rounded bg-[#337ab7] px-4 py-1.5 text-sm text-white hover:bg-[#286090] disabled:opacity-50"
                        @click="confirmBlock"
                    >
                        Yes
                    </button>
                    <button
                        type="button"
                        class="rounded border border-gray-300 px-4 py-1.5 text-sm text-[#1b1b18] hover:bg-gray-50 dark:border-[#3E3E3A] dark:text-[#EDEDEC]"
                        @click="closeBlockConfirm"
                    >
                        No
                    </button>
                </div>
            </div>
        </div>

        <!-- Housekeeping: Mark as … -->
        <div
            v-if="housekeepingMenu"
            data-housekeeping-menu
            role="menu"
            class="fixed z-[115] min-w-44 rounded-md border border-gray-200 bg-white py-1 text-sm shadow-lg dark:border-[#3E3E3A] dark:bg-[#1e1e1d]"
            :style="{
                top: `${housekeepingMenu.top}px`,
                left: `${housekeepingMenu.left}px`,
            }"
        >
            <button
                v-for="status in chartData?.housekeeping_statuses ?? []"
                :key="status.name"
                type="button"
                role="menuitem"
                :disabled="housekeepingBusy"
                class="flex w-full items-center gap-2 px-3 py-1.5 text-left text-[#1b1b18] hover:bg-gray-100 disabled:opacity-50 dark:text-[#EDEDEC] dark:hover:bg-[#2a2a28]"
                @click="markHousekeeping(status)"
            >
                <span
                    class="inline-block h-3 w-3 shrink-0 rounded-sm border border-black/10"
                    :style="{ backgroundColor: status.color }"
                ></span>
                <span class="flex-1">Mark as {{ status.name }}</span>
                <span
                    v-if="
                        housekeepingMenu.room.housekeeping?.name === status.name
                    "
                    aria-label="current status"
                    >✔</span
                >
            </button>
        </div>

        <!-- Unblock confirmation -->
        <div
            v-if="showUnblockConfirm && blockDetails"
            class="fixed inset-0 z-[110] flex items-start justify-center bg-black/40 p-4 pt-24"
            @mousedown.self="showUnblockConfirm = false"
        >
            <div
                class="w-full max-w-md rounded-md bg-white shadow-2xl dark:bg-[#1e1e1d]"
            >
                <div
                    class="border-b border-gray-200 px-4 py-3 text-lg text-[#1b1b18] dark:border-[#3E3E3A] dark:text-[#EDEDEC]"
                >
                    Unblock Room
                </div>
                <div
                    class="px-4 py-4 text-sm text-[#1b1b18] dark:text-[#EDEDEC]"
                >
                    Are you sure you want to unblock
                    {{ blockDetails.room.name }} from
                    {{ blockDetails.block.start_date }} to
                    {{ blockDetails.block.end_date }}?
                    <p v-if="unblockError" class="mt-2 text-xs text-red-600">
                        {{ unblockError }}
                    </p>
                </div>
                <div
                    class="flex justify-end gap-2 border-t border-gray-200 px-4 py-3 dark:border-[#3E3E3A]"
                >
                    <button
                        type="button"
                        :disabled="unblockSubmitting"
                        class="rounded bg-[#337ab7] px-4 py-1.5 text-sm text-white hover:bg-[#286090] disabled:opacity-50"
                        @click="confirmUnblock"
                    >
                        Yes
                    </button>
                    <button
                        type="button"
                        class="rounded border border-gray-300 px-4 py-1.5 text-sm text-[#1b1b18] hover:bg-gray-50 dark:border-[#3E3E3A] dark:text-[#EDEDEC]"
                        @click="showUnblockConfirm = false"
                    >
                        No
                    </button>
                </div>
            </div>
        </div>

        <!-- Move confirmation (drag & drop) -->
        <div
            v-if="pendingMove"
            class="fixed inset-0 z-[110] flex items-start justify-center bg-black/40 p-4 pt-24"
            @mousedown.self="pendingMove = null"
        >
            <div
                class="w-full max-w-md rounded-md bg-white shadow-2xl dark:bg-[#1e1e1d]"
            >
                <div
                    class="border-b border-gray-200 px-4 py-3 text-lg text-[#1b1b18] dark:border-[#3E3E3A] dark:text-[#EDEDEC]"
                >
                    {{ pendingMove.bar.block ? 'Move Block' : 'Move Booking' }}
                </div>
                <div
                    class="flex flex-col gap-2 px-4 py-4 text-sm text-[#1b1b18] dark:text-[#EDEDEC]"
                >
                    <p>
                        Are you sure you want to move
                        <span class="font-semibold">{{
                            pendingMove.bar.label
                        }}</span>
                        <template v-if="!pendingMove.bar.block">
                            ({{ pendingMove.bar.reference }})</template
                        >?
                    </p>
                    <div
                        class="grid grid-cols-[auto_1fr] gap-x-3 gap-y-1 rounded bg-gray-50 px-3 py-2 dark:bg-[#252524]"
                    >
                        <span class="text-gray-500">From</span>
                        <span
                            >{{ pendingMove.fromRoom.name }} ·
                            {{ formatShortDate(pendingMove.bar.startDate) }} –
                            {{ formatShortDate(pendingMove.bar.endDate) }}</span
                        >
                        <span class="text-gray-500">To</span>
                        <span class="font-semibold"
                            >{{ pendingMove.toRoom.name }} ·
                            {{ formatShortDate(pendingMove.startDate) }} –
                            {{ formatShortDate(pendingMove.endDate) }}</span
                        >
                    </div>
                    <fieldset
                        v-if="
                            needsScopeChoice(
                                pendingMove.bar,
                                pendingMove.startDate ===
                                    pendingMove.bar.startDate
                                    ? 0
                                    : 1,
                            )
                        "
                        class="flex flex-col gap-1.5 rounded border border-amber-300 bg-amber-50 px-3 py-2 dark:border-amber-800 dark:bg-amber-950/30"
                    >
                        <legend
                            class="px-1 text-xs font-semibold text-amber-800 dark:text-amber-300"
                        >
                            This booking has
                            {{ pendingMove.bar.roomsCount }} rooms. Change the
                            dates for:
                        </legend>
                        <label class="flex items-start gap-2 text-sm">
                            <input
                                type="radio"
                                name="move-scope"
                                class="mt-0.5"
                                :checked="pendingMove.scope === 'room'"
                                @change="chooseMoveScope('room')"
                            />
                            <span>
                                Only this room
                                <span
                                    class="block text-xs text-gray-500 dark:text-gray-400"
                                    >The booking is split; the other rooms keep
                                    their dates.</span
                                >
                            </span>
                        </label>
                        <label class="flex items-start gap-2 text-sm">
                            <input
                                type="radio"
                                name="move-scope"
                                class="mt-0.5"
                                :checked="pendingMove.scope === 'booking'"
                                @change="chooseMoveScope('booking')"
                            />
                            <span
                                >All
                                {{ pendingMove.bar.roomsCount }} rooms</span
                            >
                        </label>
                    </fieldset>
                    <p
                        v-if="
                            pendingMove.scope === 'room' &&
                            pendingMove.priceLoading
                        "
                        class="text-xs text-gray-500"
                    >
                        Checking the price…
                    </p>
                    <p
                        v-else-if="
                            pendingMove.scope === 'room' &&
                            pendingMove.price &&
                            Math.abs(pendingMove.price.difference) >= 0.01
                        "
                        class="rounded bg-amber-100 px-3 py-2 text-xs font-semibold text-amber-900 dark:bg-amber-900/40 dark:text-amber-200"
                        role="alert"
                    >
                        The booking total will change from
                        {{ formatMoney(pendingMove.price.current_total) }} to
                        {{ formatMoney(pendingMove.price.new_total) }} ({{
                            pendingMove.price.difference > 0 ? '+' : '−'
                        }}{{
                            formatMoney(Math.abs(pendingMove.price.difference))
                        }}).
                    </p>
                    <p
                        v-else-if="!pendingMove.bar.block && pendingMove.scope"
                        class="text-xs text-gray-500 dark:text-gray-400"
                    >
                        The number of nights and the booking's total stay the
                        same.
                    </p>
                    <p v-if="moveError" class="text-xs text-red-600">
                        {{ moveError }}
                    </p>
                </div>
                <div
                    class="flex justify-end gap-2 border-t border-gray-200 px-4 py-3 dark:border-[#3E3E3A]"
                >
                    <button
                        type="button"
                        :disabled="
                            moveSubmitting ||
                            pendingMove.scope === null ||
                            pendingMove.priceLoading
                        "
                        class="rounded bg-[#337ab7] px-4 py-1.5 text-sm text-white hover:bg-[#286090] disabled:opacity-50"
                        @click="confirmMove"
                    >
                        Yes
                    </button>
                    <button
                        type="button"
                        class="rounded border border-gray-300 px-4 py-1.5 text-sm text-[#1b1b18] hover:bg-gray-50 dark:border-[#3E3E3A] dark:text-[#EDEDEC]"
                        @click="pendingMove = null"
                    >
                        No
                    </button>
                </div>
            </div>
        </div>

        <!-- Success notification -->
        <div
            v-if="toastMessage"
            class="fixed top-4 right-4 z-[120] max-w-md rounded-md px-4 py-3 text-sm font-medium text-white shadow-lg"
            :class="toastIsError ? 'bg-red-600' : 'bg-[#16a34a]'"
            :role="toastIsError ? 'alert' : 'status'"
        >
            {{ toastMessage }}
        </div>

        <!-- Bulk Block modal -->
        <div
            v-if="showBulkBlockModal"
            class="fixed inset-0 z-[100] flex items-center justify-center bg-black/30 p-4"
            @mousedown.self="closeBulkBlockModal"
        >
            <div
                class="w-full max-w-sm overflow-visible rounded-md bg-white shadow-2xl dark:bg-[#1e1e1d]"
            >
                <div
                    class="flex items-center justify-between rounded-t-md bg-[#2f3337] px-3 py-2.5 text-white"
                >
                    <h3 class="flex items-center gap-2 text-lg font-medium">
                        <svg
                            class="h-5 w-5 text-[#f59e0b]"
                            viewBox="0 0 24 24"
                            fill="none"
                            stroke="currentColor"
                            stroke-width="2.5"
                            aria-hidden="true"
                        >
                            <circle cx="12" cy="12" r="9" />
                            <path d="m5.6 5.6 12.8 12.8" />
                        </svg>
                        Bulk Block
                    </h3>
                    <button
                        type="button"
                        class="text-lg leading-none text-white/80 hover:text-white"
                        @click="closeBulkBlockModal"
                    >
                        X
                    </button>
                </div>

                <div
                    class="flex flex-col gap-3 px-3 py-3 text-sm text-[#1b1b18] dark:text-[#EDEDEC]"
                >
                    <p
                        v-if="bulkBlockErrors.general"
                        class="text-xs text-red-600"
                    >
                        {{ bulkBlockErrors.general }}
                    </p>

                    <label class="flex flex-col gap-1">
                        <span class="text-xs font-medium uppercase"
                            >Select reason for blocking</span
                        >
                        <select
                            v-model="bulkBlockForm.block_reason_id"
                            class="rounded border border-gray-300 px-2 py-1.5 text-sm dark:border-[#3E3E3A] dark:bg-[#252524]"
                        >
                            <option
                                v-for="reason in chartData?.block_reasons ?? []"
                                :key="reason.id"
                                :value="reason.id"
                            >
                                {{ reason.name }}
                            </option>
                        </select>
                        <span
                            v-if="bulkBlockErrors.block_reason_id"
                            class="text-xs text-red-600"
                            >{{ bulkBlockErrors.block_reason_id }}</span
                        >
                    </label>

                    <div class="flex flex-col gap-1">
                        <span class="text-xs font-medium">Date Range</span>
                        <DateRangePicker
                            :holidays="chartData?.holidays"
                            :check-in="bulkBlockForm.start_date"
                            :check-out="bulkBlockForm.end_date"
                            @update:check-in="bulkBlockForm.start_date = $event"
                            @update:check-out="bulkBlockForm.end_date = $event"
                        />
                        <span
                            v-if="
                                bulkBlockErrors.start_date ||
                                bulkBlockErrors.end_date
                            "
                            class="text-xs text-red-600"
                        >
                            {{
                                bulkBlockErrors.start_date ||
                                bulkBlockErrors.end_date
                            }}
                        </span>
                    </div>

                    <div class="grid grid-cols-2 gap-2">
                        <div class="relative flex flex-col gap-1">
                            <span class="text-xs font-medium"
                                >Room Category</span
                            >
                            <button
                                type="button"
                                class="flex items-center justify-between gap-1 rounded border border-gray-300 bg-white px-2 py-1.5 text-left text-xs dark:border-[#3E3E3A] dark:bg-[#252524]"
                                @click="toggleDropdown('categories')"
                            >
                                <span class="truncate">{{
                                    selectedCategoriesLabel || 'Select...'
                                }}</span>
                                <span class="text-[10px]">▾</span>
                            </button>
                            <div
                                v-if="openDropdown === 'categories'"
                                class="absolute top-full left-0 z-10 mt-1 w-48 rounded border border-gray-300 bg-white py-1 shadow-lg dark:border-[#3E3E3A] dark:bg-[#252524]"
                            >
                                <div class="px-1.5">
                                    <input
                                        v-model="categorySearch"
                                        type="text"
                                        class="w-full rounded border border-sky-400 px-2 py-1 text-xs outline-none dark:bg-[#1e1e1d]"
                                    />
                                </div>
                                <div
                                    class="mx-1.5 my-1.5 grid grid-cols-2 overflow-hidden rounded border border-gray-300 text-xs dark:border-[#3E3E3A]"
                                >
                                    <button
                                        type="button"
                                        class="border-r border-gray-300 py-1 hover:bg-gray-100 dark:border-[#3E3E3A] dark:hover:bg-[#333]"
                                        @click="selectAllCategories"
                                    >
                                        Select All
                                    </button>
                                    <button
                                        type="button"
                                        class="py-1 hover:bg-gray-100 dark:hover:bg-[#333]"
                                        @click="deselectAllCategories"
                                    >
                                        Deselect All
                                    </button>
                                </div>
                                <div class="max-h-48 overflow-y-auto">
                                    <button
                                        v-for="category in filteredCategories"
                                        :key="category.id"
                                        type="button"
                                        class="flex w-full items-center justify-between px-3 py-1.5 text-left text-xs hover:bg-gray-100 dark:hover:bg-[#333]"
                                        @click="
                                            toggleBulkBlockCategory(category.id)
                                        "
                                    >
                                        <span>{{ category.name }}</span>
                                        <span
                                            v-if="
                                                bulkBlockForm.category_ids.includes(
                                                    category.id,
                                                )
                                            "
                                            >✔</span
                                        >
                                    </button>
                                </div>
                            </div>
                        </div>

                        <div class="relative flex flex-col gap-1">
                            <span class="text-xs font-medium">Room No's</span>
                            <button
                                type="button"
                                class="flex items-center justify-between gap-1 rounded border border-gray-300 bg-white px-2 py-1.5 text-left text-xs dark:border-[#3E3E3A] dark:bg-[#252524]"
                                @click="toggleDropdown('rooms')"
                            >
                                <span class="truncate">{{
                                    selectedRoomsLabel || 'Select...'
                                }}</span>
                                <span class="text-[10px]">▾</span>
                            </button>
                            <div
                                v-if="openDropdown === 'rooms'"
                                class="absolute top-full left-0 z-10 mt-1 w-40 rounded border border-gray-300 bg-white py-1 shadow-lg dark:border-[#3E3E3A] dark:bg-[#252524]"
                            >
                                <div class="px-1.5">
                                    <input
                                        v-model="roomSearch"
                                        type="text"
                                        class="w-full rounded border border-sky-400 px-2 py-1 text-xs outline-none dark:bg-[#1e1e1d]"
                                    />
                                </div>
                                <div
                                    class="mx-1.5 my-1.5 grid grid-cols-2 overflow-hidden rounded border border-gray-300 text-xs dark:border-[#3E3E3A]"
                                >
                                    <button
                                        type="button"
                                        class="border-r border-gray-300 py-1 hover:bg-gray-100 dark:border-[#3E3E3A] dark:hover:bg-[#333]"
                                        @click="selectAllRooms"
                                    >
                                        Select All
                                    </button>
                                    <button
                                        type="button"
                                        class="py-1 hover:bg-gray-100 dark:hover:bg-[#333]"
                                        @click="deselectAllRooms"
                                    >
                                        Deselect All
                                    </button>
                                </div>
                                <div class="max-h-48 overflow-y-auto">
                                    <p
                                        v-if="!filteredRooms.length"
                                        class="px-3 py-1.5 text-xs text-gray-500"
                                    >
                                        Select a room category first.
                                    </p>
                                    <button
                                        v-for="room in filteredRooms"
                                        :key="room.id"
                                        type="button"
                                        class="flex w-full items-center justify-between px-3 py-1.5 text-left text-xs hover:bg-gray-100 dark:hover:bg-[#333]"
                                        @click="toggleRoom(room.id)"
                                    >
                                        <span>{{ room.name }}</span>
                                        <span
                                            v-if="
                                                bulkBlockForm.room_ids.includes(
                                                    room.id,
                                                )
                                            "
                                            >✔</span
                                        >
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                    <span
                        v-if="bulkBlockErrors.room_ids"
                        class="-mt-2 text-xs text-red-600"
                        >{{ bulkBlockErrors.room_ids }}</span
                    >

                    <label class="flex flex-col gap-1">
                        <span class="text-xs font-medium">NAME</span>
                        <input
                            v-model="bulkBlockForm.name"
                            type="text"
                            class="rounded border border-gray-300 px-2 py-1.5 text-sm dark:border-[#3E3E3A] dark:bg-[#252524]"
                        />
                        <span
                            v-if="bulkBlockErrors.name"
                            class="text-xs text-red-600"
                            >{{ bulkBlockErrors.name }}</span
                        >
                    </label>

                    <label class="flex flex-col gap-1">
                        <span class="text-xs font-medium">Remarks*</span>
                        <textarea
                            v-model="bulkBlockForm.remarks"
                            rows="3"
                            class="rounded border border-gray-300 px-2 py-1.5 text-sm dark:border-[#3E3E3A] dark:bg-[#252524]"
                        ></textarea>
                        <span
                            v-if="bulkBlockErrors.remarks"
                            class="text-xs text-red-600"
                            >{{ bulkBlockErrors.remarks }}</span
                        >
                    </label>
                </div>

                <div
                    class="flex items-center justify-between rounded-b-md bg-gray-50 px-3 py-2.5 dark:bg-[#252524]"
                >
                    <button
                        type="button"
                        :disabled="!canUnblock || bulkBlockSubmitting"
                        class="rounded border border-gray-300 bg-white px-3 py-1.5 text-sm font-medium text-gray-700 uppercase hover:bg-gray-100 disabled:cursor-not-allowed disabled:bg-gray-100 disabled:text-gray-400 dark:border-[#3E3E3A] dark:bg-[#1e1e1d] dark:text-[#EDEDEC]"
                        @click="submitRoomBlocks('unblock')"
                    >
                        Unblock
                    </button>
                    <button
                        type="button"
                        :disabled="bulkBlockSubmitting"
                        class="rounded bg-[#16a34a] px-5 py-1.5 text-sm font-semibold text-white hover:bg-[#15803d] disabled:opacity-50"
                        @click="submitRoomBlocks('block')"
                    >
                        {{ bulkBlockSubmitting ? 'Saving...' : 'Block' }}
                    </button>
                </div>
            </div>
        </div>

        <!-- A date's notes; teleported so the chart's scroll area doesn't clip it -->
        <Teleport v-if="isClientMounted" to="body">
            <div
                v-if="notesPopover"
                data-chart-notes
                role="dialog"
                :aria-label="`Notes for ${notesPopover.date.formatted}`"
                class="fixed z-[115] w-72 rounded-md border border-gray-200 bg-white p-3 text-sm text-[#1b1b18] shadow-lg dark:border-[#3E3E3A] dark:bg-[#1e1e1d] dark:text-[#EDEDEC]"
                :style="{
                    top: `${notesPopover.top}px`,
                    left: `${notesPopover.left}px`,
                }"
            >
                <div class="mb-2 flex items-center justify-between">
                    <span class="font-semibold"
                        >Notes · {{ notesPopover.date.formatted }}</span
                    >
                    <button
                        type="button"
                        class="text-gray-400 hover:text-gray-600"
                        aria-label="Close"
                        @click="closeNotesPopover"
                    >
                        ✕
                    </button>
                </div>

                <ul
                    v-if="notesPopover.date.notes?.length"
                    class="mb-2 max-h-56 space-y-2 overflow-y-auto"
                >
                    <li
                        v-for="note in notesPopover.date.notes"
                        :key="note.id"
                        class="rounded border border-gray-100 bg-gray-50 p-2 dark:border-[#3E3E3A] dark:bg-[#161615]"
                    >
                        <div
                            class="mb-1 flex items-center justify-between gap-2 text-xs text-[#706f6c] dark:text-[#A1A09A]"
                        >
                            <span class="truncate font-semibold">{{
                                note.user_name
                            }}</span>
                            <span class="shrink-0">{{
                                propertyTime.dateTime(note.updated_at)
                            }}</span>
                        </div>
                        <template v-if="editingNoteId === note.id">
                            <textarea
                                v-model="editingNoteBody"
                                rows="3"
                                maxlength="1000"
                                class="w-full rounded border border-gray-300 px-2 py-1 text-sm dark:border-[#3E3E3A] dark:bg-[#161615]"
                            />
                            <div class="mt-1 flex justify-end gap-2">
                                <button
                                    type="button"
                                    class="rounded px-2 py-1 text-xs hover:bg-gray-100 dark:hover:bg-[#2a2a28]"
                                    @click="editingNoteId = null"
                                >
                                    Cancel
                                </button>
                                <button
                                    type="button"
                                    :disabled="
                                        noteSaving || !editingNoteBody.trim()
                                    "
                                    class="rounded bg-[#1b1b18] px-2 py-1 text-xs font-semibold text-white disabled:opacity-50 dark:bg-[#EDEDEC] dark:text-[#1b1b18]"
                                    @click="saveNote"
                                >
                                    Save
                                </button>
                            </div>
                        </template>
                        <template v-else>
                            <p class="text-sm break-words whitespace-pre-line">
                                {{ note.body }}
                            </p>
                            <button
                                v-if="note.user_id === currentUserId"
                                type="button"
                                class="mt-1 text-xs font-semibold text-[#c2621c] hover:underline"
                                @click="startEditingNote(note)"
                            >
                                Edit
                            </button>
                        </template>
                    </li>
                </ul>
                <p
                    v-else
                    class="mb-2 text-xs text-[#706f6c] dark:text-[#A1A09A]"
                >
                    No notes for this day yet.
                </p>

                <template v-if="editingNoteId === null">
                    <textarea
                        v-model="newNoteBody"
                        rows="2"
                        maxlength="1000"
                        placeholder="Add a note…"
                        class="w-full rounded border border-gray-300 px-2 py-1 text-sm dark:border-[#3E3E3A] dark:bg-[#161615]"
                    />
                    <div class="mt-1 flex justify-end">
                        <button
                            type="button"
                            :disabled="noteSaving || !newNoteBody.trim()"
                            class="rounded bg-[#1b1b18] px-3 py-1 text-xs font-semibold text-white disabled:opacity-50 dark:bg-[#EDEDEC] dark:text-[#1b1b18]"
                            @click="saveNote"
                        >
                            Add note
                        </button>
                    </div>
                </template>
                <p v-if="noteError" class="mt-1 text-xs text-red-600">
                    {{ noteError }}
                </p>
            </div>
        </Teleport>

        <!-- Booking summary popover; teleported so the chart's scroll area doesn't clip it -->
        <Teleport v-if="isClientMounted" to="body">
            <div
                v-if="bookingPopover"
                ref="bookingPopoverRef"
                role="dialog"
                aria-label="Booking summary"
                class="fixed z-[60] shadow-2xl"
                :style="{
                    left: `${bookingPopover.x}px`,
                    top: `${bookingPopover.y}px`,
                    width: `${BOOKING_POPOVER_WIDTH}px`,
                }"
            >
                <BookingSummaryCard
                    v-if="bookingPopover.summary"
                    :booking-number="bookingPopover.summary.booking_ref_id"
                    :ref-id="bookingPopover.summary.booking_ref_id"
                    :check-in="bookingPopover.summary.check_in"
                    :check-out="bookingPopover.summary.check_out"
                    :nights="bookingPopover.summary.nights"
                    :room-lines="bookingPopover.summary.room_lines"
                    :total="bookingPopover.summary.total"
                    :outstanding="bookingPopover.summary.outstanding"
                    :source="bookingPopover.summary.source"
                    :status="bookingPopover.summary.status"
                    :status-label="bookingPopover.summary.status_label"
                    :guest="bookingPopover.summary.guest"
                >
                    <template #close>
                        <button
                            type="button"
                            aria-label="Close"
                            class="text-lg leading-none text-white/70 hover:text-white"
                            @click="closeBookingSummary"
                        >
                            ✕
                        </button>
                    </template>
                    <template v-if="bookingPopover.summary.editable" #actions>
                        <Link
                            :href="
                                admin.bookings.edit({
                                    property: props.property.id,
                                    booking: bookingPopover.summary.id,
                                }).url
                            "
                            class="text-xs font-bold text-[#f53003] hover:underline dark:text-[#FF4433]"
                        >
                            EDIT
                        </Link>
                    </template>
                    <template #footer>
                        <div class="flex w-full flex-col items-center gap-2">
                            <p
                                v-if="bookingActionError"
                                class="w-full rounded bg-red-50 px-2 py-1 text-center text-xs text-red-700 dark:bg-red-950/40 dark:text-red-300"
                            >
                                {{ bookingActionError }}
                            </p>

                            <div
                                v-if="confirmingCancel"
                                class="flex w-full flex-col items-center gap-2 text-center"
                            >
                                <p
                                    class="text-xs text-[#1b1b18] dark:text-[#EDEDEC]"
                                >
                                    Cancel booking
                                    {{ bookingPopover.summary.booking_ref_id }}?
                                    Its rooms will become available again.
                                </p>
                                <div class="flex gap-2">
                                    <button
                                        type="button"
                                        class="rounded border border-[#e3e3e0] px-3 py-1.5 text-xs font-semibold dark:border-[#3E3E3A] dark:text-[#EDEDEC]"
                                        :disabled="bookingActionBusy"
                                        @click="confirmingCancel = false"
                                    >
                                        No
                                    </button>
                                    <button
                                        type="button"
                                        class="rounded bg-red-600 px-3 py-1.5 text-xs font-bold text-white uppercase hover:bg-red-700 disabled:opacity-50"
                                        :disabled="bookingActionBusy"
                                        @click="
                                            changeBookingStatus(
                                                'cancelled',
                                                `Booking ${bookingPopover.summary.booking_ref_id} cancelled.`,
                                            )
                                        "
                                    >
                                        {{
                                            bookingActionBusy
                                                ? 'Cancelling…'
                                                : 'Yes, Cancel'
                                        }}
                                    </button>
                                </div>
                            </div>

                            <div
                                v-else
                                class="flex flex-wrap justify-center gap-2"
                            >
                                <button
                                    v-if="canCheckInToday"
                                    type="button"
                                    class="rounded bg-[#16a34a] px-3 py-1.5 text-xs font-bold text-white uppercase hover:bg-[#15803d] disabled:opacity-50"
                                    :disabled="bookingActionBusy"
                                    @click="
                                        changeBookingStatus(
                                            'checked_in',
                                            `${bookingPopover.summary.booking_ref_id} checked in.`,
                                        )
                                    "
                                >
                                    Check In
                                </button>
                                <button
                                    v-if="
                                        bookingPopover.summary.status ===
                                        'checked_in'
                                    "
                                    type="button"
                                    class="rounded bg-[#ea580c] px-3 py-1.5 text-xs font-bold text-white uppercase hover:bg-[#c2410c] disabled:opacity-50"
                                    :disabled="bookingActionBusy"
                                    @click="
                                        changeBookingStatus(
                                            'confirmed',
                                            `Check-in undone for ${bookingPopover.summary.booking_ref_id}.`,
                                        )
                                    "
                                >
                                    Undo Check In
                                </button>
                                <Link
                                    :href="
                                        admin.bookings.show({
                                            property: props.property.id,
                                            booking: bookingPopover.summary.id,
                                        }).url
                                    "
                                    class="rounded border border-[#ea580c] px-3 py-1.5 text-xs font-bold text-[#ea580c] uppercase hover:bg-orange-50 dark:hover:bg-orange-950/20"
                                >
                                    View Details
                                </Link>
                                <button
                                    v-if="
                                        bookingPopover.summary.status ===
                                        'confirmed'
                                    "
                                    type="button"
                                    class="rounded bg-[#ea580c] px-3 py-1.5 text-xs font-bold text-white uppercase hover:bg-[#c2410c]"
                                    @click="confirmingCancel = true"
                                >
                                    Cancel Booking
                                </button>
                            </div>
                        </div>
                    </template>
                </BookingSummaryCard>
                <div
                    v-else
                    class="flex items-center justify-between gap-3 rounded-lg border border-[#e3e3e0] bg-white px-4 py-6 text-sm text-[#706f6c] dark:border-[#3E3E3A] dark:bg-[#161615] dark:text-[#A1A09A]"
                >
                    <span>{{
                        bookingPopover.error || 'Loading booking...'
                    }}</span>
                    <button
                        type="button"
                        aria-label="Close"
                        class="hover:text-[#1b1b18] dark:hover:text-[#EDEDEC]"
                        @click="closeBookingSummary"
                    >
                        ✕
                    </button>
                </div>
            </div>
        </Teleport>
    </div>
</template>

<style scoped>
.smart-chart-scroll {
    scrollbar-width: thin;
    scrollbar-color: #94a3b8 transparent;
}
</style>
