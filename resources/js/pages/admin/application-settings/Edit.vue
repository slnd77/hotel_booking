<script setup lang="ts">
import Modal from '@/components/Modal.vue';
import AdminLayout from '@/layouts/AdminLayout.vue';
import { useUrlTab } from '@/lib/useUrlTab';
import admin from '@/routes/admin';
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import { usePropertyTime } from '@/lib/usePropertyTime';

/** Times arrive in UTC and are shown in the property's timezone. */
const propertyTime = usePropertyTime();

defineOptions({ layout: AdminLayout });

type BlockReason = { id: number; name: string; display_order: number };
type Subtype = { value: string; label: string; active: boolean };
type ActivityLogEntry = {
    description: string;
    user: string | null;
    at: string | null;
};
type SmtpSettings = {
    host: string;
    port: number;
    encryption: 'tls' | 'ssl' | 'none';
    username: string;
    from_address: string;
    from_name: string;
    has_password: boolean;
};
type NotificationChannel = {
    key: string;
    label: string;
    supported: boolean;
    enabled: boolean;
    configured: boolean;
    smtp?: SmtpSettings;
};

const props = defineProps<{
    blockReasons: BlockReason[];
    reservation: {
        check_in: Record<string, boolean>;
        check_out: Record<string, boolean>;
        subtypes: Subtype[];
    };
    guest: {
        enable_tracker_number: boolean;
        tracker_prefix: string;
        tracker_start_number: number;
        mandatory: Record<string, boolean>;
        default_nationality: string;
    };
    dayPosting: { transaction_posting_time: string };
    smartChart: {
        days_to_show: number;
        min_days: number;
        max_days: number;
        auto_refresh_minutes: number;
        min_refresh_minutes: number;
        max_refresh_minutes: number;
    };
    notificationChannels: NotificationChannel[];
    guestEmails: { key: string; label: string; enabled: boolean }[];
    widgetProperties: { id: number; name: string }[];
    countries: string[];
    activityLogs: Record<string, ActivityLogEntry[]>;
}>();

const tabs = [
    {
        key: 'block-unblock',
        label: 'Block Unblock Config',
        section: 'block_unblock',
    },
    { key: 'reservation', label: 'Reservation', section: 'reservation' },
    { key: 'guest', label: 'Guest', section: 'guest' },
    { key: 'widget', label: 'Widget', section: 'widget' },
    { key: 'day-posting', label: 'Day Posting', section: 'day_posting' },
    {
        key: 'smart-chart',
        label: 'Smart Booking Chart',
        section: 'smart_chart',
    },
    {
        key: 'notifications',
        label: 'Notification Settings',
        section: 'notification',
    },
] as const;

type TabKey = (typeof tabs)[number]['key'];

const activeTab = useUrlTab<TabKey>(
    tabs.map((tab) => tab.key),
    'block-unblock',
);

const checkboxClass =
    'h-4 w-4 rounded border-[#e3e3e0] text-[#f53003] focus:ring-[#f53003] dark:border-[#3E3E3A]';
const inputClass =
    'block rounded-md border border-[#e3e3e0] bg-white px-3 py-2 text-sm text-[#1b1b18] focus:border-[#f53003] focus:ring-1 focus:ring-[#f53003] focus:outline-none dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]';

// ── Activity logs ──
const activityLogsSection = ref<string | null>(null);
const activityLogsTitle = computed(
    () =>
        tabs.find((tab) => tab.section === activityLogsSection.value)?.label ??
        '',
);

// ── Drag-and-drop ordering (Block reasons and Widget properties) ──
function moveItem<T>(list: T[], from: number, to: number): void {
    if (from === to || from < 0 || to < 0) {
        return;
    }

    const [item] = list.splice(from, 1);
    list.splice(to, 0, item);
}

// ── Block Unblock Config ──
const reasonRows = ref<BlockReason[]>([...props.blockReasons]);
const draggedReasonIndex = ref<number | null>(null);

watch(
    () => props.blockReasons,
    (reasons) => (reasonRows.value = [...reasons]),
);

function dropReason(targetIndex: number): void {
    if (
        draggedReasonIndex.value === null ||
        draggedReasonIndex.value === targetIndex
    ) {
        draggedReasonIndex.value = null;

        return;
    }

    moveItem(reasonRows.value, draggedReasonIndex.value, targetIndex);
    draggedReasonIndex.value = null;
    router.put(
        admin.applicationSettings.blockReasons.reorder().url,
        { ids: reasonRows.value.map((reason) => reason.id) },
        { preserveScroll: true },
    );
}

const reasonModalOpen = ref(false);
const editingReason = ref<BlockReason | null>(null);
const reasonForm = useForm({ name: '' });

function openReasonModal(reason: BlockReason | null): void {
    editingReason.value = reason;
    reasonForm.clearErrors();
    reasonForm.name = reason?.name ?? '';
    reasonModalOpen.value = true;
}

function saveReason(): void {
    const options = {
        preserveScroll: true,
        onSuccess: () => (reasonModalOpen.value = false),
    };

    if (editingReason.value) {
        reasonForm.put(
            admin.applicationSettings.blockReasons.update(
                editingReason.value.id,
            ).url,
            options,
        );
    } else {
        reasonForm.post(
            admin.applicationSettings.blockReasons.store().url,
            options,
        );
    }
}

// ── Reservation ──
const reservationTab = ref<'mandatory' | 'subtypes'>('mandatory');
const checkInFields = [
    { key: 'guest_nationality', label: 'Guest Nationality' },
    { key: 'guest_phone', label: 'Guest Phone' },
    { key: 'guest_email', label: 'Guest Email' },
    {
        key: 'alphanumeric_guest_name',
        label: 'Enable Alphanumeric value in Guest Name',
    },
    { key: 'guest_document', label: 'Guest Document Mandatory for check-in' },
    { key: 'guest_address', label: 'Guest Address' },
];
const checkOutFields = [
    { key: 'guest_nationality', label: 'Guest Nationality' },
    { key: 'guest_dob', label: 'Guest DOB' },
    { key: 'guest_purpose_of_visit', label: 'Guest Purpose Of Visit' },
    { key: 'guest_gender', label: 'Guest Gender' },
];

const reservationForm = useForm({
    check_in: { ...props.reservation.check_in },
    check_out: { ...props.reservation.check_out },
    subtypes: props.reservation.subtypes.map((subtype) => ({ ...subtype })),
});

function addSubtype(): void {
    reservationForm.subtypes.push({ value: '', label: '', active: true });
}

function removeSubtype(index: number): void {
    reservationForm.subtypes.splice(index, 1);
}

const subtypeErrors = computed(() =>
    Object.entries(reservationForm.errors as Record<string, string>)
        .filter(([key]) => key.startsWith('subtypes'))
        .map(([, message]) => message),
);

function saveReservation(): void {
    reservationForm.put(admin.applicationSettings.update('reservation').url, {
        preserveScroll: true,
    });
}

// ── Guest ──
const guestMandatoryFields = [
    { key: 'first_name', label: 'First Name' },
    { key: 'last_name', label: 'Last Name' },
    { key: 'update_with_document', label: 'Update guest detail with document' },
    { key: 'phone', label: 'Phone' },
    { key: 'email', label: 'E-mail' },
    { key: 'phone_or_email', label: 'Phone or E-mail' },
];

const guestForm = useForm({
    enable_tracker_number: props.guest.enable_tracker_number,
    tracker_prefix: props.guest.tracker_prefix,
    tracker_start_number: props.guest.tracker_start_number,
    mandatory: { ...props.guest.mandatory, first_name: true } as Record<
        string,
        boolean
    >,
    default_nationality: props.guest.default_nationality,
});
const editingTracker = ref(false);

function saveGuest(): void {
    guestForm.put(admin.applicationSettings.update('guest').url, {
        preserveScroll: true,
        onSuccess: () => (editingTracker.value = false),
    });
}

// ── Widget ──
const widgetRows = ref([...props.widgetProperties]);
const draggedWidgetIndex = ref<number | null>(null);

watch(
    () => props.widgetProperties,
    (rows) => (widgetRows.value = [...rows]),
);

function dropWidget(targetIndex: number): void {
    if (
        draggedWidgetIndex.value === null ||
        draggedWidgetIndex.value === targetIndex
    ) {
        draggedWidgetIndex.value = null;

        return;
    }

    moveItem(widgetRows.value, draggedWidgetIndex.value, targetIndex);
    draggedWidgetIndex.value = null;
    router.put(
        admin.applicationSettings.update('widget').url,
        { property_order: widgetRows.value.map((row) => row.id) },
        { preserveScroll: true },
    );
}

// ── Day Posting (12-hour spinner over a 24-hour H:i value) ──
function parsePostingTime(value: string): {
    hour: number;
    minute: number;
    meridiem: 'AM' | 'PM';
} {
    const [hours, minutes] = value.split(':').map(Number);

    return {
        hour: hours % 12 || 12,
        minute: minutes || 0,
        meridiem: hours >= 12 ? 'PM' : 'AM',
    };
}

const postingTime = ref(
    parsePostingTime(props.dayPosting.transaction_posting_time),
);
const dayPostingForm = useForm({
    transaction_posting_time: props.dayPosting.transaction_posting_time,
});

watch(
    postingTime,
    ({ hour, minute, meridiem }) => {
        const hours24 = (hour % 12) + (meridiem === 'PM' ? 12 : 0);
        dayPostingForm.transaction_posting_time = `${String(hours24).padStart(2, '0')}:${String(minute).padStart(2, '0')}`;
    },
    { deep: true },
);

function stepHour(delta: number): void {
    postingTime.value.hour =
        ((postingTime.value.hour - 1 + delta + 12) % 12) + 1;
}

function stepMinute(delta: number): void {
    postingTime.value.minute = (postingTime.value.minute + delta + 60) % 60;
}

function toggleMeridiem(): void {
    postingTime.value.meridiem =
        postingTime.value.meridiem === 'AM' ? 'PM' : 'AM';
}

function resetDayPosting(): void {
    postingTime.value = parsePostingTime(
        props.dayPosting.transaction_posting_time,
    );
}

function saveDayPosting(): void {
    dayPostingForm.put(admin.applicationSettings.update('day_posting').url, {
        preserveScroll: true,
    });
}

const pad = (value: number): string => String(value).padStart(2, '0');

// ── Smart Booking Chart ──
const smartChartForm = useForm({
    days_to_show: props.smartChart.days_to_show,
    auto_refresh_minutes: props.smartChart.auto_refresh_minutes,
});

function saveSmartChart(): void {
    smartChartForm.put(admin.applicationSettings.update('smart_chart').url, {
        preserveScroll: true,
    });
}

// ── Notification Settings ──
const channelStatusError = ref<string | null>(null);
const savingChannel = ref<string | null>(null);

function toggleChannel(channel: NotificationChannel): void {
    channelStatusError.value = null;
    savingChannel.value = channel.key;

    router.put(
        admin.applicationSettings.notificationChannels.status(channel.key).url,
        { enabled: !channel.enabled },
        {
            preserveScroll: true,
            onError: (errors) =>
                (channelStatusError.value =
                    errors.enabled ?? 'The status could not be changed.'),
            onFinish: () => (savingChannel.value = null),
        },
    );
}

const emailChannel = computed(() =>
    props.notificationChannels.find((channel) => channel.key === 'email'),
);
const emailModalOpen = ref(false);
const emailForm = useForm({
    host: '',
    port: 587,
    encryption: 'tls' as SmtpSettings['encryption'],
    username: '',
    password: '',
    clear_password: false,
    from_address: '',
    from_name: '',
});
const testEmailForm = useForm({ to: '' });

function openEmailSettings(): void {
    const smtp = emailChannel.value?.smtp;

    emailForm.clearErrors();
    testEmailForm.clearErrors();
    emailForm.host = smtp?.host ?? '';
    emailForm.port = smtp?.port ?? 587;
    emailForm.encryption = smtp?.encryption ?? 'tls';
    emailForm.username = smtp?.username ?? '';
    emailForm.password = '';
    emailForm.clear_password = false;
    emailForm.from_address = smtp?.from_address ?? '';
    emailForm.from_name = smtp?.from_name ?? '';
    emailModalOpen.value = true;
}

/** The usual port for each encryption, applied only while the port is still one of the defaults. */
watch(
    () => emailForm.encryption,
    (encryption) => {
        if ([25, 465, 587].includes(Number(emailForm.port))) {
            emailForm.port =
                encryption === 'ssl' ? 465 : encryption === 'tls' ? 587 : 25;
        }
    },
);

function saveEmailSettings(): void {
    emailForm.put(
        admin.applicationSettings.notificationChannels.email.update().url,
        {
            preserveScroll: true,
            onSuccess: () => {
                emailForm.password = '';
                emailForm.clear_password = false;
            },
        },
    );
}

const guestEmailsForm = useForm<Record<string, boolean>>(
    Object.fromEntries(
        props.guestEmails.map((email) => [email.key, email.enabled]),
    ),
);

function saveGuestEmails(): void {
    guestEmailsForm.put(admin.applicationSettings.guestEmails.update().url, {
        preserveScroll: true,
    });
}

function sendTestEmail(): void {
    testEmailForm.post(
        admin.applicationSettings.notificationChannels.email.test().url,
        { preserveScroll: true },
    );
}
</script>

<template>
    <Head title="Application Settings" />

    <div class="mb-6 flex items-center justify-between">
        <h1
            class="font-display text-2xl font-semibold text-[#1b1b18] dark:text-[#EDEDEC]"
        >
            Application Settings
        </h1>
        <button
            type="button"
            class="rounded-md border border-[#e3e3e0] px-4 py-2 text-sm font-medium text-[#1b1b18] hover:bg-[#f5f5f4] dark:border-[#3E3E3A] dark:text-[#EDEDEC] dark:hover:bg-[#1D1D1B]"
            @click="
                activityLogsSection = tabs.find(
                    (tab) => tab.key === activeTab,
                )!.section
            "
        >
            Activity Logs
        </button>
    </div>

    <div
        class="mb-6 flex flex-wrap gap-1 border-b border-[#e3e3e0] dark:border-[#3E3E3A]"
    >
        <button
            v-for="tab in tabs"
            :key="tab.key"
            type="button"
            class="border-b-2 px-4 py-2 text-sm font-medium"
            :class="
                activeTab === tab.key
                    ? 'border-[#f53003] text-[#1b1b18] dark:border-[#FF4433] dark:text-[#EDEDEC]'
                    : 'border-transparent text-[#706f6c] dark:text-[#A1A09A]'
            "
            @click="activeTab = tab.key"
        >
            {{ tab.label }}
        </button>
    </div>

    <!-- Block Unblock Config -->
    <section
        v-show="activeTab === 'block-unblock'"
        class="rounded-lg border border-[#e3e3e0] p-5 dark:border-[#3E3E3A]"
    >
        <h2
            class="mb-1 text-lg font-semibold text-[#1b1b18] dark:text-[#EDEDEC]"
        >
            Block Unblock Config
            <span class="text-xs font-medium text-[#706f6c] dark:text-[#A1A09A]"
                >(In Order Of Display)</span
            >
        </h2>
        <p class="mb-4 text-sm text-[#706f6c] dark:text-[#A1A09A]">
            These reasons appear on the smart chart's Block and Bulk Block
            forms. Drag rows to change their order.
        </p>

        <div class="flex flex-wrap items-start justify-between gap-6">
            <table
                class="w-full max-w-lg overflow-hidden rounded-md border border-[#e3e3e0] text-sm dark:border-[#3E3E3A]"
            >
                <thead
                    class="bg-[#dbe7f3] text-left text-[#1b1b18] dark:bg-[#1D1D1B] dark:text-[#EDEDEC]"
                >
                    <tr>
                        <th class="w-8 px-3 py-2"></th>
                        <th class="px-3 py-2 font-semibold">Display Order</th>
                        <th class="px-3 py-2 font-semibold">Name</th>
                        <th class="px-3 py-2 text-right font-semibold">Edit</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-[#e3e3e0] dark:divide-[#3E3E3A]">
                    <tr
                        v-for="(reason, index) in reasonRows"
                        :key="reason.id"
                        draggable="true"
                        class="bg-white dark:bg-[#161615]"
                        :class="{ 'opacity-50': draggedReasonIndex === index }"
                        @dragstart="draggedReasonIndex = index"
                        @dragover.prevent
                        @drop.prevent="dropReason(index)"
                        @dragend="draggedReasonIndex = null"
                    >
                        <td
                            class="cursor-move px-3 py-2 text-[#706f6c]"
                            title="Drag to reorder"
                        >
                            ⋮⋮
                        </td>
                        <td
                            class="px-3 py-2 text-[#1b1b18] dark:text-[#EDEDEC]"
                        >
                            {{ index + 1 }}
                        </td>
                        <td
                            class="px-3 py-2 text-[#1b1b18] dark:text-[#EDEDEC]"
                        >
                            {{ reason.name }}
                        </td>
                        <td class="px-3 py-2 text-right">
                            <button
                                type="button"
                                title="Rename"
                                class="text-[#706f6c] hover:text-[#1b1b18] dark:hover:text-[#EDEDEC]"
                                @click="openReasonModal(reason)"
                            >
                                ✎
                            </button>
                        </td>
                    </tr>
                </tbody>
            </table>

            <button
                type="button"
                class="rounded-md bg-[#337ab7] px-4 py-2 text-sm font-medium text-white hover:bg-[#286090]"
                @click="openReasonModal(null)"
            >
                Add Block Reason
            </button>
        </div>
    </section>

    <!-- Reservation -->
    <section
        v-show="activeTab === 'reservation'"
        class="rounded-lg border border-[#e3e3e0] p-5 dark:border-[#3E3E3A]"
    >
        <div
            class="mb-5 flex items-center justify-between border-b border-[#e3e3e0] dark:border-[#3E3E3A]"
        >
            <div class="flex gap-4">
                <button
                    v-for="subTab in [
                        { key: 'mandatory', label: 'Mandatory Field' },
                        { key: 'subtypes', label: 'Configurable Subtype' },
                    ] as const"
                    :key="subTab.key"
                    type="button"
                    class="-mb-px border-b-2 pb-2 text-sm font-semibold uppercase"
                    :class="
                        reservationTab === subTab.key
                            ? 'border-[#16a34a] text-[#1b1b18] dark:text-[#EDEDEC]'
                            : 'border-transparent text-[#706f6c] dark:text-[#A1A09A]'
                    "
                    @click="reservationTab = subTab.key"
                >
                    {{ subTab.label }}
                </button>
            </div>
            <button
                type="button"
                :disabled="reservationForm.processing"
                class="mb-2 rounded-md bg-[#337ab7] px-4 py-1.5 text-sm font-medium text-white hover:bg-[#286090] disabled:opacity-50"
                @click="saveReservation"
            >
                Save
            </button>
        </div>

        <div
            v-show="reservationTab === 'mandatory'"
            class="flex flex-col gap-6"
        >
            <div>
                <h3
                    class="mb-1 text-base font-semibold text-[#1b1b18] uppercase dark:text-[#EDEDEC]"
                >
                    Mandatory Field for Check-in
                </h3>
                <p class="mb-3 text-xs text-[#706f6c] dark:text-[#A1A09A]">
                    Checked fields must be filled on the booking's guests before
                    it can be checked in.
                </p>
                <div class="flex flex-col gap-2">
                    <label
                        v-for="field in checkInFields"
                        :key="field.key"
                        class="flex items-center gap-3 text-sm text-[#1b1b18] dark:text-[#EDEDEC]"
                    >
                        <input
                            v-model="reservationForm.check_in[field.key]"
                            type="checkbox"
                            :class="checkboxClass"
                        />
                        {{ field.label }}
                    </label>
                </div>
            </div>
            <div class="border-t border-[#e3e3e0] pt-5 dark:border-[#3E3E3A]">
                <h3
                    class="mb-1 text-base font-semibold text-[#1b1b18] uppercase dark:text-[#EDEDEC]"
                >
                    Mandatory Field for Check-out
                </h3>
                <p class="mb-3 text-xs text-[#706f6c] dark:text-[#A1A09A]">
                    Checked fields must be filled on the booking's guests before
                    it can be checked out.
                </p>
                <div class="flex flex-col gap-2">
                    <label
                        v-for="field in checkOutFields"
                        :key="field.key"
                        class="flex items-center gap-3 text-sm text-[#1b1b18] dark:text-[#EDEDEC]"
                    >
                        <input
                            v-model="reservationForm.check_out[field.key]"
                            type="checkbox"
                            :class="checkboxClass"
                        />
                        {{ field.label }}
                    </label>
                </div>
            </div>
        </div>

        <div v-show="reservationTab === 'subtypes'" class="max-w-xl">
            <p class="mb-3 text-sm text-[#706f6c] dark:text-[#A1A09A]">
                The reservation "Type" options offered on the booking form.
                Switched-off subtypes stay on existing bookings.
            </p>
            <p
                v-for="message in subtypeErrors"
                :key="message"
                class="mb-2 text-sm text-[#f53003] dark:text-[#FF4433]"
            >
                {{ message }}
            </p>
            <div class="flex flex-col gap-2">
                <div
                    v-for="(subtype, index) in reservationForm.subtypes"
                    :key="index"
                    class="flex items-center gap-3"
                >
                    <input
                        v-model="subtype.active"
                        type="checkbox"
                        title="Active"
                        :class="checkboxClass"
                    />
                    <input
                        v-model="subtype.label"
                        type="text"
                        placeholder="Subtype name"
                        :class="[inputClass, 'w-64']"
                    />
                    <button
                        v-if="!subtype.value"
                        type="button"
                        class="text-sm text-[#706f6c] hover:text-[#f53003] dark:text-[#A1A09A] dark:hover:text-[#FF4433]"
                        @click="removeSubtype(index)"
                    >
                        Remove
                    </button>
                </div>
            </div>
            <button
                type="button"
                class="mt-3 text-sm font-medium text-[#1b1b18] underline underline-offset-4 dark:text-[#EDEDEC]"
                @click="addSubtype"
            >
                Add subtype
            </button>
        </div>
    </section>

    <!-- Guest -->
    <section
        v-show="activeTab === 'guest'"
        class="rounded-lg border border-[#e3e3e0] p-5 dark:border-[#3E3E3A]"
    >
        <h2
            class="mb-4 text-lg font-semibold text-[#1b1b18] uppercase dark:text-[#EDEDEC]"
        >
            Guest Settings
        </h2>

        <label
            class="mb-4 flex items-center gap-3 text-sm font-semibold text-[#1b1b18] dark:text-[#EDEDEC]"
        >
            <input
                v-model="guestForm.enable_tracker_number"
                type="checkbox"
                :class="checkboxClass"
            />
            Enable Guest Tracker Number
        </label>

        <div
            class="mb-6 flex max-w-3xl items-start justify-between border-b border-[#e3e3e0] pb-5 dark:border-[#3E3E3A]"
        >
            <div>
                <p
                    class="mb-2 text-sm font-semibold text-[#1b1b18] dark:text-[#EDEDEC]"
                >
                    Guest Tracker Number
                </p>
                <div
                    v-if="!editingTracker"
                    class="ml-3 flex flex-col gap-1 text-sm text-[#1b1b18] dark:text-[#EDEDEC]"
                >
                    <p>
                        <span class="font-semibold">Pre-fix No.</span>
                        {{ guestForm.tracker_prefix || '—' }}
                    </p>
                    <p>
                        <span class="font-semibold">In Prefix No.</span>
                        {{ guestForm.tracker_start_number }}
                    </p>
                    <p class="text-xs text-[#706f6c] dark:text-[#A1A09A]">
                        New guests are numbered {{ guestForm.tracker_prefix
                        }}{{ guestForm.tracker_start_number }},
                        {{ guestForm.tracker_prefix
                        }}{{ guestForm.tracker_start_number + 1 }}, … while
                        enabled.
                    </p>
                </div>
                <div v-else class="ml-3 flex flex-wrap gap-4">
                    <label
                        class="flex flex-col gap-1 text-sm text-[#706f6c] dark:text-[#A1A09A]"
                    >
                        Pre-fix No.
                        <input
                            v-model="guestForm.tracker_prefix"
                            type="text"
                            maxlength="20"
                            placeholder="e.g. GST-"
                            :class="[inputClass, 'w-40']"
                        />
                    </label>
                    <label
                        class="flex flex-col gap-1 text-sm text-[#706f6c] dark:text-[#A1A09A]"
                    >
                        In Prefix No.
                        <input
                            v-model.number="guestForm.tracker_start_number"
                            type="number"
                            min="1"
                            :class="[inputClass, 'w-32']"
                        />
                    </label>
                </div>
                <p
                    v-if="
                        guestForm.errors.tracker_prefix ||
                        guestForm.errors.tracker_start_number
                    "
                    class="mt-2 text-sm text-[#f53003] dark:text-[#FF4433]"
                >
                    {{
                        guestForm.errors.tracker_prefix ||
                        guestForm.errors.tracker_start_number
                    }}
                </p>
            </div>
            <button
                type="button"
                class="text-sm text-[#337ab7] hover:underline"
                @click="editingTracker = !editingTracker"
            >
                ✎ {{ editingTracker ? 'Done' : 'Edit' }}
            </button>
        </div>

        <p
            class="mb-3 text-sm font-semibold text-[#1b1b18] dark:text-[#EDEDEC]"
        >
            Mandatory Fields for Guest creation:
        </p>
        <div class="mb-6 grid max-w-3xl grid-cols-1 gap-3 sm:grid-cols-3">
            <label
                v-for="field in guestMandatoryFields"
                :key="field.key"
                class="flex items-center gap-2 text-sm font-semibold text-[#1b1b18] dark:text-[#EDEDEC]"
            >
                <input
                    v-model="guestForm.mandatory[field.key]"
                    type="checkbox"
                    :disabled="field.key === 'first_name'"
                    :class="checkboxClass"
                />
                {{ field.label }}
            </label>
        </div>

        <div class="flex flex-wrap items-end justify-between gap-4">
            <label
                class="flex items-center gap-3 text-sm font-semibold text-[#1b1b18] dark:text-[#EDEDEC]"
            >
                Default Guest Nationality Selection
                <select
                    v-model="guestForm.default_nationality"
                    :class="[inputClass, 'w-56 font-normal']"
                >
                    <option value="property_country">Property Country</option>
                    <option value="">Nil</option>
                    <option
                        v-for="country in countries"
                        :key="country"
                        :value="country"
                    >
                        {{ country }}
                    </option>
                </select>
            </label>
            <p
                v-if="guestForm.errors.default_nationality"
                class="w-full text-sm text-[#f53003] dark:text-[#FF4433]"
            >
                {{ guestForm.errors.default_nationality }}
            </p>
            <button
                type="button"
                :disabled="guestForm.processing"
                class="rounded-md bg-[#337ab7] px-5 py-2 text-sm font-medium text-white hover:bg-[#286090] disabled:opacity-50"
                @click="saveGuest"
            >
                Save
            </button>
        </div>
    </section>

    <!-- Widget -->
    <section
        v-show="activeTab === 'widget'"
        class="rounded-lg border border-[#e3e3e0] p-5 dark:border-[#3E3E3A]"
    >
        <h2
            class="mb-1 text-lg font-semibold text-[#1b1b18] dark:text-[#EDEDEC]"
        >
            Widget Setting
        </h2>
        <p class="mb-4 text-sm text-[#706f6c] dark:text-[#A1A09A]">
            Drag properties to set the order they're listed in the booking
            widget. Changes save immediately.
        </p>
        <table
            class="w-full max-w-lg overflow-hidden rounded-md border border-[#e3e3e0] text-sm dark:border-[#3E3E3A]"
        >
            <thead
                class="bg-[#dbe7f3] text-left text-[#1b1b18] dark:bg-[#1D1D1B] dark:text-[#EDEDEC]"
            >
                <tr>
                    <th class="w-8 px-3 py-2">↕</th>
                    <th class="px-3 py-2 font-semibold">Display Order</th>
                    <th class="px-3 py-2 font-semibold">Name</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-[#e3e3e0] dark:divide-[#3E3E3A]">
                <tr
                    v-for="(row, index) in widgetRows"
                    :key="row.id"
                    draggable="true"
                    class="bg-white dark:bg-[#161615]"
                    :class="{ 'opacity-50': draggedWidgetIndex === index }"
                    @dragstart="draggedWidgetIndex = index"
                    @dragover.prevent
                    @drop.prevent="dropWidget(index)"
                    @dragend="draggedWidgetIndex = null"
                >
                    <td
                        class="cursor-move px-3 py-2 text-[#706f6c]"
                        title="Drag to reorder"
                    >
                        ⋮⋮
                    </td>
                    <td class="px-3 py-2 text-[#1b1b18] dark:text-[#EDEDEC]">
                        {{ index + 1 }}
                    </td>
                    <td
                        class="px-3 py-2 text-[#1b1b18] uppercase dark:text-[#EDEDEC]"
                    >
                        {{ row.name }}
                    </td>
                </tr>
            </tbody>
        </table>
    </section>

    <!-- Day Posting -->
    <section
        v-show="activeTab === 'day-posting'"
        class="rounded-lg border border-[#e3e3e0] p-5 dark:border-[#3E3E3A]"
    >
        <h2
            class="mb-4 text-lg font-semibold text-[#1b1b18] uppercase dark:text-[#EDEDEC]"
        >
            Day Posting Setting
        </h2>
        <div class="flex flex-wrap items-center gap-10">
            <span class="text-sm text-[#1b1b18] dark:text-[#EDEDEC]"
                >Transaction Posting Time:</span
            >
            <div class="flex items-center gap-2">
                <div
                    v-for="part in ['hour', 'minute', 'meridiem'] as const"
                    :key="part"
                    class="flex flex-col items-center gap-1"
                >
                    <button
                        type="button"
                        :title="`Increase ${part}`"
                        class="h-8 w-10 rounded border border-[#e3e3e0] text-[#1b1b18] hover:bg-[#f5f5f4] dark:border-[#3E3E3A] dark:text-[#EDEDEC] dark:hover:bg-[#1D1D1B]"
                        @click="
                            part === 'hour'
                                ? stepHour(1)
                                : part === 'minute'
                                  ? stepMinute(1)
                                  : toggleMeridiem()
                        "
                    >
                        ▲
                    </button>
                    <span
                        class="flex h-8 w-10 items-center justify-center rounded border border-[#e3e3e0] bg-[#f5f5f4] text-sm text-[#1b1b18] dark:border-[#3E3E3A] dark:bg-[#1D1D1B] dark:text-[#EDEDEC]"
                    >
                        {{
                            part === 'hour'
                                ? pad(postingTime.hour)
                                : part === 'minute'
                                  ? pad(postingTime.minute)
                                  : postingTime.meridiem
                        }}
                    </span>
                    <button
                        type="button"
                        :title="`Decrease ${part}`"
                        class="h-8 w-10 rounded border border-[#e3e3e0] text-[#1b1b18] hover:bg-[#f5f5f4] dark:border-[#3E3E3A] dark:text-[#EDEDEC] dark:hover:bg-[#1D1D1B]"
                        @click="
                            part === 'hour'
                                ? stepHour(-1)
                                : part === 'minute'
                                  ? stepMinute(-1)
                                  : toggleMeridiem()
                        "
                    >
                        ▼
                    </button>
                </div>
            </div>
        </div>
        <p
            v-if="dayPostingForm.errors.transaction_posting_time"
            class="mt-2 text-sm text-[#f53003] dark:text-[#FF4433]"
        >
            {{ dayPostingForm.errors.transaction_posting_time }}
        </p>
        <div class="mt-4 flex justify-end gap-2">
            <button
                type="button"
                :disabled="dayPostingForm.processing"
                class="rounded-md bg-[#337ab7] px-4 py-2 text-sm font-medium text-white hover:bg-[#286090] disabled:opacity-50"
                @click="saveDayPosting"
            >
                Save
            </button>
            <button
                type="button"
                class="rounded-md border border-[#e3e3e0] px-4 py-2 text-sm font-medium text-[#1b1b18] dark:border-[#3E3E3A] dark:text-[#EDEDEC]"
                @click="resetDayPosting"
            >
                Cancel
            </button>
        </div>
    </section>

    <!-- Smart Booking Chart -->
    <section
        v-show="activeTab === 'smart-chart'"
        class="rounded-lg border border-[#e3e3e0] p-5 dark:border-[#3E3E3A]"
    >
        <h2
            class="mb-4 text-lg font-semibold text-[#1b1b18] uppercase dark:text-[#EDEDEC]"
        >
            Smart Booking Chart Settings
        </h2>
        <form
            class="flex flex-wrap items-end gap-4"
            @submit.prevent="saveSmartChart"
        >
            <label
                class="flex flex-col gap-1 text-sm text-[#1b1b18] dark:text-[#EDEDEC]"
            >
                No of days to show
                <input
                    v-model.number="smartChartForm.days_to_show"
                    type="number"
                    :min="smartChart.min_days"
                    :max="smartChart.max_days"
                    step="1"
                    required
                    :class="[inputClass, 'w-32']"
                />
                <span class="text-xs text-[#706f6c] dark:text-[#A1A09A]"
                    >Between {{ smartChart.min_days }} and
                    {{ smartChart.max_days }} days. Default is 10.</span
                >
            </label>
            <label
                class="flex flex-col gap-1 text-sm text-[#1b1b18] dark:text-[#EDEDEC]"
            >
                Auto-refresh time (minutes)
                <input
                    v-model.number="smartChartForm.auto_refresh_minutes"
                    type="number"
                    :min="smartChart.min_refresh_minutes"
                    :max="smartChart.max_refresh_minutes"
                    step="1"
                    required
                    :class="[inputClass, 'w-32']"
                />
                <span class="text-xs text-[#706f6c] dark:text-[#A1A09A]"
                    >Between {{ smartChart.min_refresh_minutes }} and
                    {{ smartChart.max_refresh_minutes }} minutes. Default is
                    2.</span
                >
            </label>
            <button
                type="submit"
                :disabled="smartChartForm.processing"
                class="rounded-md bg-[#337ab7] px-4 py-2 text-sm font-medium text-white hover:bg-[#286090] disabled:opacity-50"
            >
                Save
            </button>
        </form>
        <p
            v-if="smartChartForm.errors.days_to_show"
            class="mt-2 text-sm text-[#f53003] dark:text-[#FF4433]"
        >
            {{ smartChartForm.errors.days_to_show }}
        </p>
        <p
            v-if="smartChartForm.errors.auto_refresh_minutes"
            class="mt-2 text-sm text-[#f53003] dark:text-[#FF4433]"
        >
            {{ smartChartForm.errors.auto_refresh_minutes }}
        </p>
    </section>

    <!-- Notification Settings -->
    <section
        v-show="activeTab === 'notifications'"
        class="rounded-lg border border-[#e3e3e0] p-5 dark:border-[#3E3E3A]"
    >
        <h2
            class="mb-1 text-lg font-semibold text-[#1b1b18] uppercase dark:text-[#EDEDEC]"
        >
            Notification Settings
        </h2>
        <p class="mb-4 text-sm text-[#706f6c] dark:text-[#A1A09A]">
            Channels used to send guest and staff notifications. While E-mail is
            switched on, all e-mail from the application goes through its SMTP
            server.
        </p>

        <p
            v-if="channelStatusError"
            class="mb-3 text-sm text-[#f53003] dark:text-[#FF4433]"
        >
            {{ channelStatusError }}
        </p>

        <table
            class="w-full max-w-3xl overflow-hidden rounded-md border border-[#e3e3e0] text-sm dark:border-[#3E3E3A]"
        >
            <thead
                class="bg-[#dbe7f3] text-left text-[#1b1b18] dark:bg-[#1D1D1B] dark:text-[#EDEDEC]"
            >
                <tr>
                    <th class="px-3 py-2 font-semibold">Channel</th>
                    <th class="px-3 py-2 font-semibold">Configuration</th>
                    <th class="px-3 py-2 font-semibold">Status</th>
                    <th class="px-3 py-2 text-right font-semibold">Action</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-[#e3e3e0] dark:divide-[#3E3E3A]">
                <tr
                    v-for="channel in notificationChannels"
                    :key="channel.key"
                    class="text-[#1b1b18] dark:text-[#EDEDEC]"
                >
                    <td class="px-3 py-2.5 font-medium">
                        {{ channel.label }}
                        <span
                            v-if="!channel.supported"
                            class="ml-2 rounded-full bg-gray-100 px-2 py-0.5 text-xs font-medium text-[#706f6c] dark:bg-[#262625] dark:text-[#A1A09A]"
                            >Not supported</span
                        >
                    </td>
                    <td class="px-3 py-2.5 text-[#706f6c] dark:text-[#A1A09A]">
                        <template v-if="!channel.supported">—</template>
                        <template v-else-if="channel.configured && channel.smtp"
                            >{{ channel.smtp.host }}:{{ channel.smtp.port }} ·
                            {{ channel.smtp.from_address }}</template
                        >
                        <span v-else class="text-amber-700 dark:text-amber-400"
                            >Not configured</span
                        >
                    </td>
                    <td class="px-3 py-2.5">
                        <button
                            type="button"
                            role="switch"
                            :aria-checked="channel.enabled"
                            :aria-label="`${channel.label} status`"
                            :disabled="
                                !channel.supported || savingChannel !== null
                            "
                            :title="
                                channel.supported
                                    ? channel.enabled
                                        ? 'Switch off'
                                        : 'Switch on'
                                    : `${channel.label} isn't supported yet`
                            "
                            class="relative inline-flex h-6 w-11 shrink-0 items-center rounded-full transition-colors disabled:cursor-not-allowed disabled:opacity-50"
                            :class="
                                channel.enabled
                                    ? 'bg-emerald-500'
                                    : 'bg-gray-300 dark:bg-[#3E3E3A]'
                            "
                            @click="toggleChannel(channel)"
                        >
                            <span
                                class="inline-block h-5 w-5 rounded-full bg-white shadow transition-transform"
                                :class="
                                    channel.enabled
                                        ? 'translate-x-5'
                                        : 'translate-x-0.5'
                                "
                            />
                        </button>
                        <span
                            class="ml-2 text-xs text-[#706f6c] dark:text-[#A1A09A]"
                            >{{ channel.enabled ? 'On' : 'Off' }}</span
                        >
                    </td>
                    <td class="px-3 py-2.5 text-right">
                        <button
                            type="button"
                            :disabled="!channel.supported"
                            :title="
                                channel.supported
                                    ? undefined
                                    : `${channel.label} isn't supported yet`
                            "
                            class="rounded-md border border-[#e3e3e0] px-3 py-1 text-sm font-medium hover:bg-[#f5f5f4] disabled:cursor-not-allowed disabled:opacity-50 dark:border-[#3E3E3A] dark:hover:bg-[#1D1D1B]"
                            @click="openEmailSettings"
                        >
                            Configure
                        </button>
                    </td>
                </tr>
            </tbody>
        </table>

        <form class="mt-6 max-w-3xl" @submit.prevent="saveGuestEmails">
            <h3
                class="mb-1 text-sm font-semibold text-[#1b1b18] uppercase dark:text-[#EDEDEC]"
            >
                Guest E-mails
            </h3>
            <p class="mb-3 text-sm text-[#706f6c] dark:text-[#A1A09A]">
                Sent to the booking's guest automatically, when they have an
                e-mail address. OTA bookings are left out: the OTA e-mails its
                own guests.
            </p>
            <div class="flex flex-col gap-2">
                <label
                    v-for="email in guestEmails"
                    :key="email.key"
                    class="flex items-center gap-2 text-sm text-[#1b1b18] dark:text-[#EDEDEC]"
                >
                    <input
                        v-model="guestEmailsForm[email.key]"
                        type="checkbox"
                        :class="checkboxClass"
                    />
                    {{ email.label }}
                </label>
            </div>
            <button
                type="submit"
                :disabled="guestEmailsForm.processing"
                class="mt-3 rounded-md bg-[#337ab7] px-4 py-2 text-sm font-medium text-white hover:bg-[#286090] disabled:opacity-50"
            >
                Save
            </button>
        </form>
    </section>

    <!-- E-mail (SMTP) settings -->
    <Modal
        :show="emailModalOpen"
        title="E-mail — SMTP Settings"
        @close="emailModalOpen = false"
    >
        <form
            class="grid grid-cols-1 gap-3 sm:grid-cols-2"
            @submit.prevent="saveEmailSettings"
        >
            <label
                class="flex flex-col gap-1 text-sm text-[#1b1b18] sm:col-span-2 dark:text-[#EDEDEC]"
            >
                SMTP Host*
                <input
                    v-model="emailForm.host"
                    type="text"
                    placeholder="smtp.example.com"
                    autocomplete="off"
                    :class="inputClass"
                />
                <span
                    v-if="emailForm.errors.host"
                    class="text-xs text-[#f53003] dark:text-[#FF4433]"
                    >{{ emailForm.errors.host }}</span
                >
            </label>
            <label
                class="flex flex-col gap-1 text-sm text-[#1b1b18] dark:text-[#EDEDEC]"
            >
                Encryption*
                <select v-model="emailForm.encryption" :class="inputClass">
                    <option value="tls">TLS (STARTTLS)</option>
                    <option value="ssl">SSL</option>
                    <option value="none">None</option>
                </select>
            </label>
            <label
                class="flex flex-col gap-1 text-sm text-[#1b1b18] dark:text-[#EDEDEC]"
            >
                Port*
                <input
                    v-model.number="emailForm.port"
                    type="number"
                    min="1"
                    max="65535"
                    :class="inputClass"
                />
                <span
                    v-if="emailForm.errors.port"
                    class="text-xs text-[#f53003] dark:text-[#FF4433]"
                    >{{ emailForm.errors.port }}</span
                >
            </label>
            <label
                class="flex flex-col gap-1 text-sm text-[#1b1b18] dark:text-[#EDEDEC]"
            >
                Username
                <input
                    v-model="emailForm.username"
                    type="text"
                    autocomplete="off"
                    :class="inputClass"
                />
            </label>
            <label
                class="flex flex-col gap-1 text-sm text-[#1b1b18] dark:text-[#EDEDEC]"
            >
                Password
                <input
                    v-model="emailForm.password"
                    type="password"
                    autocomplete="new-password"
                    :placeholder="
                        emailChannel?.smtp?.has_password
                            ? 'Saved — leave blank to keep'
                            : ''
                    "
                    :disabled="emailForm.clear_password"
                    :class="inputClass"
                />
                <span
                    v-if="emailChannel?.smtp?.has_password"
                    class="flex items-center gap-1.5 text-xs text-[#706f6c] dark:text-[#A1A09A]"
                >
                    <input
                        v-model="emailForm.clear_password"
                        type="checkbox"
                        :class="checkboxClass"
                    />
                    Remove saved password
                </span>
            </label>
            <label
                class="flex flex-col gap-1 text-sm text-[#1b1b18] dark:text-[#EDEDEC]"
            >
                From E-mail*
                <input
                    v-model="emailForm.from_address"
                    type="email"
                    placeholder="reservations@example.com"
                    :class="inputClass"
                />
                <span
                    v-if="emailForm.errors.from_address"
                    class="text-xs text-[#f53003] dark:text-[#FF4433]"
                    >{{ emailForm.errors.from_address }}</span
                >
            </label>
            <label
                class="flex flex-col gap-1 text-sm text-[#1b1b18] dark:text-[#EDEDEC]"
            >
                From Name
                <input
                    v-model="emailForm.from_name"
                    type="text"
                    :class="inputClass"
                />
            </label>
            <div class="flex justify-end gap-2 sm:col-span-2">
                <button
                    type="submit"
                    :disabled="emailForm.processing"
                    class="rounded-md bg-[#337ab7] px-4 py-1.5 text-sm text-white hover:bg-[#286090] disabled:opacity-50"
                >
                    {{ emailForm.processing ? 'Saving...' : 'Save' }}
                </button>
                <button
                    type="button"
                    class="rounded-md border border-[#e3e3e0] px-4 py-1.5 text-sm text-[#1b1b18] dark:border-[#3E3E3A] dark:text-[#EDEDEC]"
                    @click="emailModalOpen = false"
                >
                    Close
                </button>
            </div>
        </form>

        <form
            class="mt-5 border-t border-[#e3e3e0] pt-4 dark:border-[#3E3E3A]"
            @submit.prevent="sendTestEmail"
        >
            <p
                class="mb-2 text-sm font-semibold text-[#1b1b18] dark:text-[#EDEDEC]"
            >
                Send a test e-mail
            </p>
            <p class="mb-2 text-xs text-[#706f6c] dark:text-[#A1A09A]">
                Uses the saved settings — save any changes first.
            </p>
            <div class="flex flex-wrap items-center gap-2">
                <input
                    v-model="testEmailForm.to"
                    type="email"
                    placeholder="you@example.com"
                    :class="[inputClass, 'w-64']"
                />
                <button
                    type="submit"
                    :disabled="
                        testEmailForm.processing || !emailChannel?.configured
                    "
                    class="rounded-md border border-[#e3e3e0] px-4 py-2 text-sm font-medium text-[#1b1b18] hover:bg-[#f5f5f4] disabled:opacity-50 dark:border-[#3E3E3A] dark:text-[#EDEDEC] dark:hover:bg-[#1D1D1B]"
                >
                    {{ testEmailForm.processing ? 'Sending...' : 'Send Test' }}
                </button>
            </div>
            <p
                v-if="testEmailForm.errors.to"
                class="mt-1 text-xs text-[#f53003] dark:text-[#FF4433]"
            >
                {{ testEmailForm.errors.to }}
            </p>
        </form>
    </Modal>

    <!-- Add / rename block reason -->
    <div
        v-if="reasonModalOpen"
        class="fixed inset-0 z-50 flex items-start justify-center bg-black/40 p-4 pt-16"
        @mousedown.self="reasonModalOpen = false"
    >
        <div
            class="w-full max-w-sm overflow-hidden rounded-md bg-white shadow-2xl dark:bg-[#161615]"
        >
            <div
                class="flex items-center justify-between bg-[#2f3337] px-4 py-3 text-white"
            >
                <h3 class="font-semibold">Reason</h3>
                <button
                    type="button"
                    class="text-lg leading-none text-white/70 hover:text-white"
                    @click="reasonModalOpen = false"
                >
                    X
                </button>
            </div>
            <form class="flex flex-col gap-3 p-4" @submit.prevent="saveReason">
                <p class="text-sm text-[#1b1b18] dark:text-[#EDEDEC]">
                    {{
                        editingReason
                            ? 'Update the block reason name'
                            : 'Please enter new block reason details here'
                    }}
                </p>
                <label
                    class="flex items-center gap-3 text-sm font-semibold text-[#1b1b18] dark:text-[#EDEDEC]"
                >
                    <span class="w-28 shrink-0">Reason Name</span>
                    <input
                        v-model="reasonForm.name"
                        type="text"
                        placeholder="Reason Name"
                        maxlength="100"
                        :class="[inputClass, 'w-full font-normal']"
                        autofocus
                    />
                </label>
                <p
                    v-if="reasonForm.errors.name"
                    class="text-sm text-[#f53003] dark:text-[#FF4433]"
                >
                    {{ reasonForm.errors.name }}
                </p>
                <div class="flex justify-end gap-2">
                    <button
                        type="submit"
                        :disabled="reasonForm.processing"
                        class="rounded-md bg-[#337ab7] px-4 py-1.5 text-sm text-white hover:bg-[#286090] disabled:opacity-50"
                    >
                        Save
                    </button>
                    <button
                        type="button"
                        class="rounded-md border border-[#e3e3e0] px-4 py-1.5 text-sm text-[#1b1b18] dark:border-[#3E3E3A] dark:text-[#EDEDEC]"
                        @click="reasonModalOpen = false"
                    >
                        Cancel
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Activity logs -->
    <Modal
        :show="activityLogsSection !== null"
        :title="`Activity Logs — ${activityLogsTitle}`"
        @close="activityLogsSection = null"
    >
        <p
            v-if="!(activityLogs[activityLogsSection ?? ''] ?? []).length"
            class="text-sm text-[#706f6c] dark:text-[#A1A09A]"
        >
            No changes recorded yet.
        </p>
        <ul
            v-else
            class="flex max-h-96 flex-col divide-y divide-[#e3e3e0] overflow-y-auto text-sm dark:divide-[#3E3E3A]"
        >
            <li
                v-for="(entry, index) in activityLogs[
                    activityLogsSection ?? ''
                ]"
                :key="index"
                class="py-2"
            >
                <p class="text-[#1b1b18] dark:text-[#EDEDEC]">
                    {{ entry.description }}
                </p>
                <p class="text-xs text-[#706f6c] dark:text-[#A1A09A]">
                    {{ entry.user ?? 'System' }} ·
                    {{ propertyTime.dateTime(entry.at) }}
                </p>
            </li>
        </ul>
    </Modal>
</template>
