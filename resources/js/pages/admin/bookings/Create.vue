<script setup lang="ts">
import AgentCombobox, {
    type AgentOption,
} from '@/components/AgentCombobox.vue';
import AdminLayout from '@/layouts/AdminLayout.vue';
import BookingBillingPanel, {
    type BillingProps,
} from '@/components/BookingBillingPanel.vue';
import BookingPrintMenu from '@/components/BookingPrintMenu.vue';
import BookingSummaryCard from '@/components/BookingSummaryCard.vue';
import { roomSummaryLines } from '@/lib/roomSummaryLines';
import admin from '@/routes/admin';
import DateRangePicker from '@/components/DateRangePicker.vue';
import DocumentViewerModal, {
    type ViewableDocument,
} from '@/components/DocumentViewerModal.vue';
import ExternalRecipientModal, {
    type ExternalRecipient,
} from '@/components/ExternalRecipientModal.vue';
import GuestFormModal, {
    type GuestRecord,
} from '@/components/GuestFormModal.vue';
import GuestSearchCombobox from '@/components/GuestSearchCombobox.vue';
import Modal from '@/components/Modal.vue';
import RichTextEditor from '@/components/RichTextEditor.vue';
import SearchableSelect from '@/components/SearchableSelect.vue';
import { useSidebarCollapsed } from '@/lib/useSidebarCollapsed';
import type { RoomCategory } from '@/types';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { useCurrency } from '@/lib/useCurrency';
import type { Holiday } from '@/lib/holidays';
import { useCan } from '@/lib/useCan';
import { usePropertyTime } from '@/lib/usePropertyTime';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';

const { amount, symbol } = useCurrency();
const propertyTime = usePropertyTime();

defineOptions({ layout: AdminLayout });

const props = defineProps<{
    property: { id: number; name: string; country_id: number | null };
    holidays: Holiday[];
    roomCategories: Pick<
        RoomCategory,
        | 'id'
        | 'name'
        | 'abbreviation'
        | 'meal_plans'
        | 'max_adults'
        | 'max_children'
        | 'ideal_occupancy'
        | 'default_number_of_children'
        | 'inclusion_details'
        | 'rooms'
        | 'sell_by_bed'
    >[];
    countries: { id: number; name: string; iso2: string; phonecode: string }[];
    prefill: {
        check_in: string;
        check_out: string;
        rooms: { room_category_id: number | null; room_id: number | null }[];
        /** Set when converting a waitlist request: saving the booking closes it. */
        waitlist_request_id?: number;
        guest_name?: string;
        guest_id?: number | null;
    } | null;
    isHold: boolean;
    /** Log Enquiry: a quote with a release date that doesn't take any room. */
    isEnquiry: boolean;
    holdDefaults: { duration_days: number; reminder_days: number[] };
    documentTypes: {
        name: string;
        require_expiry_date: boolean;
        require_document_number: boolean;
    }[];
    typeOptions: { value: string; label: string }[];
    /** For the agent picker's "Add agent" form. */
    agencies: { id: number; name: string; agent_rate_code_id: number | null }[];
    agentRateCodes: { id: number; name: string }[];
    /** OTAs for "Selected OTA" (BookingChannelSeeder). */
    otaChannels: { id: number; name: string }[];
    /** A Meta booking's "Type": the metasearch engines the property is listed on; empty when Meta listings are off. */
    metaPlatforms: { id: number; name: string }[];
    /** Notification channels switched on and configured in Application Settings → Notification Settings. */
    reminderChannels: { value: HoldReminder['channel']; label: string }[];
    /** Present when editing an existing booking; null on Quick Book. */
    booking: EditableBooking | null;
    /** Payments, payment links and folios of the booking being edited. */
    billing?: BillingProps;
    /** Add-on services the booking can include (Property → Add-ons). */
    addonCatalogue: AddonOption[];
    /** Editing only: the saved bill and folio balance, and the cancellation policy for a change made today. */
    changeSettlement?: {
        saved_bill: number;
        balance: number;
        tiers: { days: number; percent: number }[];
        summary: {
            window: string;
            penalty: string;
            refund: string;
            description: string;
        }[];
        days_before: number;
        /** Open bookings of the property the credit can move to. */
        transfer_targets: {
            id: number;
            booking_ref_id: string;
            guest_name: string;
            check_in: string;
            outstanding: number;
        }[];
        /** The guest's ledger at the property (null without a guest). */
        guest_ledger: { guest_name: string; balance: number } | null;
        refund_modes: { value: string; label: string }[];
    };
}>();

type AddonOption = {
    id: number;
    name: string;
    description: string | null;
    pricing: string;
    pricing_label: string;
    price: number;
    tax_rate: number;
};
type SavedAddonLine = {
    id: number;
    addon_id: number | null;
    name: string;
    pricing: string;
    pricing_label: string;
    unit_price: number;
    quantity: number;
    tax_rate: number;
    total: number;
};

type BookingRoomInput = {
    room_category_id: number;
    room_id: number | null;
    /** A saved dorm stay's bed number. */
    bed?: number | null;
    meal_plan: string;
    /** The Rate Settings package the room is sold under. */
    package_id?: number | null;
    adults: number;
    children: number;
    infants: number;
    rate_per_night: number;
    /** A split booking's rooms carry their own dates. */
    check_in_date?: string | null;
    check_out_date?: string | null;
};

export type EditableBooking = {
    id: number;
    booking_ref_id: string;
    source: string;
    agent: AgentOption | null;
    booking_channel_id: number | null;
    metasearch_platform_id: number | null;
    type: string;
    booking_type: string | null;
    check_in: string;
    check_out: string;
    check_in_time: string | null;
    check_out_time: string | null;
    rooms_split: boolean;
    same_plan_for_all_rooms: boolean;
    pay_at_hotel: boolean;
    rate_plan: string | null;
    discount: number;
    status: string;
    room_bill_to: 'to_guest' | 'to_company';
    extras_bill_to: 'to_guest' | 'to_company';
    bill_room_to_guest_company: boolean;
    bill_extras_to_guest_company: boolean;
    company_billing?: { linked: boolean; billed?: number } | null;
    no_post: boolean;
    special_instructions: string | null;
    internal_notes: string | null;
    copy_to: ExternalRecipient[];
    inclusions: Record<number, string>;
    hold_release_at: string | null;
    hold_auto_release: boolean;
    hold_reminders: HoldReminder[];
    rooms: BookingRoomInput[];
    guests: GuestRecord[];
    attachments: SavedAttachment[];
    addons?: SavedAddonLine[];
};

type SavedAttachment = {
    id: number;
    document_type: string;
    document_number: string | null;
    expiry_date: string | null;
    original_name: string;
    mime_type: string | null;
    url: string;
    download_url: string;
};

type HoldReminder = {
    channel: 'email' | 'sms' | 'whatsapp';
    days: number;
    relative_to: 'hold_release_date' | 'check_in_date';
};

type AttachmentRow = {
    document_type: string;
    document_number: string;
    expiry_date: string;
    file: File | null;
};

const { forceCollapsed } = useSidebarCollapsed();

onMounted(() => {
    forceCollapsed.value = true;
});

onBeforeUnmount(() => {
    forceCollapsed.value = false;
});

/** The property's business date (not the device's). */
function todayIso(): string {
    return propertyTime.today();
}

function addDaysIso(iso: string, days: number): string {
    const date = new Date(`${iso}T00:00:00`);
    date.setDate(date.getDate() + days);

    return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;
}

const allBookingSourceOptions = [
    { value: 'direct', label: 'Direct' },
    { value: 'corporate', label: 'Corporate' },
    { value: 'travel_agent', label: 'Agent' },
    { value: 'ota', label: 'OTA' },
    { value: 'meta', label: 'Meta' },
    { value: 'booking_engine', label: 'Booking Engine' },
];

/** Application Settings → Reservation → Configurable Subtype; keeps an edited booking's current type even if it was switched off. */
const typeOptions = computed(() => {
    const options = [...props.typeOptions];
    const currentType = props.booking?.type;

    if (
        currentType &&
        !options.some((option) => option.value === currentType)
    ) {
        options.push({
            value: currentType,
            label: currentType.replace(/_/g, ' '),
        });
    }

    return options;
});

const billToOptions = [
    { value: 'to_guest', label: 'To Guest' },
    { value: 'to_company', label: 'To Company' },
];

const bookingTypeOptions = [
    { value: 'fit', label: 'FIT (Free Independent Traveller)' },
    { value: 'group', label: 'Group' },
    {
        value: 'mice',
        label: 'MICE (Meetings, Incentives, Conferences & Exhibitions)',
    },
    { value: 'series', label: 'Series (Recurring Group)' },
];

type CategoryLine = {
    id: number;
    room_category_id: number | null;
    meal_plan: string;
    room_count: number;
};

type RoomLine = {
    id: number;
    category_line_id: number;
    room_category_id: number;
    meal_plan: string;
    adults: number;
    children: number;
    infants: number;
    room_id: number | null;
    rate_per_night: number;
    check_in_date: string | null;
    check_out_date: string | null;
    /** The bed a saved dorm stay is on, while it keeps its room. */
    saved_bed?: { room_id: number | null; bed: number } | null;
    /** The Rate Settings package the room is sold under, if any. */
    package_id?: number | null;
};

function emptyCategoryLine(id: number): CategoryLine {
    const firstCategory = props.roomCategories[0] ?? null;

    return {
        id,
        room_category_id: firstCategory?.id ?? null,
        meal_plan: firstCategory?.meal_plans?.[0] ?? '',
        room_count: 1,
    };
}

const editing = props.booking;
const isEditing = editing !== null;

/** Override Price starts off: room rates follow the rate plan until it is ticked. */
const can = useCan();
const overridePrice = ref(false);

/** An agent booking names the agency contact it came through; other sources have none. */
const selectedAgent = ref<AgentOption | null>(editing?.agent ?? null);
const isAgentSource = computed(() => form.source === 'travel_agent');

const otaOptions = computed(() =>
    props.otaChannels.map((channel) => ({
        id: channel.id,
        label: channel.name,
    })),
);

/** Meta is offered only when the property lists metasearch engines (or the booking already is one). */
const bookingSourceOptions = computed(() =>
    allBookingSourceOptions.filter(
        (option) =>
            option.value !== 'meta' ||
            props.metaPlatforms.length > 0 ||
            editing?.source === 'meta',
    ),
);

const form = useForm({
    waitlist_request_id: (props.prefill?.waitlist_request_id ?? null) as
        | number
        | null,
    booking_ref_id: editing?.booking_ref_id ?? '',
    source: editing?.source ?? 'direct',
    agent_id: (editing?.agent?.id ?? null) as number | null,
    booking_channel_id: (editing?.booking_channel_id ?? null) as number | null,
    metasearch_platform_id: (editing?.metasearch_platform_id ?? null) as
        | number
        | null,
    type: editing?.type ?? props.typeOptions[0]?.value ?? 'walk_in',
    // New bookings start as FIT; a saved booking keeps its type.
    booking_type: editing ? (editing.booking_type ?? '') : 'fit',
    check_in: editing?.check_in ?? props.prefill?.check_in ?? todayIso(),
    check_out:
        editing?.check_out ??
        props.prefill?.check_out ??
        addDaysIso(todayIso(), 1),
    check_in_time: editing?.check_in_time ?? '10:30',
    check_out_time: editing?.check_out_time ?? '09:00',
    same_plan_for_all_rooms: editing?.same_plan_for_all_rooms ?? true,
    pay_at_hotel: editing?.pay_at_hotel ?? false,
    rate_plan: editing?.rate_plan ?? 'best_available_rates',
    discount: editing?.discount ?? 0,
    status: editing?.status ?? newBookingStatus(),
    room_bill_to: (editing?.room_bill_to ?? 'to_guest') as
        | 'to_guest'
        | 'to_company',
    extras_bill_to: (editing?.extras_bill_to ?? 'to_guest') as
        | 'to_guest'
        | 'to_company',
    bill_room_to_guest_company: editing?.bill_room_to_guest_company ?? false,
    bill_extras_to_guest_company:
        editing?.bill_extras_to_guest_company ?? false,
    no_post: editing?.no_post ?? false,
    special_instructions: editing?.special_instructions ?? '',
    internal_notes: editing?.internal_notes ?? '',
    copy_to: [...(editing?.copy_to ?? [])] as ExternalRecipient[],
    inclusions: { ...editing?.inclusions } as Record<number, string>,
    rooms: [] as BookingRoomInput[],
    guest_ids: (editing?.guests ?? []).map((guest) => guest.id),
    // Edited as the property's wall-clock time; arrives and leaves as a UTC instant.
    hold_release_at: editing?.hold_release_at
        ? propertyTime.toInput(editing.hold_release_at)
        : '',
    hold_auto_release: editing?.hold_auto_release ?? false,
    hold_reminders: (editing?.hold_reminders ?? []).filter((reminder) =>
        isReminderChannelActive(reminder.channel),
    ) as HoldReminder[],
    attachments: [] as AttachmentRow[],
    removed_attachment_ids: [] as number[],
    addons: (editing?.addons ?? [])
        .filter((line) => line.addon_id !== null)
        .map((line) => ({
            addon_id: line.addon_id as number,
            quantity: line.quantity,
        })),
});

/** The OTA / Meta pick belongs to its source, so a change of source clears it. */
watch(
    () => form.source,
    () => {
        form.booking_channel_id = null;
        form.metasearch_platform_id = null;
    },
);

function isReminderChannelActive(channel: string): boolean {
    return props.reminderChannels.some((option) => option.value === channel);
}

/** Saved reminders on a channel that has since been switched off are dropped from the form. */
const droppedReminderCount = (editing?.hold_reminders ?? []).filter(
    (reminder) => !isReminderChannelActive(reminder.channel),
).length;

function newBookingStatus(): string {
    if (props.isEnquiry) {
        return 'enquiry';
    }

    return props.isHold ? 'on_hold' : 'confirmed';
}

/** Hold and enquiry forms both carry a release date & time with reminders. */
const hasReleaseReminder = computed(() => props.isHold || props.isEnquiry);

/**
 * Default hold release: the Auto Release "Duration Days" from today (or the
 * check-in date when unset), never after check-in, at 12:00 pm.
 */
/** Noon (property time) on the release day, or the next whole hour when that has passed; a datetime-local value. */
function defaultHoldReleaseAt(): string {
    const releaseDate =
        props.holdDefaults.duration_days > 0
            ? addDaysIso(propertyTime.today(), props.holdDefaults.duration_days)
            : form.check_in;
    const release = `${releaseDate < form.check_in ? releaseDate : form.check_in}T12:00`;

    if (new Date(propertyTime.fromInput(release) ?? 0).getTime() > Date.now()) {
        return release;
    }

    const nextHour = new Date(Date.now() + 60 * 60 * 1000);
    nextHour.setUTCMinutes(0, 0, 0);

    return propertyTime.toInput(nextHour.toISOString());
}

if (hasReleaseReminder.value && !isEditing) {
    form.hold_release_at = defaultHoldReleaseAt();
    form.hold_reminders = (
        props.reminderChannels.length
            ? props.holdDefaults.reminder_days.length
                ? props.holdDefaults.reminder_days
                : [0]
            : []
    ).map((days) => ({
        channel: props.reminderChannels[0].value,
        days,
        relative_to: 'hold_release_date',
    }));
}

function addHoldReminder(): void {
    if (!props.reminderChannels.length) {
        return;
    }

    form.hold_reminders.push({
        channel: props.reminderChannels[0].value,
        days: 0,
        relative_to: 'hold_release_date',
    });
}

function removeHoldReminder(index: number): void {
    form.hold_reminders.splice(index, 1);
}

// ── Attachments / Upload Documents ──
const showUploadDocuments = ref(false);
const uploadRows = ref<AttachmentRow[]>([]);
const uploadErrors = ref<Record<number, string>>({});

function emptyAttachmentRow(): AttachmentRow {
    return {
        document_type: props.documentTypes[0]?.name ?? '',
        document_number: '',
        expiry_date: '',
        file: null,
    };
}

function documentTypeFor(name: string) {
    return props.documentTypes.find((type) => type.name === name) ?? null;
}

function openUploadDocuments(): void {
    uploadRows.value = [emptyAttachmentRow()];
    uploadErrors.value = {};
    showUploadDocuments.value = true;
}

function addUploadRow(): void {
    uploadRows.value.push(emptyAttachmentRow());
}

function removeUploadRow(index: number): void {
    uploadRows.value.splice(index, 1);
}

function onUploadFileChosen(row: AttachmentRow, event: Event): void {
    row.file = (event.target as HTMLInputElement).files?.[0] ?? null;
}

/** Checks each row against its document type's Document Settings before adding it to the booking. */
function saveUploadRows(): void {
    const errors: Record<number, string> = {};

    uploadRows.value.forEach((row, index) => {
        const type = documentTypeFor(row.document_type);

        if (!type) {
            errors[index] = 'Choose a document type.';
        } else if (!row.file) {
            errors[index] = 'Upload a file.';
        } else if (row.file.size > 10 * 1024 * 1024) {
            errors[index] = 'The file must be 10 MB or smaller.';
        } else if (
            type.require_document_number &&
            !row.document_number.trim()
        ) {
            errors[index] = `${type.name} needs a document number.`;
        } else if (type.require_expiry_date && !row.expiry_date) {
            errors[index] = `${type.name} needs an expiry date.`;
        }
    });

    uploadErrors.value = errors;

    if (Object.keys(errors).length) {
        return;
    }

    form.attachments.push(
        ...uploadRows.value.map((row) => ({
            ...row,
            document_number: row.document_number.trim(),
        })),
    );
    showUploadDocuments.value = false;
}

function removeAttachment(index: number): void {
    form.attachments.splice(index, 1);
}

const savedAttachments = ref<SavedAttachment[]>([
    ...(editing?.attachments ?? []),
]);

/** Saved documents are only deleted when the booking is saved. */
function removeSavedAttachment(attachment: SavedAttachment): void {
    savedAttachments.value = savedAttachments.value.filter(
        (item) => item.id !== attachment.id,
    );
    form.removed_attachment_ids.push(attachment.id);
}

function viewSavedAttachment(attachment: SavedAttachment): void {
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

/** Previews a chosen file before upload through a temporary object URL, released when the viewer closes. */
const viewedDocument = ref<ViewableDocument | null>(null);

function viewAttachment(attachment: AttachmentRow): void {
    if (!attachment.file) {
        return;
    }

    viewedDocument.value = {
        title: attachment.document_type,
        subtitle: [
            attachment.document_number,
            attachment.expiry_date ? `Expires ${attachment.expiry_date}` : null,
            attachment.file.name,
        ]
            .filter(Boolean)
            .join(' · '),
        url: URL.createObjectURL(attachment.file),
        mimeType: attachment.file.type || null,
        fileName: attachment.file.name,
    };
}

function closeDocumentViewer(): void {
    if (viewedDocument.value?.url.startsWith('blob:')) {
        URL.revokeObjectURL(viewedDocument.value.url);
    }

    viewedDocument.value = null;
}

onBeforeUnmount(closeDocumentViewer);

const attachmentErrors = computed(() =>
    Object.entries(form.errors as Record<string, string>)
        .filter(([key]) => key.startsWith('attachments'))
        .map(([, message]) => message),
);

const holdErrors = computed(() =>
    Object.entries(form.errors as Record<string, string>)
        .filter(([key]) => key.startsWith('hold_'))
        .map(([, message]) => message),
);

const editingInclusions = ref<Record<number, boolean>>({});

function inclusionsFor(category: {
    id: number;
    inclusion_details: string | null;
}): string {
    return form.inclusions[category.id] ?? category.inclusion_details ?? '';
}

function startEditingInclusions(category: {
    id: number;
    inclusion_details: string | null;
}): void {
    if (form.inclusions[category.id] === undefined) {
        form.inclusions[category.id] = category.inclusion_details ?? '';
    }

    editingInclusions.value[category.id] = true;
}

function stopEditingInclusions(categoryId: number): void {
    editingInclusions.value[categoryId] = false;
}

function defaultPhoneCountryCode(): string {
    const propertyCountry = props.countries.find(
        (country) => country.id === props.property.country_id,
    );

    return propertyCountry ? `+${propertyCountry.phonecode}` : '+91';
}

const recipientModalOpen = ref(false);
const editingRecipientIndex = ref<number | null>(null);

function openAddRecipientModal(): void {
    editingRecipientIndex.value = null;
    recipientModalOpen.value = true;
}

function openEditRecipientModal(index: number): void {
    editingRecipientIndex.value = index;
    recipientModalOpen.value = true;
}

function onRecipientSaved(recipient: ExternalRecipient): void {
    if (editingRecipientIndex.value === null) {
        form.copy_to.push(recipient);
    } else {
        form.copy_to[editingRecipientIndex.value] = recipient;
    }

    recipientModalOpen.value = false;
}

function removeRecipient(index: number): void {
    form.copy_to.splice(index, 1);
}

const inclusionsExpanded = ref<Record<number, boolean>>({});

function toggleInclusions(categoryId: number): void {
    inclusionsExpanded.value[categoryId] =
        !inclusionsExpanded.value[categoryId];
}

const guests = ref<GuestRecord[]>([...(editing?.guests ?? [])]);
const guestModalOpen = ref(false);
const editingGuest = ref<GuestRecord | null>(null);

function openAddGuestModal(): void {
    editingGuest.value = null;
    guestModalOpen.value = true;
}

function openEditGuestModal(guest: GuestRecord): void {
    editingGuest.value = guest;
    guestModalOpen.value = true;
}

function onGuestSelected(guest: GuestRecord): void {
    if (guests.value.some((item) => item.id === guest.id)) {
        return;
    }

    guests.value.push(guest);
}

function onGuestSaved(guest: GuestRecord): void {
    const index = guests.value.findIndex((item) => item.id === guest.id);

    if (index === -1) {
        guests.value.push(guest);
    } else {
        guests.value[index] = guest;
    }

    guestModalOpen.value = false;
}

function removeGuest(guest: GuestRecord): void {
    guests.value = guests.value.filter((item) => item.id !== guest.id);
}

function guestsHaveCompanyDetails(): boolean {
    return guests.value.some(
        (guest) => guest.company && (guest.company.name || guest.company.gstin),
    );
}

/** The City Ledger account billed when the booking bills the guest's company: the first guest's linked one. */
const billedCompanyAccount = computed(
    () =>
        guests.value.find((guest) => guest.city_ledger_account)
            ?.city_ledger_account ?? null,
);

function validateBillToGuestCompany(
    field: 'bill_room_to_guest_company' | 'bill_extras_to_guest_company',
): void {
    if (form[field] && !guestsHaveCompanyDetails()) {
        alert('This cannot be done as guest has no company details saved.');
        form[field] = false;
    }
}

watch(
    guests,
    (list) => {
        form.guest_ids = list.map((guest) => guest.id);
    },
    { deep: true },
);

/**
 * One category line per category selected on the smart booking chart, sized
 * to the number of rooms picked from it.
 */
function initialCategoryLines(): CategoryLine[] {
    // A waitlist request for "any category" leaves the category for staff to pick.
    const sourceRooms: { room_category_id: number; meal_plan?: string }[] = (
        (editing?.rooms ?? props.prefill?.rooms ?? []) as {
            room_category_id: number | null;
            meal_plan?: string;
        }[]
    ).filter(
        (room): room is { room_category_id: number; meal_plan?: string } =>
            room.room_category_id !== null,
    );

    if (!sourceRooms.length) {
        return [emptyCategoryLine(1)];
    }

    const lines: CategoryLine[] = [];

    sourceRooms.forEach((room) => {
        const existing = lines.find(
            (line) => line.room_category_id === room.room_category_id,
        );

        if (existing) {
            existing.room_count++;

            return;
        }

        lines.push({
            id: lines.length + 1,
            room_category_id: room.room_category_id,
            meal_plan:
                room.meal_plan ||
                categoryFor(room.room_category_id)?.meal_plans?.[0] ||
                '',
            room_count: 1,
        });
    });

    return lines;
}

const categoryLines = ref<CategoryLine[]>(initialCategoryLines());
const roomLines = ref<RoomLine[]>([]);

const roomAvailability = ref<Record<number, number>>({});
const unavailableRoomIds = ref<number[]>([]);
/** Dorm rooms: room id => beds free on every night of the stay. */
const freeBeds = ref<Record<number, number>>({});

/**
 * A dorm room's beds still free once every bed line of this booking that picked it is counted;
 * the same figure on every line. Null for a room sold whole.
 */
function bedsLeftIn(roomId: number): number | null {
    const free = freeBeds.value[roomId];

    if (free === undefined) {
        return null;
    }

    const picked = roomLines.value.filter(
        (line) => line.room_id === roomId,
    ).length;

    return Math.max(0, free - picked);
}

function roomOptionDisabled(line: RoomLine, roomId: number): boolean {
    const bedsLeft = bedsLeftIn(roomId);

    // A dorm room can be picked while a bed is left; a line keeps the room it already has.
    if (bedsLeft !== null) {
        return line.room_id !== roomId && bedsLeft < 1;
    }

    return unavailableRoomIds.value.includes(roomId);
}

function roomOptionLabel(option: { id: number; name: string }): string {
    const bedsLeft = bedsLeftIn(option.id);

    if (bedsLeft !== null) {
        return `${option.name} (${bedsLeft} bed${bedsLeft === 1 ? '' : 's'} left)`;
    }

    return (
        option.name +
        (unavailableRoomIds.value.includes(option.id)
            ? ' (booked/blocked)'
            : '')
    );
}

/** Dormitories are counted in beds. */
function unitLabel(categoryId: number | null, plural = true): string {
    const dorm = categoryFor(categoryId)?.sell_by_bed;

    return dorm ? (plural ? 'Beds' : 'Bed') : plural ? 'Rooms' : 'Room';
}
const roomAvailabilityLoading = ref(false);
let roomAvailabilityRequestId = 0;

const roomRates = ref<Record<string, number>>({});
const roomRatesLoading = ref(false);
let roomRatesRequestId = 0;

type TaxSlab = {
    start_range: number;
    end_range: number;
    tax_value_type: 'percentage' | 'fixed';
    tax: number;
};
type TaxComponent = {
    component_choice: string;
    applied_on: 'rack_rate' | 'discounted_net_rate' | '';
    define_tax_slab: boolean;
    tax_value_type: 'percentage' | 'fixed';
    tax_value: number;
    slabs: TaxSlab[];
};

const taxComponents = ref<TaxComponent[]>([]);

function taxComponentRate(
    component: TaxComponent,
    amount: number,
): { rate: number; type: 'percentage' | 'fixed' } {
    if (component.define_tax_slab) {
        const slab = component.slabs.find(
            (item) => amount >= item.start_range && amount <= item.end_range,
        );

        return {
            rate: slab?.tax ?? 0,
            type: slab?.tax_value_type ?? 'percentage',
        };
    }

    return { rate: component.tax_value, type: component.tax_value_type };
}

function taxComponentAmount(component: TaxComponent, amount: number): number {
    const { rate, type } = taxComponentRate(component, amount);

    return type === 'fixed' ? rate : amount * (rate / 100);
}

function taxForAmount(amount: number): number {
    return taxComponents.value.reduce(
        (sum, component) => sum + taxComponentAmount(component, amount),
        0,
    );
}

function categoryFor(id: number | null) {
    return props.roomCategories.find((category) => category.id === id) ?? null;
}

function availabilityFor(categoryId: number): number {
    return (
        roomAvailability.value[categoryId] ??
        categoryFor(categoryId)?.rooms?.length ??
        0
    );
}

function occupancyLabelForAdults(
    category: { max_adults: number } | null,
    adults: number,
): string {
    const tiers = ['Single', 'Double', 'Triple', 'Quad'].slice(
        0,
        Math.min(4, Math.max(1, category?.max_adults ?? 1)),
    );
    const index = Math.min(Math.max(adults, 1), tiers.length) - 1;

    return tiers[index] ?? 'Single';
}

function rowKeyFor(room: {
    room_category_id: number;
    meal_plan: string;
    adults: number;
}): string {
    const occupancy = occupancyLabelForAdults(
        categoryFor(room.room_category_id),
        room.adults,
    );

    return `${room.room_category_id}:${room.meal_plan}:${occupancy}`;
}

function suggestedRateFor(room: {
    room_category_id: number;
    meal_plan: string;
    adults: number;
    package_id?: number | null;
}): number {
    // A package room is suggested at the package's own rate.
    if (room.package_id) {
        const offer = (
            packageOffers.value[offerKey(room as RoomLine)]?.packages ?? []
        ).find((item) => item.id === room.package_id);

        if (offer) {
            return offer.rate_per_night;
        }
    }

    return roomRates.value[rowKeyFor(room)] ?? 0;
}

type PackageOffer = {
    id: number;
    name: string;
    rate_per_night: number;
    amount: number;
    inclusions: string | null;
};

/** Per room (category, meal plan, adults, dates): the packages it can be sold under, and any stay restriction. */
const packageOffers = ref<
    Record<string, { packages: PackageOffer[]; restriction: string | null }>
>({});

function offerKey(room: RoomLine): string {
    const dates = roomDates(room);

    return `${room.room_category_id}|${room.meal_plan}|${room.adults}|${dates.checkIn}|${dates.checkOut}`;
}

async function loadPackageOffers(room: RoomLine): Promise<void> {
    const key = offerKey(room);
    const dates = roomDates(room);

    if (
        key in packageOffers.value ||
        !dates.checkIn ||
        !dates.checkOut ||
        dates.checkOut <= dates.checkIn
    ) {
        return;
    }

    packageOffers.value[key] = { packages: [], restriction: null };

    try {
        const response = await fetch(
            admin.bookings.packageOffers(props.property, {
                query: {
                    check_in: dates.checkIn,
                    check_out: dates.checkOut,
                    room_category_id: room.room_category_id,
                    meal_plan: room.meal_plan,
                    adults: room.adults,
                },
            }).url,
            { headers: { Accept: 'application/json' } },
        );

        if (response.ok) {
            packageOffers.value[key] = await response.json();
        }
    } catch {
        delete packageOffers.value[key];
    }
}

function offersFor(room: RoomLine): PackageOffer[] {
    return packageOffers.value[offerKey(room)]?.packages ?? [];
}

function restrictionFor(room: RoomLine): string | null {
    return packageOffers.value[offerKey(room)]?.restriction ?? null;
}

/** Stay restrictions and package conditions can be overridden by users with the permission. */
const overrideRestrictions = ref(false);

function roomError(index: number): string | undefined {
    const errors = form.errors as Record<string, string | undefined>;

    return (
        errors[`rooms.${index}.room_category_id`] ??
        errors[`rooms.${index}.package_id`]
    );
}

function occupancyRate(
    room: { room_category_id: number; meal_plan: string },
    occupancy: 'Extra Person' | 'Extra Child',
): number {
    return (
        roomRates.value[
            `${room.room_category_id}:${room.meal_plan}:${occupancy}`
        ] ?? 0
    );
}

function extraAdultsFor(room: {
    room_category_id: number;
    adults: number;
}): number {
    const ideal =
        categoryFor(room.room_category_id)?.ideal_occupancy ?? room.adults;

    return Math.max(0, room.adults - ideal);
}

function extraChildrenFor(room: {
    room_category_id: number;
    children: number;
}): number {
    const included =
        categoryFor(room.room_category_id)?.default_number_of_children ?? 0;

    return Math.max(0, room.children - included);
}

function extraChargeFor(room: {
    room_category_id: number;
    meal_plan: string;
    adults: number;
    children: number;
}): number {
    return (
        extraAdultsFor(room) * occupancyRate(room, 'Extra Person') +
        extraChildrenFor(room) * occupancyRate(room, 'Extra Child')
    );
}

function effectiveRateFor(room: {
    room_category_id: number;
    meal_plan: string;
    adults: number;
    children: number;
    rate_per_night: number;
}): number {
    return room.rate_per_night + extraChargeFor(room);
}

function roomCountOptions(categoryId: number | null): number[] {
    const max = categoryId === null ? 1 : availabilityFor(categoryId);

    // Nothing free on the dates: the category can't take a room.
    if (max <= 0) {
        return [0];
    }

    return Array.from({ length: max }, (_, index) => index + 1);
}

/**
 * Keeps a category line's room count within what is free on the dates: none when
 * nothing is free, back to one when rooms free up again.
 */
function clampRoomCount(line: CategoryLine): void {
    if (line.room_category_id === null) {
        return;
    }

    const max = availabilityFor(line.room_category_id);

    if (line.room_count > max) {
        line.room_count = Math.max(0, max);
    } else if (line.room_count === 0 && max > 0) {
        line.room_count = 1;
    }
}

/** Category lines with no room free on the booking's dates. */
const soldOutCategoryLines = computed(() =>
    categoryLines.value.filter(
        (line) =>
            line.room_category_id !== null &&
            availabilityFor(line.room_category_id) <= 0,
    ),
);

function isCategoryTakenElsewhere(
    categoryId: number,
    currentLineId: number,
): boolean {
    return categoryLines.value.some(
        (line) =>
            line.id !== currentLineId && line.room_category_id === categoryId,
    );
}

function categoryOptionLabel(
    category: { id: number; name: string; sell_by_bed?: boolean },
    currentLineId: number,
): string {
    if (isCategoryTakenElsewhere(category.id, currentLineId)) {
        return `${category.name} — already added`;
    }

    // A dormitory sells beds: its count is free beds.
    return `${category.name} (${availabilityFor(category.id)} ${category.sell_by_bed ? 'beds ' : ''}available)`;
}

function addCategoryLine(): void {
    const nextId =
        categoryLines.value.reduce((max, line) => Math.max(max, line.id), 0) +
        1;
    const usedCategoryIds = new Set(
        categoryLines.value.map((line) => line.room_category_id),
    );
    const nextCategory =
        props.roomCategories.find(
            (category) =>
                !usedCategoryIds.has(category.id) &&
                availabilityFor(category.id) > 0,
        ) ??
        props.roomCategories.find(
            (category) => !usedCategoryIds.has(category.id),
        ) ??
        null;

    categoryLines.value.push({
        id: nextId,
        room_category_id: nextCategory?.id ?? null,
        meal_plan: nextCategory?.meal_plans?.[0] ?? '',
        room_count: 1,
    });
}

function removeCategoryLine(line: CategoryLine): void {
    if (categoryLines.value.length <= 1) {
        return;
    }

    categoryLines.value = categoryLines.value.filter(
        (item) => item.id !== line.id,
    );
}

let nextRoomLineId = 1;

watch(
    categoryLines,
    (lines) => {
        const next: RoomLine[] = [];

        lines.forEach((line) => {
            if (line.room_category_id === null) {
                return;
            }

            clampRoomCount(line);

            const existing = roomLines.value.filter(
                (room) => room.category_line_id === line.id,
            );

            for (let index = 0; index < line.room_count; index++) {
                const category = categoryFor(line.room_category_id);
                const previous = existing[index];

                next.push({
                    id: previous?.id ?? nextRoomLineId++,
                    category_line_id: line.id,
                    room_category_id: line.room_category_id,
                    meal_plan: form.same_plan_for_all_rooms
                        ? line.meal_plan
                        : (previous?.meal_plan ?? line.meal_plan),
                    adults:
                        previous?.adults ??
                        Math.min(2, category?.max_adults ?? 1),
                    children: previous?.children ?? 0,
                    infants: previous?.infants ?? 0,
                    room_id: previous?.room_id ?? null,
                    rate_per_night: previous?.rate_per_night ?? 0,
                    package_id: previous?.package_id ?? null,
                    check_in_date: previous?.check_in_date ?? null,
                    check_out_date: previous?.check_out_date ?? null,
                });
            }
        });

        roomLines.value = next;
    },
    { deep: true, immediate: true },
);

const restoredRoomLineIds = new Set<number>();

/** Restores each saved room's occupancy, room number, meal plan and rate onto the generated room lines. */
(editing?.rooms ?? []).forEach((savedRoom) => {
    const line = roomLines.value.find(
        (room) =>
            room.room_category_id === savedRoom.room_category_id &&
            !restoredRoomLineIds.has(room.id),
    );

    if (!line) {
        return;
    }

    restoredRoomLineIds.add(line.id);
    Object.assign(line, {
        room_id: savedRoom.room_id,
        meal_plan: savedRoom.meal_plan || line.meal_plan,
        adults: savedRoom.adults,
        children: savedRoom.children,
        infants: savedRoom.infants,
        rate_per_night: savedRoom.rate_per_night,
        package_id: savedRoom.package_id ?? null,
        check_in_date: savedRoom.check_in_date ?? null,
        check_out_date: savedRoom.check_out_date ?? null,
        saved_bed: savedRoom.bed
            ? { room_id: savedRoom.room_id, bed: savedRoom.bed }
            : null,
    });
});

(props.prefill?.rooms ?? []).forEach((prefillRoom) => {
    const line = roomLines.value.find(
        (room) =>
            room.room_category_id === prefillRoom.room_category_id &&
            room.room_id === null,
    );

    if (line && prefillRoom.room_id !== null) {
        line.room_id = prefillRoom.room_id;
    }
});

const nights = computed(() => {
    const checkIn = new Date(`${form.check_in}T00:00:00`);
    const checkOut = new Date(`${form.check_out}T00:00:00`);
    const diff = Math.round(
        (checkOut.getTime() - checkIn.getTime()) / (1000 * 60 * 60 * 24),
    );

    return Math.max(1, diff);
});

/** A split booking: each room keeps its own dates, set on its room card. */
const isSplit = computed(() => !!editing?.rooms_split);

function nightsBetween(checkIn: string, checkOut: string): number {
    const diff = Math.round(
        (new Date(`${checkOut}T00:00:00`).getTime() -
            new Date(`${checkIn}T00:00:00`).getTime()) /
            86_400_000,
    );

    return Math.max(1, diff);
}

/** A room's own dates on a split booking (new rooms start on the booking's dates). */
function roomDates(room: RoomLine): { checkIn: string; checkOut: string } {
    return {
        checkIn: room.check_in_date ?? form.check_in,
        checkOut: room.check_out_date ?? form.check_out,
    };
}

function nightsFor(room: RoomLine): number {
    if (!isSplit.value) {
        return nights.value;
    }

    const { checkIn, checkOut } = roomDates(room);

    return nightsBetween(checkIn, checkOut);
}

const roomTotal = computed(() =>
    roomLines.value.reduce(
        (sum, room) => sum + effectiveRateFor(room) * nightsFor(room),
        0,
    ),
);

function discountedNetRatePerNightFor(room: RoomLine): number {
    const roomAmount = effectiveRateFor(room) * nightsFor(room);
    const discountShare =
        roomTotal.value > 0
            ? form.discount * (roomAmount / roomTotal.value)
            : 0;
    const netAmount = Math.max(0, roomAmount - discountShare);

    return netAmount / nightsFor(room);
}

function taxBaseRateFor(component: TaxComponent, room: RoomLine): number {
    return component.applied_on === 'discounted_net_rate'
        ? discountedNetRatePerNightFor(room)
        : effectiveRateFor(room);
}

function taxComponentLabel(component: TaxComponent): string {
    const name = component.component_choice || 'Tax';
    const representativeRoom = roomLines.value[0];

    if (!representativeRoom) {
        return name;
    }

    const { rate, type } = taxComponentRate(
        component,
        taxBaseRateFor(component, representativeRoom),
    );

    return type === 'percentage' ? `${name}@${rate}%` : name;
}

const taxBreakup = computed(() =>
    taxComponents.value.map((component) => ({
        label: taxComponentLabel(component),
        amount:
            Math.round(
                roomLines.value.reduce(
                    (sum, room) =>
                        sum +
                        taxComponentAmount(
                            component,
                            taxBaseRateFor(component, room),
                        ) *
                            nightsFor(room),
                    0,
                ) * 100,
            ) / 100,
    })),
);

const taxes = computed(
    () =>
        Math.round(
            taxBreakup.value.reduce((sum, item) => sum + item.amount, 0) * 100,
        ) / 100,
);
const subTotal = computed(
    () => Math.round((roomTotal.value - form.discount) * 100) / 100,
);
const total = computed(
    () =>
        Math.round((roomTotal.value - form.discount + taxes.value) * 100) / 100,
);

// Add-ons: priced like the server does (price × quantity × nights and/or guests, plus
// tax). A line saved before keeps the price it was sold at.
const addonToAdd = ref<number | null>(null);
const addonQuantity = ref(1);

const addonLines = computed(() => {
    const guests =
        roomLines.value.reduce(
            (sum, room) =>
                sum + Number(room.adults || 0) + Number(room.children || 0),
            0,
        ) || 1;
    const stayNights = Math.max(1, nights.value);

    return form.addons.map((line) => {
        const saved = editing?.addons?.find(
            (item) => item.addon_id === line.addon_id,
        );
        const addon = props.addonCatalogue.find(
            (item) => item.id === line.addon_id,
        );
        const pricing = saved?.pricing ?? addon?.pricing ?? 'per_booking';
        const unitPrice = saved?.unit_price ?? addon?.price ?? 0;
        const taxRate = saved?.tax_rate ?? addon?.tax_rate ?? 0;
        const multiplier =
            pricing === 'per_night'
                ? stayNights
                : pricing === 'per_guest'
                  ? guests
                  : pricing === 'per_guest_per_night'
                    ? guests * stayNights
                    : 1;
        const amount =
            Math.round(unitPrice * line.quantity * multiplier * 100) / 100;
        const tax = Math.round(((amount * taxRate) / 100) * 100) / 100;

        return {
            addon_id: line.addon_id,
            name: saved?.name ?? addon?.name ?? 'Add-on',
            pricing_label: saved?.pricing_label ?? addon?.pricing_label ?? '',
            unit_price: unitPrice,
            multiplier,
            amount,
            tax,
            total: Math.round((amount + tax) * 100) / 100,
        };
    });
});

const addonsTotal = computed(
    () =>
        Math.round(
            addonLines.value.reduce((sum, line) => sum + line.total, 0) * 100,
        ) / 100,
);
const grandTotal = computed(
    () => Math.round((total.value + addonsTotal.value) * 100) / 100,
);

/** Payments already taken on the booking being edited (as on the booking page). */
const totalPaid = computed(
    () =>
        Math.round(
            (props.billing?.booking.payments ?? []).reduce(
                (sum, payment) =>
                    sum +
                    (payment.tagged_amount ?? payment.total_txn_amount ?? 0),
                0,
            ) * 100,
        ) / 100,
);

/**
 * What the guest still owes: on an edit, the folio balance as saved (extras and POS
 * included) moved by the change to the bill; on a new booking, the total less what is paid.
 * Negative is a credit: paid beyond the bill.
 */
const outstanding = computed(() => {
    const saved = props.changeSettlement;
    const owed = saved
        ? saved.balance + grandTotal.value - saved.saved_bill
        : grandTotal.value - totalPaid.value;

    return Math.round(owed * 100) / 100;
});

// ── Saving a change that lowers the bill: cancellation charge and the credit left ──
const changeAmountTakenOff = computed(() =>
    props.changeSettlement
        ? Math.max(
              0,
              Math.round(
                  (props.changeSettlement.saved_bill - grandTotal.value) * 100,
              ) / 100,
          )
        : 0,
);

/** The policy tier the change falls in: the first whose "days from check-in" covers the days left. */
const changePolicyTier = computed(() => {
    const saved = props.changeSettlement;

    return saved?.tiers.find((tier) => saved.days_before <= tier.days) ?? null;
});

const cancellationChargePreview = computed(
    () =>
        Math.round(
            changeAmountTakenOff.value * (changePolicyTier.value?.percent ?? 0),
        ) / 100,
);

const settlement = ref({
    open: false,
    confirmed: false,
    apply_cancellation_charge: true,
    credit_action: 'refund' as
        | 'refund'
        | 'guest_ledger'
        | 'advance'
        | 'transfer'
        | 'leave',
    refund_mode: props.changeSettlement?.refund_modes[0]?.value ?? '',
    refund_reference: '',
    refund_notes: '',
    transfer_booking_id: null as number | null,
    pending: null as {
        after: 'edit' | null;
        action: 'primary' | 'secondary';
    } | null,
});

/** The credit once the change (and the charge, when applied) is saved. */
const creditAfterChange = computed(() => {
    const charge = settlement.value.apply_cancellation_charge
        ? cancellationChargePreview.value
        : 0;

    return Math.max(0, Math.round(-(outstanding.value + charge) * 100) / 100);
});

const transferTargets = computed(
    () => props.changeSettlement?.transfer_targets ?? [],
);

/** A saved edit that takes something off the bill, or leaves the guest in credit, asks first. */
function needsSettlement(): boolean {
    return (
        isEditing &&
        props.changeSettlement !== undefined &&
        (cancellationChargePreview.value > 0 || creditAfterChange.value > 0)
    );
}

function confirmSettlement(): void {
    const pending = settlement.value.pending;
    settlement.value.confirmed = true;
    settlement.value.open = false;
    submit(pending?.after ?? null, pending?.action ?? 'primary');
}

/**
 * Bill To Guest Company with a linked City Ledger account: the company's share of what is owed,
 * the room bill and/or the add-ons, less what was already moved to its account.
 */
const netPayableByCompany = computed(() => {
    if (
        !billedCompanyAccount.value ||
        (!form.bill_room_to_guest_company && !form.bill_extras_to_guest_company)
    ) {
        return null;
    }

    const share =
        (form.bill_room_to_guest_company ? total.value : 0) +
        (form.bill_extras_to_guest_company ? addonsTotal.value : 0) -
        (editing?.company_billing?.billed ?? 0);

    return (
        Math.round(
            Math.min(Math.max(0, outstanding.value), Math.max(0, share)) * 100,
        ) / 100
    );
});

/** What the guest pays: what is owed less the company's share. */
const netPayableByGuest = computed(() =>
    Math.max(
        0,
        Math.round(
            (outstanding.value - (netPayableByCompany.value ?? 0)) * 100,
        ) / 100,
    ),
);

/** The part of that to collect at the hotel: all of it on a pay-at-hotel booking. */
const netPayableAtHotel = computed(() =>
    form.pay_at_hotel ? netPayableByGuest.value : 0,
);

function addAddon(): void {
    if (addonToAdd.value === null) {
        return;
    }

    const quantity = Math.max(1, Math.floor(addonQuantity.value || 1));
    const existing = form.addons.find(
        (line) => line.addon_id === addonToAdd.value,
    );

    if (existing) {
        existing.quantity += quantity;
    } else {
        form.addons.push({ addon_id: addonToAdd.value, quantity });
    }

    addonToAdd.value = null;
    addonQuantity.value = 1;
}

function removeAddon(addonId: number): void {
    form.addons = form.addons.filter((line) => line.addon_id !== addonId);
}

const inclusionCategories = computed(() => {
    const usedIds = new Set(
        roomLines.value.map((room) => room.room_category_id),
    );

    return props.roomCategories.filter(
        (category) => usedIds.has(category.id) && category.inclusion_details,
    );
});

const summaryRoomLines = computed(() =>
    roomSummaryLines(
        roomLines.value.map((room) => ({
            category: categoryFor(room.room_category_id)?.name ?? '—',
            mealPlan: room.meal_plan,
            adults: room.adults,
        })),
    ),
);

function currency(value: number): string {
    return amount(value);
}

const pendingDateChange = ref<{ check_in: string; check_out: string } | null>(
    null,
);

/**
 * The picker's range while it is being picked: choosing a new check-in clears
 * check-out until the second click, so the form only changes once both are set.
 */
const draftCheckIn = ref(form.check_in);
const draftCheckOut = ref(form.check_out);

function resetDraftDates(): void {
    draftCheckIn.value = form.check_in;
    draftCheckOut.value = form.check_out;
}

watch(roomAvailability, () => {
    categoryLines.value.forEach(clampRoomCount);
});

watch(
    [roomLines, roomRates, overridePrice],
    () => {
        if (overridePrice.value) {
            return;
        }

        roomLines.value.forEach((room) => {
            const suggested = suggestedRateFor(room);

            // No rate in the plan for these dates: the room keeps the rate it has rather than 0.
            if (suggested <= 0 && room.rate_per_night > 0) {
                return;
            }

            if (room.rate_per_night !== suggested) {
                room.rate_per_night = suggested;
            }
        });
    },
    { deep: true, immediate: true },
);

watch(
    () => roomLines.value.map((room) => offerKey(room)),
    () => roomLines.value.forEach((room) => void loadPackageOffers(room)),
    { immediate: true },
);

async function fetchRoomAvailability(): Promise<void> {
    const requestId = ++roomAvailabilityRequestId;
    roomAvailabilityLoading.value = true;

    try {
        const response = await fetch(
            admin.bookings.roomAvailability(props.property, {
                query: {
                    check_in: form.check_in,
                    check_out: form.check_out,
                    ...(isEditing ? { exclude_booking_id: editing!.id } : {}),
                },
            }).url,
            { headers: { Accept: 'application/json' } },
        );
        const data = (await response.json()) as {
            availability: Record<number, number>;
            unavailable_room_ids: number[];
            free_beds?: Record<number, number>;
        };

        if (requestId === roomAvailabilityRequestId) {
            roomAvailability.value = data.availability;
            unavailableRoomIds.value = data.unavailable_room_ids;
            freeBeds.value = data.free_beds ?? {};
        }
    } finally {
        if (requestId === roomAvailabilityRequestId) {
            roomAvailabilityLoading.value = false;
        }
    }
}

async function fetchRateSuggestions(): Promise<void> {
    const requestId = ++roomRatesRequestId;
    roomRatesLoading.value = true;

    try {
        const response = await fetch(
            admin.bookings.rateSuggestions(props.property, {
                query: { check_in: form.check_in, check_out: form.check_out },
            }).url,
            { headers: { Accept: 'application/json' } },
        );
        const data = (await response.json()) as {
            nights: { date: string; day_key: string; plan_id: number | null }[];
            rates: Record<number, Record<string, Record<string, number>>>;
            taxComponents: TaxComponent[];
        };

        if (requestId !== roomRatesRequestId) {
            return;
        }

        taxComponents.value = data.taxComponents;

        const totals: Record<string, { sum: number; count: number }> = {};

        data.nights.forEach((night) => {
            if (night.plan_id === null) {
                return;
            }

            const planRates = data.rates[night.plan_id];

            if (!planRates) {
                return;
            }

            Object.entries(planRates).forEach(([rowKey, dayRates]) => {
                const nightlyRate = dayRates[night.day_key];

                if (nightlyRate === undefined) {
                    return;
                }

                const entry =
                    totals[rowKey] ?? (totals[rowKey] = { sum: 0, count: 0 });
                entry.sum += nightlyRate;
                entry.count += 1;
            });
        });

        roomRates.value = Object.fromEntries(
            Object.entries(totals).map(([rowKey, { sum, count }]) => [
                rowKey,
                Math.round((sum / count) * 100) / 100,
            ]),
        );
    } finally {
        if (requestId === roomRatesRequestId) {
            roomRatesLoading.value = false;
        }
    }
}

onMounted(() => {
    fetchRoomAvailability();
    fetchRateSuggestions();
});

function requestDateChange(
    field: 'check_in' | 'check_out',
    value: string,
): void {
    if (field === 'check_in') {
        draftCheckIn.value = value;
    } else {
        draftCheckOut.value = value;
    }

    if (
        !draftCheckIn.value ||
        !draftCheckOut.value ||
        draftCheckOut.value <= draftCheckIn.value
    ) {
        return;
    }

    if (
        draftCheckIn.value === form.check_in &&
        draftCheckOut.value === form.check_out
    ) {
        return;
    }

    pendingDateChange.value = {
        check_in: draftCheckIn.value,
        check_out: draftCheckOut.value,
    };
}

function confirmDateChange(): void {
    if (!pendingDateChange.value) {
        return;
    }

    form.check_in = pendingDateChange.value.check_in;
    form.check_out = pendingDateChange.value.check_out;
    roomLines.value = roomLines.value.map((room) => ({
        ...room,
        room_id: null,
    }));
    pendingDateChange.value = null;
    fetchRoomAvailability();
    fetchRateSuggestions();
}

function cancelDateChange(): void {
    pendingDateChange.value = null;
    resetDraftDates();
}

/** Lets each room of this booking keep its own dates (reloads the form in split mode). */
function splitRooms(): void {
    if (!editing) {
        return;
    }

    router.post(
        admin.bookings.split({
            property: props.property.id,
            booking: editing.id,
        }).url,
        {},
        { preserveScroll: true },
    );
}

/** Which footer button started the save, so only that one shows the spinner. */
const savingAction = ref<'primary' | 'secondary' | null>(null);

/**
 * Field errors sit up the page, out of view when saving from the sticky footer, so a failed
 * save is also shown as a floating toast (the layout shows the success message).
 */
const savedToast = ref('');
const savedToastIsError = ref(false);
let savedToastTimer: ReturnType<typeof setTimeout> | undefined;

function showSavedToast(message: string, isError = false): void {
    savedToast.value = message;
    savedToastIsError.value = isError;
    clearTimeout(savedToastTimer);
    savedToastTimer = setTimeout(
        () => (savedToast.value = ''),
        isError ? 8000 : 5000,
    );
}

onBeforeUnmount(() => clearTimeout(savedToastTimer));

/**
 * @param after "edit" (Save & Add Payment) reopens the saved booking in this form instead of going to the dashboard.
 */
function submit(
    after: 'edit' | null = null,
    action: 'primary' | 'secondary' = 'primary',
): void {
    if (!settlement.value.confirmed && needsSettlement()) {
        settlement.value.pending = { after, action };
        settlement.value.open = true;

        return;
    }

    const settlementFields = settlement.value.confirmed
        ? {
              apply_cancellation_charge:
                  cancellationChargePreview.value > 0 &&
                  settlement.value.apply_cancellation_charge,
              credit_action:
                  creditAfterChange.value > 0
                      ? settlement.value.credit_action
                      : 'leave',
              refund_mode: settlement.value.refund_mode,
              refund_reference: settlement.value.refund_reference,
              refund_notes: settlement.value.refund_notes,
              transfer_booking_id: settlement.value.transfer_booking_id,
          }
        : {};
    // Asked again on the next save if this one fails.
    settlement.value.confirmed = false;
    savingAction.value = action;
    form.status = editing?.status ?? newBookingStatus();
    form.discount = Number(form.discount) || 0;
    form.rooms = roomLines.value.map((room) => ({
        room_category_id: room.room_category_id,
        room_id: room.room_id,
        meal_plan: room.meal_plan,
        package_id: room.package_id ?? null,
        adults: room.adults,
        children: room.children,
        infants: room.infants,
        rate_per_night: effectiveRateFor(room),
        ...(isSplit.value
            ? {
                  check_in_date: roomDates(room).checkIn,
                  check_out_date: roomDates(room).checkOut,
              }
            : {}),
    }));

    form.transform((data) => ({
        ...(hasReleaseReminder.value
            ? {
                  ...data,
                  hold_release_at: propertyTime.fromInput(data.hold_release_at),
              }
            : {
                  ...data,
                  hold_release_at: null,
                  hold_auto_release: false,
                  hold_reminders: [],
              }),
        // Files require multipart, so updates are POSTed with method spoofing.
        ...(isEditing ? { _method: 'put' } : {}),
        ...(after ? { after } : {}),
        override_restrictions: overrideRestrictions.value,
        ...settlementFields,
    })).post(
        isEditing
            ? admin.bookings.update({
                  property: props.property.id,
                  booking: editing!.id,
              }).url
            : admin.bookings.store(props.property).url,
        {
            preserveScroll: 'errors',
            // Same page component after the redirect (create → edit): rebuild it from the new props unless validation failed.
            preserveState: 'errors',
            onError: (errors) => {
                const messages = Object.values(errors);
                showSavedToast(
                    `Not saved — ${messages.slice(0, 3).join(' ')}${messages.length > 3 ? ` (+${messages.length - 3} more)` : ''}`,
                    true,
                );
            },
            onFinish: () => (savingAction.value = null),
        },
    );
}
</script>

<template>
    <Head
        :title="
            isEditing
                ? `Edit ${isEnquiry ? 'Enquiry' : 'Booking'} ${editing!.booking_ref_id}`
                : `${isEnquiry ? 'Log Enquiry' : 'Quick Book'}: ${property.name}`
        "
    />

    <div
        v-if="!isEditing && prefill?.waitlist_request_id"
        class="mb-4 w-full max-w-5xl rounded-md border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800 dark:border-green-900 dark:bg-green-950 dark:text-green-300"
        data-test="waitlist-banner"
    >
        Converting the waitlist request for
        <strong>{{ prefill.guest_name }}</strong> ({{ prefill.check_in }} to
        {{ prefill.check_out }}, {{ prefill.rooms.length }} room{{
            prefill.rooms.length === 1 ? '' : 's'
        }}). Saving this booking closes the request.
    </div>

    <div class="mb-6 flex w-full max-w-5xl items-center justify-between">
        <h1
            class="font-display text-2xl font-semibold text-[#1b1b18] dark:text-[#EDEDEC]"
        >
            <template v-if="isEditing"
                >Edit {{ isEnquiry ? 'Enquiry' : 'Booking' }}
                {{ editing!.booking_ref_id }} — {{ property.name }}</template
            >
            <template v-else
                >{{ isEnquiry ? 'Log Enquiry' : 'Quick Book' }} —
                {{ property.name }}</template
            >
        </h1>
        <Link
            :href="
                isEditing
                    ? admin.bookings.show({
                          property: property.id,
                          booking: editing!.id,
                      }).url
                    : admin.dashboard({ query: { property: property.id } }).url
            "
            class="rounded-md border border-[#e3e3e0] px-4 py-2 text-sm font-medium text-[#1b1b18] dark:border-[#3E3E3A] dark:text-[#EDEDEC]"
        >
            Back
        </Link>
    </div>

    <div class="flex w-full max-w-5xl flex-col items-start gap-4 lg:flex-row">
        <!-- Left booking summary panel, detached from the form -->
        <BookingSummaryCard
            class="w-full shrink-0 lg:sticky lg:top-4 lg:w-96"
            :booking-number="editing?.booking_ref_id"
            :ref-id="form.booking_ref_id"
            :check-in="form.check_in"
            :check-out="form.check_out"
            :nights="nights"
            :room-lines="summaryRoomLines"
            :total="grandTotal"
            :outstanding="outstanding"
            :company-due="netPayableByCompany"
            :source="form.source"
            :status="form.status"
        />

        <!-- Right form panel, its own detached box -->
        <div
            class="w-full flex-1 overflow-hidden rounded-lg border border-[#e3e3e0] px-6 py-4 dark:border-[#3E3E3A]"
        >
            <h3
                class="mb-4 text-base font-semibold text-[#1b1b18] dark:text-[#EDEDEC]"
            >
                Booking Details
            </h3>

            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <label
                    class="flex flex-col gap-1 text-sm text-[#706f6c] dark:text-[#A1A09A]"
                >
                    Booking source
                    <select
                        v-model="form.source"
                        class="block rounded-md border border-[#e3e3e0] bg-white px-3 py-2 text-sm text-[#1b1b18] focus:border-[#f53003] focus:ring-1 focus:ring-[#f53003] focus:outline-none dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]"
                    >
                        <option
                            v-for="option in bookingSourceOptions"
                            :key="option.value"
                            :value="option.value"
                        >
                            {{ option.label }}
                        </option>
                    </select>
                </label>

                <div
                    v-if="isAgentSource"
                    class="flex flex-col gap-1 text-sm text-[#706f6c] dark:text-[#A1A09A]"
                >
                    Agency/Contact Name
                    <AgentCombobox
                        :model-value="selectedAgent"
                        :property="property"
                        :agencies="agencies"
                        :rate-codes="agentRateCodes"
                        :countries="countries"
                        :error="form.errors.agent_id"
                        @update:model-value="
                            selectedAgent = $event;
                            form.agent_id = $event?.id ?? null;
                        "
                    />
                </div>

                <div
                    v-else-if="form.source === 'ota'"
                    class="flex flex-col gap-1 text-sm text-[#706f6c] dark:text-[#A1A09A]"
                >
                    Selected OTA
                    <SearchableSelect
                        :model-value="form.booking_channel_id ?? ''"
                        :options="otaOptions"
                        placeholder="Search OTA"
                        @update:model-value="
                            form.booking_channel_id =
                                $event === '' ? null : $event
                        "
                    />
                    <p
                        v-if="form.errors.booking_channel_id"
                        class="text-xs text-red-600"
                    >
                        {{ form.errors.booking_channel_id }}
                    </p>
                </div>

                <label
                    v-else-if="form.source === 'meta'"
                    class="flex flex-col gap-1 text-sm text-[#706f6c] dark:text-[#A1A09A]"
                >
                    Type
                    <select
                        v-model="form.metasearch_platform_id"
                        class="block rounded-md border border-[#e3e3e0] bg-white px-3 py-2 text-sm text-[#1b1b18] focus:border-[#f53003] focus:ring-1 focus:ring-[#f53003] focus:outline-none dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]"
                    >
                        <option :value="null">--Select--</option>
                        <option
                            v-for="platform in metaPlatforms"
                            :key="platform.id"
                            :value="platform.id"
                        >
                            {{ platform.name }}
                        </option>
                    </select>
                    <span
                        v-if="form.errors.metasearch_platform_id"
                        class="text-xs text-red-600"
                        >{{ form.errors.metasearch_platform_id }}</span
                    >
                </label>

                <label
                    v-else
                    class="flex flex-col gap-1 text-sm text-[#706f6c] dark:text-[#A1A09A]"
                >
                    Type
                    <select
                        v-model="form.type"
                        class="block rounded-md border border-[#e3e3e0] bg-white px-3 py-2 text-sm text-[#1b1b18] focus:border-[#f53003] focus:ring-1 focus:ring-[#f53003] focus:outline-none dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]"
                    >
                        <option
                            v-for="option in typeOptions"
                            :key="option.value"
                            :value="option.value"
                        >
                            {{ option.label }}
                        </option>
                    </select>
                </label>

                <label
                    class="col-span-full flex flex-col gap-1 text-sm text-[#706f6c] dark:text-[#A1A09A]"
                >
                    Booking Ref. Id
                    <input
                        v-model="form.booking_ref_id"
                        type="text"
                        class="block rounded-md border border-[#e3e3e0] bg-white px-3 py-2 text-sm text-[#1b1b18] focus:border-[#f53003] focus:ring-1 focus:ring-[#f53003] focus:outline-none dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]"
                    />
                </label>

                <label
                    class="flex flex-col gap-1 text-sm text-[#706f6c] dark:text-[#A1A09A]"
                >
                    <span>Check-in&nbsp;&nbsp;|&nbsp;&nbsp;Check-out</span>
                    <p
                        v-if="isSplit"
                        class="rounded-md border border-sky-200 bg-sky-50 px-3 py-2 text-xs text-sky-800 dark:border-sky-900 dark:bg-sky-950/40 dark:text-sky-300"
                    >
                        Split booking — each room has its own dates below. The
                        booking spans {{ form.check_in }} →
                        {{ form.check_out }}.
                    </p>
                    <DateRangePicker
                        v-else
                        :holidays="holidays"
                        :check-in="draftCheckIn"
                        :check-out="draftCheckOut"
                        @update:check-in="requestDateChange('check_in', $event)"
                        @update:check-out="
                            requestDateChange('check_out', $event)
                        "
                    />
                    <button
                        v-if="isEditing && !isSplit && roomLines.length > 1"
                        type="button"
                        class="self-start text-xs font-semibold text-sky-700 hover:underline dark:text-sky-400"
                        @click="splitRooms"
                    >
                        Split rooms — give each room its own dates
                    </button>
                </label>

                <label
                    class="flex flex-col gap-1 text-sm text-[#706f6c] dark:text-[#A1A09A]"
                >
                    Booking Type
                    <select
                        v-model="form.booking_type"
                        class="block rounded-md border border-[#e3e3e0] bg-white px-3 py-2 text-sm text-[#1b1b18] focus:border-[#f53003] focus:ring-1 focus:ring-[#f53003] focus:outline-none dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]"
                    >
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

                <label
                    class="flex flex-col gap-1 text-sm text-[#706f6c] dark:text-[#A1A09A]"
                >
                    Check-in Time
                    <input
                        v-model="form.check_in_time"
                        type="time"
                        class="block rounded-md border border-[#e3e3e0] bg-white px-3 py-2 text-sm text-[#1b1b18] focus:border-[#f53003] focus:ring-1 focus:ring-[#f53003] focus:outline-none dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]"
                    />
                </label>
                <label
                    class="flex flex-col gap-1 text-sm text-[#706f6c] dark:text-[#A1A09A]"
                >
                    Check-out Time
                    <input
                        v-model="form.check_out_time"
                        type="time"
                        class="block rounded-md border border-[#e3e3e0] bg-white px-3 py-2 text-sm text-[#1b1b18] focus:border-[#f53003] focus:ring-1 focus:ring-[#f53003] focus:outline-none dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]"
                    />
                </label>

                <div class="col-span-full flex items-center gap-6">
                    <label
                        class="flex items-center gap-2 text-sm font-medium text-[#1b1b18] dark:text-[#EDEDEC]"
                    >
                        <input
                            v-model="form.same_plan_for_all_rooms"
                            type="checkbox"
                            class="h-4 w-4 rounded border-[#e3e3e0] text-[#f53003] focus:ring-[#f53003] dark:border-[#3E3E3A]"
                        />
                        Same Plan for All Rooms
                    </label>
                    <label
                        class="flex items-center gap-2 text-sm font-medium text-[#1b1b18] dark:text-[#EDEDEC]"
                    >
                        <input
                            v-model="form.pay_at_hotel"
                            type="checkbox"
                            class="h-4 w-4 rounded border-[#e3e3e0] text-[#f53003] focus:ring-[#f53003] dark:border-[#3E3E3A]"
                        />
                        Pay At Hotel
                    </label>
                </div>

                <label
                    class="col-span-full flex flex-col gap-1 text-sm text-[#706f6c] dark:text-[#A1A09A]"
                >
                    Rate Plan
                    <select
                        v-model="form.rate_plan"
                        class="block rounded-md border border-[#e3e3e0] bg-white px-3 py-2 text-sm text-[#1b1b18] focus:border-[#f53003] focus:ring-1 focus:ring-[#f53003] focus:outline-none dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]"
                    >
                        <option value="best_available_rates">
                            Best Available Rates
                        </option>
                    </select>
                </label>
            </div>

            <div
                v-for="line in categoryLines"
                :key="line.id"
                class="mt-4 grid grid-cols-1 items-end gap-4"
                :class="
                    form.same_plan_for_all_rooms
                        ? 'sm:grid-cols-[1fr_1fr_120px_auto]'
                        : 'sm:grid-cols-[1fr_120px_auto]'
                "
            >
                <label
                    class="flex flex-col gap-1 text-sm text-[#706f6c] dark:text-[#A1A09A]"
                >
                    <span class="flex items-center gap-2">
                        Category
                        <svg
                            v-if="roomAvailabilityLoading"
                            class="h-3.5 w-3.5 animate-spin text-[#706f6c] dark:text-[#A1A09A]"
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
                                d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"
                            ></path>
                        </svg>
                    </span>
                    <select
                        v-model.number="line.room_category_id"
                        class="block rounded-md border border-[#e3e3e0] bg-white px-3 py-2 text-sm text-[#1b1b18] focus:border-[#f53003] focus:ring-1 focus:ring-[#f53003] focus:outline-none dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]"
                    >
                        <option
                            v-for="category in roomCategories"
                            :key="category.id"
                            :value="category.id"
                            :disabled="
                                availabilityFor(category.id) === 0 ||
                                isCategoryTakenElsewhere(category.id, line.id)
                            "
                        >
                            {{ categoryOptionLabel(category, line.id) }}
                        </option>
                    </select>
                </label>
                <label
                    v-if="form.same_plan_for_all_rooms"
                    class="flex flex-col gap-1 text-sm text-[#706f6c] dark:text-[#A1A09A]"
                >
                    Meal Plan
                    <select
                        v-model="line.meal_plan"
                        class="block rounded-md border border-[#e3e3e0] bg-white px-3 py-2 text-sm text-[#1b1b18] focus:border-[#f53003] focus:ring-1 focus:ring-[#f53003] focus:outline-none dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]"
                    >
                        <option
                            v-for="plan in categoryFor(line.room_category_id)
                                ?.meal_plans ?? []"
                            :key="plan"
                            :value="plan"
                        >
                            {{ plan }}
                        </option>
                    </select>
                </label>
                <label
                    class="flex flex-col gap-1 text-sm text-[#706f6c] dark:text-[#A1A09A]"
                >
                    {{ unitLabel(line.room_category_id) }}
                    <select
                        v-model.number="line.room_count"
                        class="block rounded-md border border-[#e3e3e0] bg-white px-3 py-2 text-sm text-[#1b1b18] focus:border-[#f53003] focus:ring-1 focus:ring-[#f53003] focus:outline-none dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]"
                    >
                        <option
                            v-for="count in roomCountOptions(
                                line.room_category_id,
                            )"
                            :key="count"
                            :value="count"
                        >
                            {{ count }}
                        </option>
                    </select>
                </label>
                <div class="flex items-center gap-2">
                    <button
                        v-if="categoryLines.length > 1"
                        type="button"
                        title="Remove category"
                        class="text-[#706f6c] hover:text-[#f53003] dark:text-[#A1A09A] dark:hover:text-[#FF4433]"
                        @click="removeCategoryLine(line)"
                    >
                        🗑
                    </button>
                    <button
                        type="button"
                        title="Add another category"
                        class="flex h-9 w-9 items-center justify-center rounded-full border border-[#f53003] text-[#f53003] hover:bg-[#f53003]/10 dark:border-[#FF4433] dark:text-[#FF4433]"
                        :class="{ invisible: line.id !== categoryLines[0].id }"
                        @click="addCategoryLine"
                    >
                        +
                    </button>
                </div>
            </div>

            <div
                class="mt-6 border-t border-[#e3e3e0] pt-4 dark:border-[#3E3E3A]"
            >
                <div class="mb-3 flex items-center justify-between">
                    <div>
                        <h3
                            class="text-base font-semibold text-[#1b1b18] dark:text-[#EDEDEC]"
                        >
                            Requirements
                        </h3>
                        <p
                            v-if="form.errors.rooms"
                            class="mt-1 text-sm text-[#f53003] dark:text-[#FF4433]"
                        >
                            {{ form.errors.rooms }}
                        </p>
                    </div>
                    <label
                        class="flex items-center gap-2 text-sm font-medium text-[#1b1b18] dark:text-[#EDEDEC]"
                    >
                        <input
                            v-model="overridePrice"
                            :disabled="!can('reservation.price_override')"
                            :title="
                                can('reservation.price_override')
                                    ? undefined
                                    : 'You don\'t have permission to override prices'
                            "
                            type="checkbox"
                            class="h-4 w-4 rounded border-[#e3e3e0] text-[#f53003] focus:ring-[#f53003] dark:border-[#3E3E3A]"
                        />
                        Override Price
                    </label>
                    <label
                        v-if="can('reservation.override_restrictions')"
                        class="flex items-center gap-2 text-sm font-medium text-[#1b1b18] dark:text-[#EDEDEC]"
                        title="Book although stay restrictions or a package's conditions rule the stay out"
                    >
                        <input
                            v-model="overrideRestrictions"
                            type="checkbox"
                            class="h-4 w-4 rounded border-[#e3e3e0] text-[#f53003] focus:ring-[#f53003] dark:border-[#3E3E3A]"
                        />
                        Override restrictions
                    </label>
                </div>

                <p
                    v-for="line in soldOutCategoryLines"
                    :key="`sold-out-${line.id}`"
                    class="mb-3 rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-800 dark:border-amber-900 dark:bg-amber-950/40 dark:text-amber-200"
                    data-test="category-sold-out"
                >
                    No {{ categoryFor(line.room_category_id)?.name }} rooms are
                    free on these dates. Pick other dates or another category.
                </p>

                <div
                    v-for="(room, index) in roomLines"
                    :key="room.id"
                    class="mb-4 rounded-lg border border-[#e3e3e0] p-4 dark:border-[#3E3E3A]"
                >
                    <p
                        class="mb-3 text-sm font-semibold text-[#1b1b18] dark:text-[#EDEDEC]"
                    >
                        {{ unitLabel(room.room_category_id, false) }}
                        {{ index + 1 }} |
                        {{ categoryFor(room.room_category_id)?.name }}
                        <span
                            v-if="isSplit"
                            class="ml-1 font-normal text-[#706f6c] dark:text-[#A1A09A]"
                            >· {{ nightsFor(room) }} night(s)</span
                        >
                    </p>
                    <p
                        v-if="roomError(index)"
                        class="mb-2 text-sm text-[#f53003] dark:text-[#FF4433]"
                    >
                        {{ roomError(index) }}
                    </p>
                    <p
                        v-else-if="restrictionFor(room)"
                        class="mb-2 rounded bg-amber-50 px-2 py-1 text-xs text-amber-800 dark:bg-amber-950 dark:text-amber-300"
                    >
                        Restricted: {{ restrictionFor(room) }}
                    </p>
                    <div
                        v-if="isSplit"
                        class="mb-3 flex flex-wrap items-end gap-4"
                    >
                        <label
                            class="flex flex-col gap-1 text-sm text-[#706f6c] dark:text-[#A1A09A]"
                        >
                            Check-in
                            <input
                                type="date"
                                :value="roomDates(room).checkIn"
                                class="block rounded-md border border-[#e3e3e0] bg-white px-2 py-1.5 text-sm text-[#1b1b18] dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]"
                                @change="
                                    room.check_in_date =
                                        ($event.target as HTMLInputElement)
                                            .value || null
                                "
                            />
                        </label>
                        <label
                            class="flex flex-col gap-1 text-sm text-[#706f6c] dark:text-[#A1A09A]"
                        >
                            Check-out
                            <input
                                type="date"
                                :value="roomDates(room).checkOut"
                                :min="roomDates(room).checkIn"
                                class="block rounded-md border border-[#e3e3e0] bg-white px-2 py-1.5 text-sm text-[#1b1b18] dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]"
                                @change="
                                    room.check_out_date =
                                        ($event.target as HTMLInputElement)
                                            .value || null
                                "
                            />
                        </label>
                    </div>
                    <div class="flex flex-wrap items-end gap-4">
                        <label
                            class="flex flex-col gap-1 text-sm text-[#706f6c] dark:text-[#A1A09A]"
                        >
                            Adults
                            <input
                                v-model.number="room.adults"
                                type="number"
                                min="1"
                                :max="
                                    categoryFor(room.room_category_id)
                                        ?.max_adults ?? 4
                                "
                                class="block w-20 rounded-md border border-[#e3e3e0] bg-white px-2 py-1.5 text-sm text-[#1b1b18] focus:border-[#f53003] focus:ring-1 focus:ring-[#f53003] focus:outline-none dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]"
                            />
                        </label>
                        <label
                            class="flex flex-col gap-1 text-sm text-[#706f6c] dark:text-[#A1A09A]"
                        >
                            Children
                            <input
                                v-model.number="room.children"
                                type="number"
                                min="0"
                                :max="
                                    categoryFor(room.room_category_id)
                                        ?.max_children ?? 4
                                "
                                class="block w-20 rounded-md border border-[#e3e3e0] bg-white px-2 py-1.5 text-sm text-[#1b1b18] focus:border-[#f53003] focus:ring-1 focus:ring-[#f53003] focus:outline-none dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]"
                            />
                        </label>
                        <label
                            class="flex flex-col gap-1 text-sm text-[#706f6c] dark:text-[#A1A09A]"
                        >
                            Infant
                            <input
                                v-model.number="room.infants"
                                type="number"
                                min="0"
                                max="4"
                                class="block w-20 rounded-md border border-[#e3e3e0] bg-white px-2 py-1.5 text-sm text-[#1b1b18] focus:border-[#f53003] focus:ring-1 focus:ring-[#f53003] focus:outline-none dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]"
                            />
                        </label>
                        <label
                            v-if="!form.same_plan_for_all_rooms"
                            class="flex flex-col gap-1 text-sm text-[#706f6c] dark:text-[#A1A09A]"
                        >
                            Meal Plan
                            <select
                                v-model="room.meal_plan"
                                class="block w-28 rounded-md border border-[#e3e3e0] bg-white px-2 py-1.5 text-sm text-[#1b1b18] focus:border-[#f53003] focus:ring-1 focus:ring-[#f53003] focus:outline-none dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]"
                            >
                                <option
                                    v-for="plan in categoryFor(
                                        room.room_category_id,
                                    )?.meal_plans ?? []"
                                    :key="plan"
                                    :value="plan"
                                >
                                    {{ plan }}
                                </option>
                            </select>
                        </label>
                        <label
                            v-if="offersFor(room).length || room.package_id"
                            class="flex flex-col gap-1 text-sm text-[#706f6c] dark:text-[#A1A09A]"
                        >
                            Package
                            <select
                                v-model="room.package_id"
                                :data-test="`room-package-${index}`"
                                class="block w-48 rounded-md border border-[#e3e3e0] bg-white px-2 py-1.5 text-sm text-[#1b1b18] focus:border-[#f53003] focus:ring-1 focus:ring-[#f53003] focus:outline-none dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]"
                            >
                                <option :value="null">None (rate plan)</option>
                                <option
                                    v-for="offer in offersFor(room)"
                                    :key="offer.id"
                                    :value="offer.id"
                                >
                                    {{ offer.name }} —
                                    {{ offer.rate_per_night }}/night
                                </option>
                                <option
                                    v-if="
                                        room.package_id &&
                                        !offersFor(room).some(
                                            (offer) =>
                                                offer.id === room.package_id,
                                        )
                                    "
                                    :value="room.package_id"
                                >
                                    Saved package (not on sale for these dates)
                                </option>
                            </select>
                        </label>
                        <label
                            class="flex flex-col gap-1 text-sm text-[#706f6c] dark:text-[#A1A09A]"
                        >
                            {{
                                categoryFor(room.room_category_id)?.sell_by_bed
                                    ? 'Dorm room'
                                    : 'Room No.'
                            }}
                            <select
                                v-model.number="room.room_id"
                                class="block w-32 rounded-md border border-[#e3e3e0] bg-white px-2 py-1.5 text-sm text-[#1b1b18] focus:border-[#f53003] focus:ring-1 focus:ring-[#f53003] focus:outline-none dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]"
                            >
                                <option :value="null">Auto</option>
                                <option
                                    v-for="option in categoryFor(
                                        room.room_category_id,
                                    )?.rooms ?? []"
                                    :key="option.id"
                                    :value="option.id"
                                    :disabled="
                                        roomOptionDisabled(room, option.id)
                                    "
                                >
                                    {{ roomOptionLabel(option) }}
                                </option>
                            </select>
                            <span
                                v-if="
                                    room.saved_bed &&
                                    room.saved_bed.room_id === room.room_id
                                "
                                class="text-xs text-[#706f6c] dark:text-[#A1A09A]"
                                >Bed {{ room.saved_bed.bed }}</span
                            >
                            <span
                                v-else-if="
                                    categoryFor(room.room_category_id)
                                        ?.sell_by_bed
                                "
                                class="text-xs text-[#706f6c] dark:text-[#A1A09A]"
                                >Auto picks the first dorm room with a free bed;
                                a free bed is assigned on save</span
                            >
                        </label>
                        <label
                            class="flex flex-col gap-1 text-sm text-[#706f6c] dark:text-[#A1A09A]"
                        >
                            Rate / night
                            <input
                                v-model.number="room.rate_per_night"
                                :data-test="`room-rate-${index}`"
                                type="number"
                                min="0"
                                step="0.01"
                                :disabled="!overridePrice"
                                class="block w-28 rounded-md border border-[#e3e3e0] bg-white px-2 py-1.5 text-sm text-[#1b1b18] focus:border-[#f53003] focus:ring-1 focus:ring-[#f53003] focus:outline-none disabled:cursor-not-allowed disabled:bg-[#f5f5f4] disabled:text-[#706f6c] dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC] dark:disabled:bg-[#1D1D1B] dark:disabled:text-[#A1A09A]"
                            />
                        </label>
                        <div class="ml-auto text-right">
                            <p
                                v-if="
                                    extraAdultsFor(room) > 0 ||
                                    extraChildrenFor(room) > 0
                                "
                                class="text-xs text-[#706f6c] dark:text-[#A1A09A]"
                            >
                                <span v-if="extraAdultsFor(room) > 0"
                                    >+{{ extraAdultsFor(room) }} extra adult @
                                    {{ symbol }}
                                    {{
                                        occupancyRate(room, 'Extra Person')
                                    }}</span
                                >
                                <span
                                    v-if="
                                        extraAdultsFor(room) > 0 &&
                                        extraChildrenFor(room) > 0
                                    "
                                    >,
                                </span>
                                <span v-if="extraChildrenFor(room) > 0"
                                    >+{{ extraChildrenFor(room) }} extra child @
                                    {{ symbol }}
                                    {{
                                        occupancyRate(room, 'Extra Child')
                                    }}</span
                                >
                            </p>
                            <p
                                class="text-sm font-semibold text-[#1b1b18] dark:text-[#EDEDEC]"
                            >
                                {{ symbol }}
                                {{ currency(effectiveRateFor(room) * nights) }}
                                ({{ nights }} nt)
                            </p>
                        </div>
                    </div>
                </div>

                <p
                    class="mb-3 text-sm font-medium text-[#1b1b18] dark:text-[#EDEDEC]"
                >
                    Total Selected Rooms {{ roomLines.length }}
                </p>

                <div
                    v-if="addonCatalogue.length || form.addons.length"
                    class="mb-4"
                >
                    <h3
                        class="mb-2 text-base font-semibold text-[#1b1b18] dark:text-[#EDEDEC]"
                    >
                        Add-ons
                    </h3>
                    <div class="flex flex-wrap items-end gap-2">
                        <label
                            class="flex min-w-0 flex-1 flex-col gap-1 text-sm text-[#706f6c] dark:text-[#A1A09A]"
                        >
                            Service
                            <select
                                v-model="addonToAdd"
                                class="rounded-md border border-[#e3e3e0] bg-white px-2 py-1.5 text-sm text-[#1b1b18] dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]"
                            >
                                <option :value="null">Choose an add-on</option>
                                <option
                                    v-for="addon in addonCatalogue"
                                    :key="addon.id"
                                    :value="addon.id"
                                >
                                    {{ addon.name }} ·
                                    {{ currency(addon.price) }}
                                    {{ addon.pricing_label.toLowerCase() }}
                                </option>
                            </select>
                        </label>
                        <label
                            class="flex w-20 flex-col gap-1 text-sm text-[#706f6c] dark:text-[#A1A09A]"
                        >
                            Qty
                            <input
                                v-model.number="addonQuantity"
                                type="number"
                                min="1"
                                max="999"
                                class="rounded-md border border-[#e3e3e0] bg-white px-2 py-1.5 text-sm text-[#1b1b18] dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]"
                            />
                        </label>
                        <button
                            type="button"
                            class="rounded-md border border-[#e3e3e0] px-3 py-1.5 text-sm font-medium text-[#1b1b18] disabled:opacity-40 dark:border-[#3E3E3A] dark:text-[#EDEDEC]"
                            :disabled="addonToAdd === null"
                            @click="addAddon"
                        >
                            Add
                        </button>
                    </div>
                    <table
                        v-if="addonLines.length"
                        class="mt-3 w-full text-sm text-[#1b1b18] dark:text-[#EDEDEC]"
                    >
                        <tbody
                            class="divide-y divide-[#e3e3e0] dark:divide-[#3E3E3A]"
                        >
                            <tr
                                v-for="(line, index) in addonLines"
                                :key="line.addon_id"
                            >
                                <td class="py-1.5">
                                    {{ line.name }}
                                    <span
                                        class="block text-xs text-[#706f6c] dark:text-[#A1A09A]"
                                        >{{ currency(line.unit_price) }}
                                        {{ line.pricing_label.toLowerCase()
                                        }}<template v-if="line.multiplier > 1">
                                            × {{ line.multiplier }}</template
                                        ><template v-if="line.tax">
                                            + tax
                                            {{ currency(line.tax) }}</template
                                        ></span
                                    >
                                </td>
                                <td class="w-20 py-1.5">
                                    <input
                                        v-model.number="
                                            form.addons[index].quantity
                                        "
                                        type="number"
                                        min="1"
                                        max="999"
                                        :aria-label="`Quantity of ${line.name}`"
                                        class="w-16 rounded-md border border-[#e3e3e0] bg-white px-2 py-1 text-sm dark:border-[#3E3E3A] dark:bg-[#0a0a0a]"
                                    />
                                </td>
                                <td class="py-1.5 text-right">
                                    {{ currency(line.total) }}
                                </td>
                                <td class="w-8 py-1.5 text-right">
                                    <button
                                        type="button"
                                        class="text-[#706f6c] hover:text-red-600"
                                        :aria-label="`Remove ${line.name}`"
                                        @click="removeAddon(line.addon_id)"
                                    >
                                        ✕
                                    </button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                    <p
                        v-if="form.errors.addons"
                        class="mt-1 text-xs text-[#f53003]"
                    >
                        {{ form.errors.addons }}
                    </p>
                </div>

                <h3
                    class="mb-2 text-base font-semibold text-[#1b1b18] dark:text-[#EDEDEC]"
                >
                    Bill Summary
                </h3>

                <div
                    class="flex flex-col gap-2 border-t border-[#e3e3e0] pt-3 text-sm dark:border-[#3E3E3A]"
                >
                    <div class="flex items-center justify-between">
                        <span class="text-[#706f6c] dark:text-[#A1A09A]"
                            >Room Charges</span
                        >
                        <span class="text-[#1b1b18] dark:text-[#EDEDEC]">{{
                            currency(roomTotal)
                        }}</span>
                    </div>
                    <div class="flex items-center justify-between">
                        <span class="text-[#706f6c] dark:text-[#A1A09A]"
                            >Addons (incl. tax)</span
                        >
                        <span class="text-[#1b1b18] dark:text-[#EDEDEC]">{{
                            currency(addonsTotal)
                        }}</span>
                    </div>
                    <div class="flex items-center justify-between">
                        <span class="text-[#706f6c] dark:text-[#A1A09A]"
                            >Discount</span
                        >
                        <input
                            v-model.number="form.discount"
                            type="number"
                            min="0"
                            step="0.01"
                            class="block w-28 rounded-md border border-[#e3e3e0] bg-white px-2 py-1 text-right text-sm text-[#1b1b18] focus:border-[#f53003] focus:ring-1 focus:ring-[#f53003] focus:outline-none dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]"
                        />
                    </div>
                    <div
                        class="flex items-center justify-between border-t border-[#e3e3e0] pt-2 dark:border-[#3E3E3A]"
                    >
                        <span class="text-[#1b1b18] dark:text-[#EDEDEC]"
                            >Sub Total</span
                        >
                        <span class="text-[#1b1b18] dark:text-[#EDEDEC]">{{
                            currency(subTotal)
                        }}</span>
                    </div>
                    <div
                        v-for="item in taxBreakup"
                        :key="item.label"
                        class="flex items-center justify-between"
                    >
                        <span class="text-[#706f6c] dark:text-[#A1A09A]">{{
                            item.label
                        }}</span>
                        <span class="text-[#1b1b18] dark:text-[#EDEDEC]">{{
                            currency(item.amount)
                        }}</span>
                    </div>
                    <div
                        v-if="!taxBreakup.length"
                        class="flex items-center justify-between"
                    >
                        <span class="text-[#706f6c] dark:text-[#A1A09A]"
                            >Taxes</span
                        >
                        <span class="text-[#1b1b18] dark:text-[#EDEDEC]">{{
                            currency(taxes)
                        }}</span>
                    </div>
                    <div class="flex items-center justify-between">
                        <span class="text-[#706f6c] dark:text-[#A1A09A]"
                            >Payments</span
                        >
                        <span class="text-[#1b1b18] dark:text-[#EDEDEC]">{{
                            currency(totalPaid)
                        }}</span>
                    </div>
                    <div
                        v-if="netPayableByCompany !== null"
                        class="flex items-center justify-between border-t border-[#e3e3e0] pt-2 font-semibold dark:border-[#3E3E3A]"
                        data-test="net-payable-company"
                    >
                        <span class="text-[#1b1b18] dark:text-[#EDEDEC]"
                            >Net Payable by Company</span
                        >
                        <span class="text-[#1b1b18] dark:text-[#EDEDEC]">{{
                            currency(netPayableByCompany)
                        }}</span>
                    </div>
                    <div
                        class="flex items-center justify-between border-t border-[#e3e3e0] pt-2 font-semibold dark:border-[#3E3E3A]"
                    >
                        <span class="text-[#1b1b18] dark:text-[#EDEDEC]"
                            >Net Payable by Guest</span
                        >
                        <span class="text-[#1b1b18] dark:text-[#EDEDEC]">{{
                            currency(netPayableByGuest)
                        }}</span>
                    </div>
                    <div
                        class="flex items-center justify-between border-t border-[#e3e3e0] pt-2 text-base font-bold dark:border-[#3E3E3A]"
                    >
                        <span class="text-[#1b1b18] dark:text-[#EDEDEC]"
                            >Net Payable at Hotel</span
                        >
                        <span class="text-[#1b1b18] dark:text-[#EDEDEC]">{{
                            currency(Math.max(0, netPayableAtHotel))
                        }}</span>
                    </div>
                    <div
                        v-if="outstanding < 0"
                        class="flex items-center justify-between rounded-md bg-emerald-50 px-2 py-1.5 font-semibold text-emerald-800 dark:bg-emerald-950/40 dark:text-emerald-300"
                        data-test="credit-balance"
                    >
                        <span>Credit balance (refund due)</span>
                        <span>{{ currency(-outstanding) }}</span>
                    </div>
                    <p
                        v-if="cancellationChargePreview > 0"
                        class="text-xs text-amber-700 dark:text-amber-300"
                    >
                        Cancellation policy: {{ changePolicyTier?.percent }}% of
                        the {{ currency(changeAmountTakenOff) }} taken off ({{
                            changeSettlement?.days_before
                        }}
                        day(s) before check-in) =
                        {{ currency(cancellationChargePreview) }}. You can apply
                        or waive it on Save.
                    </p>
                </div>
            </div>

            <div
                v-if="hasReleaseReminder"
                class="mt-6 border-t border-[#e3e3e0] pt-4 dark:border-[#3E3E3A]"
            >
                <h3
                    class="mb-3 text-base font-semibold text-[#1b1b18] dark:text-[#EDEDEC]"
                >
                    Reminder
                </h3>

                <p
                    v-for="message in holdErrors"
                    :key="message"
                    class="mb-2 text-sm text-[#f53003] dark:text-[#FF4433]"
                >
                    {{ message }}
                </p>

                <label
                    class="mb-4 flex w-64 flex-col gap-1 text-sm text-[#706f6c] dark:text-[#A1A09A]"
                >
                    {{
                        isEnquiry
                            ? 'Enquiry release Date & Time'
                            : 'Hold Release Date & Time'
                    }}
                    <input
                        v-model="form.hold_release_at"
                        type="datetime-local"
                        class="block rounded-md border border-[#e3e3e0] bg-white px-3 py-2 text-sm text-[#1b1b18] focus:border-[#f53003] focus:ring-1 focus:ring-[#f53003] focus:outline-none dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]"
                    />
                </label>

                <div class="flex flex-col gap-2">
                    <div
                        v-for="(reminder, index) in form.hold_reminders"
                        :key="index"
                        class="flex flex-wrap items-end gap-3"
                    >
                        <label
                            class="flex flex-col gap-1 text-sm text-[#706f6c] dark:text-[#A1A09A]"
                        >
                            <span v-if="index === 0">Reminder</span>
                            <select
                                v-model="reminder.channel"
                                class="block w-36 rounded-md border border-[#e3e3e0] bg-white px-3 py-2 text-sm text-[#1b1b18] focus:border-[#f53003] focus:ring-1 focus:ring-[#f53003] focus:outline-none dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]"
                            >
                                <option
                                    v-for="channel in reminderChannels"
                                    :key="channel.value"
                                    :value="channel.value"
                                >
                                    {{ channel.label }}
                                </option>
                            </select>
                        </label>
                        <label
                            class="flex flex-col gap-1 text-sm text-[#706f6c] dark:text-[#A1A09A]"
                        >
                            <span v-if="index === 0">Days*</span>
                            <input
                                v-model.number="reminder.days"
                                type="number"
                                min="0"
                                max="365"
                                class="block w-20 rounded-md border border-[#e3e3e0] bg-white px-3 py-2 text-sm text-[#1b1b18] focus:border-[#f53003] focus:ring-1 focus:ring-[#f53003] focus:outline-none dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]"
                            />
                        </label>
                        <select
                            v-model="reminder.relative_to"
                            class="block w-48 rounded-md border border-[#e3e3e0] bg-white px-3 py-2 text-sm text-[#1b1b18] focus:border-[#f53003] focus:ring-1 focus:ring-[#f53003] focus:outline-none dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]"
                        >
                            <option value="hold_release_date">
                                {{
                                    isEnquiry
                                        ? 'Enquiry Release date'
                                        : 'Hold release date'
                                }}
                            </option>
                            <option value="check_in_date">Check-in date</option>
                        </select>
                        <button
                            v-if="form.hold_reminders.length > 1"
                            type="button"
                            title="Remove reminder"
                            class="pb-2 text-[#706f6c] hover:text-[#f53003] dark:text-[#A1A09A] dark:hover:text-[#FF4433]"
                            @click="removeHoldReminder(index)"
                        >
                            ✕
                        </button>
                        <button
                            v-if="index === form.hold_reminders.length - 1"
                            type="button"
                            title="Add reminder"
                            class="mb-1.5 flex h-6 w-6 items-center justify-center rounded-full border border-[#f53003] text-sm leading-none text-[#f53003] hover:bg-[#fff2f2] dark:border-[#FF4433] dark:text-[#FF4433]"
                            @click="addHoldReminder"
                        >
                            +
                        </button>
                    </div>
                    <p
                        v-if="!reminderChannels.length"
                        class="text-sm text-[#706f6c] dark:text-[#A1A09A]"
                    >
                        No notification channel is switched on. Set one up in
                        Application Settings → Notification Settings to add
                        reminders.
                    </p>
                    <p
                        v-if="droppedReminderCount"
                        class="text-sm text-amber-700 dark:text-amber-400"
                    >
                        {{ droppedReminderCount }} saved reminder(s) used a
                        channel that is now switched off and will be removed
                        when you save.
                    </p>
                    <button
                        v-if="
                            !form.hold_reminders.length &&
                            reminderChannels.length
                        "
                        type="button"
                        class="w-fit text-sm font-medium text-[#1b1b18] underline underline-offset-4 dark:text-[#EDEDEC]"
                        @click="addHoldReminder"
                    >
                        Add reminder
                    </button>
                </div>

                <p
                    v-if="isEnquiry"
                    class="mt-3 text-sm text-[#706f6c] dark:text-[#A1A09A]"
                >
                    An enquiry doesn't take any room. After the release date
                    &amp; time it becomes an Expired Quote.
                </p>
                <label
                    v-else
                    class="mt-3 flex items-center gap-2 text-sm text-[#1b1b18] dark:text-[#EDEDEC]"
                >
                    <input
                        v-model="form.hold_auto_release"
                        type="checkbox"
                        class="h-4 w-4 rounded border-[#e3e3e0] text-[#f53003] focus:ring-[#f53003] dark:border-[#3E3E3A]"
                    />
                    Auto release the room(s)
                    <span
                        title="When the hold release time passes, the booking is cancelled and its rooms become available again."
                        class="cursor-help text-sm text-[#706f6c] dark:text-[#A1A09A]"
                        >ⓘ</span
                    >
                </label>
            </div>

            <div
                class="mt-6 border-t border-[#e3e3e0] pt-4 dark:border-[#3E3E3A]"
            >
                <h3
                    class="mb-3 text-base font-semibold text-[#1b1b18] dark:text-[#EDEDEC]"
                >
                    Guest Details*
                </h3>
                <p
                    v-if="form.errors.guest_ids"
                    class="mb-2 text-sm text-[#f53003] dark:text-[#FF4433]"
                    data-test="guest-ids-error"
                >
                    {{ form.errors.guest_ids }}
                </p>

                <label
                    class="mb-2 flex flex-col gap-1 text-sm text-[#706f6c] dark:text-[#A1A09A]"
                >
                    Add Guest[s]
                    <GuestSearchCombobox
                        :property="property"
                        :exclude-ids="guests.map((guest) => guest.id)"
                        @select="onGuestSelected"
                        @add-new="openAddGuestModal"
                    />
                </label>

                <p
                    v-if="guests.length === 0"
                    class="text-sm text-[#706f6c] dark:text-[#A1A09A]"
                >
                    No guests added yet.
                </p>

                <div
                    v-for="guest in guests"
                    :key="guest.id"
                    class="mt-2 mb-2 flex items-center justify-between rounded-lg border border-[#e3e3e0] px-4 py-2 dark:border-[#3E3E3A]"
                >
                    <div>
                        <p
                            class="text-sm font-semibold text-[#1b1b18] dark:text-[#EDEDEC]"
                        >
                            {{ guest.title }} {{ guest.first_name }}
                            {{ guest.last_name }}
                        </p>
                        <p class="text-xs text-[#706f6c] dark:text-[#A1A09A]">
                            {{ guest.phone_country_code }} {{ guest.phone }}
                        </p>
                    </div>
                    <div class="flex items-center gap-3">
                        <button
                            type="button"
                            class="text-sm text-[#706f6c] hover:text-[#1b1b18] dark:text-[#A1A09A] dark:hover:text-[#EDEDEC]"
                            @click="openEditGuestModal(guest)"
                        >
                            Edit
                        </button>
                        <button
                            type="button"
                            class="text-sm text-[#706f6c] hover:text-[#f53003] dark:text-[#A1A09A] dark:hover:text-[#FF4433]"
                            @click="removeGuest(guest)"
                        >
                            Remove
                        </button>
                    </div>
                </div>
            </div>

            <div
                class="mt-6 border-t border-[#e3e3e0] pt-4 dark:border-[#3E3E3A]"
            >
                <h3
                    class="mb-4 flex items-center gap-1.5 text-base font-semibold text-[#1b1b18] dark:text-[#EDEDEC]"
                >
                    Billing Preferences
                    <span
                        title="Choose who the room charges and extras get billed to."
                        class="cursor-help text-sm text-[#706f6c] dark:text-[#A1A09A]"
                        >ⓘ</span
                    >
                </h3>

                <div class="grid grid-cols-1 gap-6 sm:grid-cols-2">
                    <div class="flex flex-col gap-2">
                        <label
                            class="flex flex-col gap-1 text-sm text-[#706f6c] dark:text-[#A1A09A]"
                        >
                            Room Bill
                            <select
                                v-model="form.room_bill_to"
                                class="block rounded-md border border-[#e3e3e0] bg-white px-3 py-2 text-sm text-[#1b1b18] focus:border-[#f53003] focus:ring-1 focus:ring-[#f53003] focus:outline-none dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]"
                            >
                                <option
                                    v-for="option in billToOptions"
                                    :key="option.value"
                                    :value="option.value"
                                >
                                    {{ option.label }}
                                </option>
                            </select>
                        </label>
                        <label
                            class="flex items-center gap-1.5 text-sm text-[#1b1b18] dark:text-[#EDEDEC]"
                        >
                            <input
                                v-model="form.bill_room_to_guest_company"
                                type="checkbox"
                                class="h-4 w-4 rounded border-[#e3e3e0] text-[#f53003] focus:ring-[#f53003] dark:border-[#3E3E3A]"
                                @change="
                                    validateBillToGuestCompany(
                                        'bill_room_to_guest_company',
                                    )
                                "
                            />
                            Bill To Guest Company
                            <span
                                title="Bill the room charges to the guest's linked company instead of the guest."
                                class="cursor-help text-[#706f6c] dark:text-[#A1A09A]"
                                >ⓘ</span
                            >
                        </label>
                        <label
                            class="flex items-center gap-1.5 text-sm text-[#1b1b18] dark:text-[#EDEDEC]"
                        >
                            <input
                                v-model="form.no_post"
                                type="checkbox"
                                class="h-4 w-4 rounded border-[#e3e3e0] text-[#f53003] focus:ring-[#f53003] dark:border-[#3E3E3A]"
                            />
                            No Post
                            <span
                                title="Do not post room charges to the folio automatically."
                                class="cursor-help text-[#706f6c] dark:text-[#A1A09A]"
                                >ⓘ</span
                            >
                        </label>
                    </div>
                    <div class="flex flex-col gap-2">
                        <label
                            class="flex flex-col gap-1 text-sm text-[#706f6c] dark:text-[#A1A09A]"
                        >
                            Extras
                            <select
                                v-model="form.extras_bill_to"
                                class="block rounded-md border border-[#e3e3e0] bg-white px-3 py-2 text-sm text-[#1b1b18] focus:border-[#f53003] focus:ring-1 focus:ring-[#f53003] focus:outline-none dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]"
                            >
                                <option
                                    v-for="option in billToOptions"
                                    :key="option.value"
                                    :value="option.value"
                                >
                                    {{ option.label }}
                                </option>
                            </select>
                        </label>
                        <label
                            class="flex items-center gap-1.5 text-sm text-[#1b1b18] dark:text-[#EDEDEC]"
                        >
                            <input
                                v-model="form.bill_extras_to_guest_company"
                                type="checkbox"
                                class="h-4 w-4 rounded border-[#e3e3e0] text-[#f53003] focus:ring-[#f53003] dark:border-[#3E3E3A]"
                                @change="
                                    validateBillToGuestCompany(
                                        'bill_extras_to_guest_company',
                                    )
                                "
                            />
                            Bill To Guest Company
                        </label>
                    </div>
                </div>
                <!-- Who pays: the guest's company as a City Ledger account, billed at checkout. -->
                <p
                    v-if="
                        form.bill_room_to_guest_company ||
                        form.bill_extras_to_guest_company
                    "
                    class="mt-3 text-sm"
                    :class="
                        billedCompanyAccount
                            ? 'text-emerald-700 dark:text-emerald-400'
                            : 'text-amber-700 dark:text-amber-400'
                    "
                    data-test="billed-company-account"
                >
                    <template v-if="billedCompanyAccount">
                        Billed to {{ billedCompanyAccount.name }} (City Ledger)
                        at checkout<template
                            v-if="billedCompanyAccount.available_credit != null"
                            >, credit left
                            {{
                                Number(
                                    billedCompanyAccount.available_credit,
                                ).toFixed(2)
                            }}</template
                        >.
                    </template>
                    <template v-else>
                        The guest's company isn't linked to a City Ledger
                        account, so the bill stays with the guest. Link it under
                        the guest's Add/Update Company.
                    </template>
                </p>
            </div>

            <!-- Payments, payment links and folios work straight away; the form's own changes still need Save Changes. -->
            <div
                v-if="billing"
                class="-mx-4 mt-6 border-t border-[#e3e3e0] dark:border-[#3E3E3A]"
            >
                <BookingBillingPanel v-bind="billing" :manage-stays="false" />
            </div>

            <div
                class="mt-6 border-t border-[#e3e3e0] pt-4 dark:border-[#3E3E3A]"
            >
                <div class="flex items-center justify-between">
                    <h3
                        class="text-base font-semibold text-[#1b1b18] dark:text-[#EDEDEC]"
                    >
                        Attachments
                    </h3>
                    <button
                        type="button"
                        class="rounded-md border border-[#e3e3e0] px-3 py-1 text-xs font-medium text-[#1b1b18] uppercase hover:bg-[#f5f5f4] dark:border-[#3E3E3A] dark:text-[#EDEDEC] dark:hover:bg-[#1D1D1B]"
                        @click="openUploadDocuments"
                    >
                        Add
                    </button>
                </div>

                <p
                    v-for="message in attachmentErrors"
                    :key="message"
                    class="mt-2 text-sm text-[#f53003] dark:text-[#FF4433]"
                >
                    {{ message }}
                </p>

                <div
                    v-if="savedAttachments.length"
                    class="mt-3 flex flex-col divide-y divide-[#e3e3e0] rounded-md border border-[#e3e3e0] dark:divide-[#3E3E3A] dark:border-[#3E3E3A]"
                >
                    <div
                        v-for="attachment in savedAttachments"
                        :key="attachment.id"
                        class="flex items-center justify-between gap-3 px-3 py-2 text-sm"
                    >
                        <div class="min-w-0">
                            <p
                                class="font-medium text-[#1b1b18] dark:text-[#EDEDEC]"
                            >
                                {{ attachment.document_type
                                }}<template v-if="attachment.document_number">
                                    · {{ attachment.document_number }}</template
                                >
                            </p>
                            <p
                                class="truncate text-xs text-[#706f6c] dark:text-[#A1A09A]"
                            >
                                {{ attachment.original_name
                                }}<template v-if="attachment.expiry_date">
                                    · Expires
                                    {{ attachment.expiry_date }}</template
                                >
                            </p>
                        </div>
                        <div class="flex shrink-0 items-center gap-3">
                            <button
                                type="button"
                                class="text-sm font-medium text-[#1b1b18] underline underline-offset-4 dark:text-[#EDEDEC]"
                                @click="viewSavedAttachment(attachment)"
                            >
                                View
                            </button>
                            <button
                                type="button"
                                class="text-sm text-[#706f6c] hover:text-[#f53003] dark:text-[#A1A09A] dark:hover:text-[#FF4433]"
                                @click="removeSavedAttachment(attachment)"
                            >
                                Remove
                            </button>
                        </div>
                    </div>
                </div>

                <div
                    v-if="form.attachments.length"
                    class="mt-3 flex flex-col divide-y divide-[#e3e3e0] rounded-md border border-[#e3e3e0] dark:divide-[#3E3E3A] dark:border-[#3E3E3A]"
                >
                    <div
                        v-for="(attachment, index) in form.attachments"
                        :key="index"
                        class="flex items-center justify-between gap-3 px-3 py-2 text-sm"
                    >
                        <div class="min-w-0">
                            <p
                                class="font-medium text-[#1b1b18] dark:text-[#EDEDEC]"
                            >
                                {{ attachment.document_type
                                }}<template v-if="attachment.document_number">
                                    · {{ attachment.document_number }}</template
                                >
                            </p>
                            <p
                                class="truncate text-xs text-[#706f6c] dark:text-[#A1A09A]"
                            >
                                {{ attachment.file?.name
                                }}<template v-if="attachment.expiry_date">
                                    · Expires
                                    {{ attachment.expiry_date }}</template
                                >
                            </p>
                        </div>
                        <div class="flex shrink-0 items-center gap-3">
                            <button
                                type="button"
                                class="text-sm font-medium text-[#1b1b18] underline underline-offset-4 dark:text-[#EDEDEC]"
                                @click="viewAttachment(attachment)"
                            >
                                View
                            </button>
                            <button
                                type="button"
                                class="text-sm text-[#706f6c] hover:text-[#f53003] dark:text-[#A1A09A] dark:hover:text-[#FF4433]"
                                @click="removeAttachment(index)"
                            >
                                Remove
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <div
                class="mt-6 border-t border-[#e3e3e0] pt-4 dark:border-[#3E3E3A]"
            >
                <h3
                    class="mb-3 text-base font-semibold text-[#1b1b18] dark:text-[#EDEDEC]"
                >
                    Others
                </h3>

                <div
                    v-for="category in inclusionCategories"
                    :key="category.id"
                    class="mb-4"
                >
                    <div class="mb-1 flex items-center gap-1.5">
                        <p
                            class="text-sm font-semibold text-[#1b1b18] dark:text-[#EDEDEC]"
                        >
                            Inclusions
                        </p>
                        <button
                            v-if="!editingInclusions[category.id]"
                            type="button"
                            title="Edit inclusions"
                            class="text-[#706f6c] hover:text-[#f53003] dark:text-[#A1A09A] dark:hover:text-[#FF4433]"
                            @click="startEditingInclusions(category)"
                        >
                            ✏️
                        </button>
                    </div>

                    <template v-if="editingInclusions[category.id]">
                        <div
                            class="rounded-md border border-[#e3e3e0] dark:border-[#3E3E3A]"
                        >
                            <RichTextEditor
                                v-model="form.inclusions[category.id]"
                            />
                        </div>
                        <button
                            type="button"
                            class="mt-1 text-sm font-medium text-[#f53003] dark:text-[#FF4433]"
                            @click="stopEditingInclusions(category.id)"
                        >
                            Done
                        </button>
                    </template>
                    <template v-else>
                        <div
                            class="prose prose-sm max-w-none overflow-hidden text-sm text-[#1b1b18] dark:text-[#EDEDEC]"
                            :class="
                                inclusionsExpanded[category.id]
                                    ? ''
                                    : 'max-h-32'
                            "
                            v-html="inclusionsFor(category)"
                        ></div>
                        <button
                            type="button"
                            class="mt-1 text-sm font-medium text-[#f53003] dark:text-[#FF4433]"
                            @click="toggleInclusions(category.id)"
                        >
                            {{
                                inclusionsExpanded[category.id]
                                    ? '...View less'
                                    : '...View more'
                            }}
                        </button>
                    </template>
                </div>

                <label
                    class="mb-4 flex flex-col gap-1 text-sm text-[#706f6c] dark:text-[#A1A09A]"
                >
                    Special Instructions
                    <span class="italic">(shared with guest)</span>
                    <textarea
                        v-model="form.special_instructions"
                        rows="2"
                        class="block rounded-md border border-[#e3e3e0] bg-white px-3 py-2 text-sm text-[#1b1b18] focus:border-[#f53003] focus:ring-1 focus:ring-[#f53003] focus:outline-none dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]"
                    ></textarea>
                </label>

                <label
                    class="mb-4 flex flex-col gap-1 text-sm text-[#706f6c] dark:text-[#A1A09A]"
                >
                    Internal Notes
                    <textarea
                        v-model="form.internal_notes"
                        rows="2"
                        class="block rounded-md border border-[#e3e3e0] bg-white px-3 py-2 text-sm text-[#1b1b18] focus:border-[#f53003] focus:ring-1 focus:ring-[#f53003] focus:outline-none dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]"
                    ></textarea>
                </label>

                <div
                    class="border-t border-dashed border-[#e3e3e0] pt-3 dark:border-[#3E3E3A]"
                >
                    <div class="flex items-center gap-2">
                        <span
                            class="text-sm font-medium text-[#1b1b18] dark:text-[#EDEDEC]"
                            >Copy To:</span
                        >
                        <button
                            type="button"
                            title="Add external recipient"
                            class="flex h-6 w-6 items-center justify-center rounded-full border border-[#f53003] text-sm text-[#f53003] hover:bg-[#f53003]/10 dark:border-[#FF4433] dark:text-[#FF4433]"
                            @click="openAddRecipientModal"
                        >
                            +
                        </button>
                    </div>

                    <div
                        v-for="(recipient, index) in form.copy_to"
                        :key="index"
                        class="mt-2 flex items-center justify-between rounded-lg border border-[#e3e3e0] px-3 py-2 dark:border-[#3E3E3A]"
                    >
                        <div>
                            <p
                                class="text-sm font-medium text-[#1b1b18] dark:text-[#EDEDEC]"
                            >
                                {{ recipient.name }}
                            </p>
                            <p
                                class="text-xs text-[#706f6c] dark:text-[#A1A09A]"
                            >
                                <span v-if="recipient.phone"
                                    >{{ recipient.phone_country_code }}
                                    {{ recipient.phone }}</span
                                >
                                <span v-if="recipient.phone && recipient.email">
                                    ·
                                </span>
                                <span v-if="recipient.email">{{
                                    recipient.email
                                }}</span>
                            </p>
                        </div>
                        <div class="flex items-center gap-3">
                            <button
                                type="button"
                                class="text-sm text-[#706f6c] hover:text-[#1b1b18] dark:text-[#A1A09A] dark:hover:text-[#EDEDEC]"
                                @click="openEditRecipientModal(index)"
                            >
                                Edit
                            </button>
                            <button
                                type="button"
                                class="text-sm text-[#706f6c] hover:text-[#f53003] dark:text-[#A1A09A] dark:hover:text-[#FF4433]"
                                @click="removeRecipient(index)"
                            >
                                Remove
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div
        class="sticky bottom-0 mt-4 flex w-full flex-wrap items-center justify-center gap-3 rounded-lg border border-[#e3e3e0] bg-white px-6 py-4 dark:border-[#3E3E3A] dark:bg-[#161615]"
    >
        <button
            type="button"
            :disabled="form.processing"
            class="inline-flex items-center gap-2 rounded-md border border-black bg-[#f53003] px-4 py-2 text-sm font-semibold text-white hover:bg-[#d92a02] disabled:opacity-50"
            @click="submit(isEditing || isEnquiry ? null : 'edit')"
        >
            <svg
                v-if="form.processing && savingAction === 'primary'"
                class="h-4 w-4 animate-spin"
                xmlns="http://www.w3.org/2000/svg"
                fill="none"
                viewBox="0 0 24 24"
                aria-hidden="true"
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
                    d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"
                ></path>
            </svg>
            {{
                form.processing && savingAction === 'primary'
                    ? 'Saving...'
                    : isEditing
                      ? 'Save Changes'
                      : isEnquiry
                        ? 'Save Enquiry'
                        : 'Save & Add Payment'
            }}
        </button>
        <button
            v-if="!isEditing"
            type="button"
            :disabled="form.processing"
            class="inline-flex items-center gap-2 rounded-md border border-black bg-[#1b1b18] px-4 py-2 text-sm font-semibold text-white hover:bg-black disabled:opacity-50 dark:border-[#eeeeec] dark:bg-[#eeeeec] dark:text-[#1C1C1A]"
            @click="submit(null, 'secondary')"
        >
            <svg
                v-if="form.processing && savingAction === 'secondary'"
                class="h-4 w-4 animate-spin"
                xmlns="http://www.w3.org/2000/svg"
                fill="none"
                viewBox="0 0 24 24"
                aria-hidden="true"
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
                    d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"
                ></path>
            </svg>
            {{
                form.processing && savingAction === 'secondary'
                    ? 'Saving...'
                    : isEnquiry
                      ? 'Save & Email Quote'
                      : isHold
                        ? 'Hold & Email'
                        : 'Confirm & Email'
            }}
        </button>
        <Link
            :href="
                isEditing
                    ? admin.bookings.show({
                          property: property.id,
                          booking: editing!.id,
                      }).url
                    : admin.dashboard({ query: { property: property.id } }).url
            "
            class="rounded-md border border-[#e3e3e0] px-4 py-2 text-sm font-medium text-[#1b1b18] dark:border-[#3E3E3A] dark:text-[#EDEDEC]"
        >
            Cancel
        </Link>
        <BookingPrintMenu
            v-if="isEditing"
            :property-id="property.id"
            :booking-id="editing!.id"
            button-class="cursor-pointer rounded-md border border-[#e3e3e0] px-4 py-2 text-sm font-medium text-[#1b1b18] uppercase dark:border-[#3E3E3A] dark:text-[#EDEDEC]"
        />
        <button
            type="button"
            class="rounded-md border border-[#e3e3e0] px-4 py-2 text-sm font-medium text-[#1b1b18] dark:border-[#3E3E3A] dark:text-[#EDEDEC]"
        >
            More ▾
        </button>
    </div>

    <Modal
        :show="settlement.open"
        title="Booking change: bill lowered"
        @close="settlement.open = false"
    >
        <form
            class="flex flex-col gap-4 text-sm text-[#1b1b18] dark:text-[#EDEDEC]"
            data-test="change-settlement"
            @submit.prevent="confirmSettlement"
        >
            <div
                class="rounded-md border border-[#e3e3e0] p-3 dark:border-[#3E3E3A]"
            >
                <div class="flex justify-between">
                    <span class="text-[#706f6c] dark:text-[#A1A09A]"
                        >Bill before</span
                    >
                    <span>{{
                        currency(changeSettlement?.saved_bill ?? 0)
                    }}</span>
                </div>
                <div class="flex justify-between">
                    <span class="text-[#706f6c] dark:text-[#A1A09A]"
                        >Bill after the change</span
                    >
                    <span>{{ currency(grandTotal) }}</span>
                </div>
                <div class="flex justify-between font-semibold">
                    <span>Taken off</span>
                    <span>{{ currency(changeAmountTakenOff) }}</span>
                </div>
            </div>

            <div v-if="cancellationChargePreview > 0">
                <h3 class="mb-2 font-semibold">Cancellation policy</h3>
                <table class="mb-2 w-full text-xs">
                    <thead>
                        <tr
                            class="text-left text-[#706f6c] dark:text-[#A1A09A]"
                        >
                            <th class="py-1 font-medium">When</th>
                            <th class="py-1 font-medium">Charge</th>
                            <th class="py-1 font-medium">Refund</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="row in changeSettlement?.summary ?? []"
                            :key="row.window"
                            class="border-t border-[#e3e3e0] dark:border-[#3E3E3A]"
                        >
                            <td class="py-1">{{ row.window }}</td>
                            <td class="py-1">{{ row.penalty }}</td>
                            <td class="py-1">{{ row.refund }}</td>
                        </tr>
                    </tbody>
                </table>
                <label
                    class="flex items-start gap-2 rounded-md bg-amber-50 px-3 py-2 text-amber-900 dark:bg-amber-950/40 dark:text-amber-200"
                >
                    <input
                        v-model="settlement.apply_cancellation_charge"
                        type="checkbox"
                        class="mt-0.5"
                        data-test="apply-cancellation-charge"
                    />
                    <span>
                        Apply cancellation charge of
                        <strong>{{
                            currency(cancellationChargePreview)
                        }}</strong
                        >: {{ changePolicyTier?.percent }}% of
                        {{ currency(changeAmountTakenOff) }}, changed
                        {{ changeSettlement?.days_before }} day(s) before
                        check-in.
                    </span>
                </label>
            </div>

            <div v-if="creditAfterChange > 0">
                <h3 class="mb-1 font-semibold">
                    Credit left: {{ currency(creditAfterChange) }}
                </h3>
                <p class="mb-2 text-xs text-[#706f6c] dark:text-[#A1A09A]">
                    The guest has paid more than the new bill. Choose what
                    happens to it.
                </p>
                <div class="flex flex-col gap-2">
                    <label class="flex items-center gap-2">
                        <input
                            v-model="settlement.credit_action"
                            type="radio"
                            value="refund"
                            data-test="credit-refund"
                        />
                        Refund now
                    </label>
                    <div
                        v-if="settlement.credit_action === 'refund'"
                        class="ml-6 grid gap-2 sm:grid-cols-2"
                    >
                        <label
                            class="flex flex-col gap-1 text-xs text-[#706f6c] dark:text-[#A1A09A]"
                        >
                            Refund mode
                            <select
                                v-model="settlement.refund_mode"
                                class="rounded-md border border-[#e3e3e0] bg-white px-2 py-1.5 text-sm text-[#1b1b18] dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]"
                            >
                                <option
                                    v-for="mode in changeSettlement?.refund_modes ??
                                    []"
                                    :key="mode.value"
                                    :value="mode.value"
                                >
                                    {{ mode.label }}
                                </option>
                            </select>
                        </label>
                        <label
                            class="flex flex-col gap-1 text-xs text-[#706f6c] dark:text-[#A1A09A]"
                        >
                            Reference (UTR, cheque no., gateway refund id)
                            <input
                                v-model="settlement.refund_reference"
                                type="text"
                                maxlength="255"
                                class="rounded-md border border-[#e3e3e0] bg-white px-2 py-1.5 text-sm text-[#1b1b18] dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]"
                            />
                        </label>
                        <label
                            class="flex flex-col gap-1 text-xs text-[#706f6c] sm:col-span-2 dark:text-[#A1A09A]"
                        >
                            Notes
                            <input
                                v-model="settlement.refund_notes"
                                type="text"
                                maxlength="1000"
                                class="rounded-md border border-[#e3e3e0] bg-white px-2 py-1.5 text-sm text-[#1b1b18] dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]"
                            />
                        </label>
                    </div>
                    <label
                        class="flex items-center gap-2"
                        :class="{
                            'opacity-50': !changeSettlement?.guest_ledger,
                        }"
                    >
                        <input
                            v-model="settlement.credit_action"
                            type="radio"
                            value="guest_ledger"
                            :disabled="!changeSettlement?.guest_ledger"
                            data-test="credit-guest-ledger"
                        />
                        <span>
                            Move to the guest's ledger, to use on future
                            bookings
                            <span
                                v-if="changeSettlement?.guest_ledger"
                                class="text-xs text-[#706f6c] dark:text-[#A1A09A]"
                            >
                                ({{ changeSettlement.guest_ledger.guest_name }},
                                balance now
                                {{
                                    currency(
                                        changeSettlement.guest_ledger.balance,
                                    )
                                }})
                            </span>
                            <span
                                v-else
                                class="text-xs text-[#706f6c] dark:text-[#A1A09A]"
                                >(add a guest to the booking first)</span
                            >
                        </span>
                    </label>
                    <label class="flex items-center gap-2">
                        <input
                            v-model="settlement.credit_action"
                            type="radio"
                            value="advance"
                            data-test="credit-advance"
                        />
                        Keep as an advance (untagged on the receipt, to use on
                        another booking)
                    </label>
                    <label class="flex items-center gap-2">
                        <input
                            v-model="settlement.credit_action"
                            type="radio"
                            value="transfer"
                            :disabled="!transferTargets.length"
                            data-test="credit-transfer"
                        />
                        Move to another booking
                    </label>
                    <select
                        v-if="settlement.credit_action === 'transfer'"
                        v-model="settlement.transfer_booking_id"
                        class="ml-6 rounded-md border border-[#e3e3e0] bg-white px-2 py-1.5 text-sm text-[#1b1b18] dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]"
                        required
                    >
                        <option :value="null" disabled>
                            Choose a booking…
                        </option>
                        <option
                            v-for="reservation in transferTargets"
                            :key="reservation.id"
                            :value="reservation.id"
                        >
                            {{ reservation.booking_ref_id }} ·
                            {{ reservation.guest_name }} ·
                            {{ reservation.check_in }} · owes
                            {{ currency(reservation.outstanding) }}
                        </option>
                    </select>
                    <label class="flex items-center gap-2">
                        <input
                            v-model="settlement.credit_action"
                            type="radio"
                            value="leave"
                            data-test="credit-leave"
                        />
                        Leave on the booking for now (shows under Refunds)
                    </label>
                </div>
            </div>

            <div class="flex justify-end gap-2">
                <button
                    type="button"
                    class="rounded-md border border-[#e3e3e0] px-4 py-2 text-sm font-medium dark:border-[#3E3E3A]"
                    @click="settlement.open = false"
                >
                    Back
                </button>
                <button
                    type="submit"
                    class="rounded-md bg-[#f53003] px-4 py-2 text-sm font-medium text-white hover:bg-[#d42a02]"
                    data-test="confirm-change-settlement"
                >
                    Save changes
                </button>
            </div>
        </form>
    </Modal>

    <div
        v-if="savedToast"
        class="fixed top-4 right-4 z-[120] flex max-w-md items-start gap-3 rounded-md px-4 py-3 text-sm font-medium text-white shadow-lg"
        :class="savedToastIsError ? 'bg-red-600' : 'bg-[#16a34a]'"
        :role="savedToastIsError ? 'alert' : 'status'"
    >
        <span>{{ savedToast }}</span>
        <button
            type="button"
            class="leading-none text-white/80 hover:text-white"
            aria-label="Dismiss"
            @click="savedToast = ''"
        >
            &times;
        </button>
    </div>

    <div
        v-if="showUploadDocuments"
        class="fixed inset-0 z-50 flex items-start justify-center bg-black/30 p-4 pt-20"
        @mousedown.self="showUploadDocuments = false"
    >
        <div
            class="w-full max-w-5xl overflow-hidden rounded-md border border-black bg-white shadow-2xl dark:border-[#3E3E3A] dark:bg-[#161615]"
        >
            <div class="bg-black px-6 py-3 text-lg font-semibold text-white">
                Upload Documents
            </div>

            <div class="overflow-x-auto">
                <table class="w-full min-w-[720px] text-sm">
                    <thead>
                        <tr
                            class="border-b border-[#e3e3e0] text-left text-xs font-medium text-[#706f6c] dark:border-[#3E3E3A] dark:text-[#A1A09A]"
                        >
                            <th class="px-6 py-2 font-medium">Document Type</th>
                            <th class="px-2 py-2 font-medium">Document No.</th>
                            <th class="px-2 py-2 font-medium">Expiry</th>
                            <th class="px-2 py-2 font-medium">Document</th>
                            <th class="w-8"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <template
                            v-for="(row, index) in uploadRows"
                            :key="index"
                        >
                            <tr>
                                <td class="px-6 pt-3">
                                    <select
                                        v-model="row.document_type"
                                        class="block w-full rounded border border-[#e3e3e0] bg-white px-3 py-1.5 text-sm text-[#1b1b18] dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]"
                                    >
                                        <option
                                            v-for="type in documentTypes"
                                            :key="type.name"
                                            :value="type.name"
                                        >
                                            {{ type.name }}
                                        </option>
                                    </select>
                                </td>
                                <td class="px-2 pt-3">
                                    <input
                                        v-model="row.document_number"
                                        type="text"
                                        :placeholder="
                                            documentTypeFor(row.document_type)
                                                ?.require_document_number
                                                ? 'Required'
                                                : ''
                                        "
                                        class="block w-full rounded border border-[#e3e3e0] bg-white px-3 py-1.5 text-sm text-[#1b1b18] dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]"
                                    />
                                </td>
                                <td class="px-2 pt-3">
                                    <div class="flex items-center gap-1">
                                        <input
                                            v-model="row.expiry_date"
                                            type="date"
                                            class="block w-full rounded border border-[#e3e3e0] bg-white px-3 py-1.5 text-sm text-[#1b1b18] dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]"
                                        />
                                        <button
                                            v-if="row.expiry_date"
                                            type="button"
                                            title="Clear expiry"
                                            class="text-[#706f6c] hover:text-[#1b1b18] dark:text-[#A1A09A]"
                                            @click="row.expiry_date = ''"
                                        >
                                            ⊗
                                        </button>
                                    </div>
                                </td>
                                <td class="px-2 pt-3">
                                    <label
                                        class="flex cursor-pointer items-center gap-1 text-xs font-semibold text-[#1a73e8] uppercase hover:underline"
                                    >
                                        ⭱ {{ row.file ? 'Change' : 'Upload' }}
                                        <input
                                            type="file"
                                            class="hidden"
                                            accept=".pdf,.jpg,.jpeg,.png,.webp,.heic,.doc,.docx"
                                            @change="
                                                onUploadFileChosen(row, $event)
                                            "
                                        />
                                    </label>
                                    <p
                                        v-if="row.file"
                                        class="mt-0.5 max-w-44 truncate text-xs text-[#706f6c] dark:text-[#A1A09A]"
                                    >
                                        {{ row.file.name }}
                                    </p>
                                </td>
                                <td class="pt-3 pr-4">
                                    <button
                                        v-if="uploadRows.length > 1"
                                        type="button"
                                        title="Remove row"
                                        class="text-[#706f6c] hover:text-[#f53003] dark:text-[#A1A09A]"
                                        @click="removeUploadRow(index)"
                                    >
                                        ✕
                                    </button>
                                </td>
                            </tr>
                            <tr v-if="uploadErrors[index]">
                                <td
                                    colspan="5"
                                    class="px-6 pt-1 text-xs text-[#f53003] dark:text-[#FF4433]"
                                >
                                    {{ uploadErrors[index] }}
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>
                <p
                    v-if="!documentTypes.length"
                    class="px-6 pt-3 text-sm text-[#706f6c] dark:text-[#A1A09A]"
                >
                    No document types are enabled. Turn some on in Booking
                    Settings → Document Settings.
                </p>
            </div>

            <div
                class="mt-16 flex items-center justify-between border-t border-[#e3e3e0] px-6 py-3 dark:border-[#3E3E3A]"
            >
                <div class="flex items-center gap-3">
                    <button
                        type="button"
                        class="rounded bg-[#f53003] px-4 py-1.5 text-sm font-semibold text-white uppercase hover:bg-[#d92a02]"
                        @click="saveUploadRows"
                    >
                        Save
                    </button>
                    <button
                        type="button"
                        class="text-sm text-[#1a73e8] hover:underline"
                        @click="showUploadDocuments = false"
                    >
                        Back to Folio details
                    </button>
                </div>
                <button
                    type="button"
                    class="text-xs font-semibold text-[#16a34a] uppercase hover:underline"
                    @click="addUploadRow"
                >
                    Add
                </button>
            </div>
        </div>
    </div>

    <DocumentViewerModal
        :document="viewedDocument"
        @close="closeDocumentViewer"
    />

    <Modal
        :show="pendingDateChange !== null"
        title="Change date?"
        @close="cancelDateChange"
    >
        <p class="text-sm text-[#706f6c] dark:text-[#A1A09A]">
            Changing the date will reset the room availability and rates. Do you
            want to continue?
        </p>
        <div class="mt-6 flex justify-end gap-3">
            <button
                type="button"
                class="rounded-md border border-[#e3e3e0] px-4 py-2 text-sm font-medium text-[#1b1b18] dark:border-[#3E3E3A] dark:text-[#EDEDEC]"
                @click="cancelDateChange"
            >
                No
            </button>
            <button
                type="button"
                class="rounded-md border border-black bg-[#f53003] px-4 py-2 text-sm font-semibold text-white hover:bg-[#d92a02]"
                @click="confirmDateChange"
            >
                Yes, change date
            </button>
        </div>
    </Modal>

    <GuestFormModal
        :show="guestModalOpen"
        :property="property"
        :countries="countries"
        :guest="editingGuest"
        @close="guestModalOpen = false"
        @saved="onGuestSaved"
    />

    <ExternalRecipientModal
        :show="recipientModalOpen"
        :countries="countries"
        :default-phone-country-code="defaultPhoneCountryCode()"
        :recipient="
            editingRecipientIndex === null
                ? null
                : form.copy_to[editingRecipientIndex]
        "
        @close="recipientModalOpen = false"
        @save="onRecipientSaved"
    />
</template>
