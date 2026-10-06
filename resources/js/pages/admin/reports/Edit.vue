<script setup lang="ts">
import TableColumnSettingsModal, {
    type TableColumn,
} from '@/components/TableColumnSettingsModal.vue';
import AdminLayout from '@/layouts/AdminLayout.vue';
import { usePropertyTime } from '@/lib/usePropertyTime';
import admin from '@/routes/admin';
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, reactive, ref } from 'vue';

defineOptions({ layout: AdminLayout });

type ReportSettings = {
    name: string;
    email_subject: string;
    heading: string;
    send_time: string;
    send_report: boolean;
    same_as_internal: boolean;
    recipients: string[];
    description: string;
    hidden_columns: string[];
};

type Report = {
    key: string;
    dates: 'none' | 'single' | 'range';
    staff: boolean;
    downloads: ('excel' | 'pdf')[];
    available_downloads: ('excel' | 'pdf')[];
    columns: TableColumn[];
    settings: ReportSettings;
};

const props = defineProps<{
    property: { id: number; name: string };
    reports: Report[];
    internalRecipients: string[];
    today: string;
    /** Night audit → Restrict Reports: the last business date dated reports may cover (null: no limit). */
    closedUntil: string | null;
    restrictable: string[];
    monthStart: string;
    monthEnd: string;
    staff: { id: number; name: string }[];
    activityLogs: { action: string; user: string | null; at: string | null }[];
    deliveries: {
        id: number;
        report: string;
        period: string;
        recipients: string[];
        status: 'queued' | 'sent' | 'failed';
        error: string | null;
        scheduled: boolean;
        user: string | null;
        at: string | null;
    }[];
}>();

const page = usePage();
const propertyTime = usePropertyTime();
const activityLogsOpen = ref(false);
const deliveriesOpen = ref(false);
const statusError = computed(() => {
    const errors = page.props.errors as Record<string, string>;

    return (
        errors.status ?? errors.hidden_columns ?? errors.date ?? errors.end_date
    );
});

/** The latest date a report may be run for, if Restrict Reports limits it. */
function maxDate(reportKey: string): string | undefined {
    return props.closedUntil !== null && props.restrictable.includes(reportKey)
        ? props.closedUntil
        : undefined;
}

function limitDate(reportKey: string, date: string): string {
    const max = maxDate(reportKey);

    return max !== undefined && date > max ? max : date;
}

/** The download filters on each row; they only shape a download, so they aren't saved. */
const filters = reactive(
    Object.fromEntries(
        props.reports.map((report) => [
            report.key,
            {
                start: limitDate(
                    report.key,
                    report.dates === 'range' ? props.monthStart : props.today,
                ),
                end: limitDate(report.key, props.monthEnd),
                staff: '',
            },
        ]),
    ) as Record<string, { start: string; end: string; staff: string }>,
);

const downloadLabels: Record<string, string> = { excel: 'XLS', pdf: 'PDF' };

function downloadUrl(report: Report, type: 'excel' | 'pdf'): string {
    const filter = filters[report.key];
    const query: Record<string, string> =
        report.dates === 'range'
            ? { date: filter.start, end_date: filter.end, type }
            : { date: filter.start, type };

    if (report.staff && filter.staff !== '') {
        query.staff = filter.staff;
    }

    return admin.reports.download(
        { property: props.property.id, report: report.key },
        { query },
    ).url;
}

/** "E-mail now": the report for the row's date(s), to its recipients, built by the queue worker. */
const sendingReport = ref<string | null>(null);

function emailNow(report: Report): void {
    const filter = filters[report.key];
    const data: Record<string, string> =
        report.dates === 'range'
            ? { date: filter.start, end_date: filter.end }
            : { date: filter.start };

    if (report.staff && filter.staff !== '') {
        data.staff = filter.staff;
    }

    sendingReport.value = report.key;
    router.post(
        admin.reports.send({ property: props.property.id, report: report.key })
            .url,
        data,
        {
            preserveScroll: true,
            onFinish: () => (sendingReport.value = null),
        },
    );
}

const deliveryStatusClass: Record<string, string> = {
    queued: 'text-amber-700 dark:text-amber-400',
    sent: 'text-emerald-700 dark:text-emerald-400',
    failed: 'text-red-600',
};

function toggleStatus(report: Report, event: Event): void {
    const checkbox = event.target as HTMLInputElement;

    router.put(
        admin.reports.status({
            property: props.property.id,
            report: report.key,
        }).url,
        { send_report: checkbox.checked },
        {
            preserveScroll: true,
            // A refused change leaves the props as they were, so put the tick back by hand.
            onFinish: () => {
                checkbox.checked =
                    props.reports.find((item) => item.key === report.key)
                        ?.settings.send_report ?? false;
            },
        },
    );
}

/* Select Columns modal */

const selectingColumnsFor = ref<Report | null>(null);

const columnVisibility = computed<Record<string, boolean>>(() =>
    Object.fromEntries(
        (selectingColumnsFor.value?.columns ?? []).map((column) => [
            column.key,
            !selectingColumnsFor.value?.settings.hidden_columns.includes(
                column.key,
            ),
        ]),
    ),
);

function saveColumns(visibility: Record<string, boolean>): void {
    const report = selectingColumnsFor.value;

    if (!report) {
        return;
    }

    router.put(
        admin.reports.columns({
            property: props.property.id,
            report: report.key,
        }).url,
        {
            hidden_columns: report.columns
                .filter((column) => !visibility[column.key])
                .map((column) => column.key),
        },
        {
            preserveScroll: true,
            onSuccess: () => (selectingColumnsFor.value = null),
        },
    );
}

/* Edit modal */

const editing = ref<Report | null>(null);
const form = useForm<ReportSettings>({
    name: '',
    email_subject: '',
    heading: '',
    send_time: '05:30',
    send_report: false,
    same_as_internal: true,
    recipients: [],
    description: '',
    hidden_columns: [],
});

/** What each list held when the modal opened, so switching "same as internal" swaps lists. */
const ownRecipients = ref<string[]>([]);

function openEdit(report: Report): void {
    editing.value = report;
    ownRecipients.value = report.settings.same_as_internal
        ? []
        : [...report.settings.recipients];
    form.defaults({
        ...report.settings,
        recipients: [...report.settings.recipients],
    });
    form.reset();
    form.clearErrors();
}

function closeEdit(): void {
    editing.value = null;
}

function toggleSameAsInternal(): void {
    if (form.same_as_internal) {
        ownRecipients.value = [...form.recipients];
        form.recipients = [...props.internalRecipients];
    } else {
        form.recipients = [...ownRecipients.value];
    }
}

function addRecipient(): void {
    form.recipients.push('');
}

function removeRecipient(index: number): void {
    form.recipients.splice(index, 1);
}

function saveEdit(): void {
    if (!editing.value) {
        return;
    }

    form.transform((data) => ({
        ...data,
        recipients: data.recipients
            .map((email) => email.trim())
            .filter((email) => email !== ''),
    })).put(
        admin.reports.update({
            property: props.property.id,
            report: editing.value.key,
        }).url,
        { preserveScroll: true, onSuccess: () => closeEdit() },
    );
}

/* Send time as 12-hour hour / minute / AM-PM spinners over the 24-hour "HH:MM" value. */

const sendTime = computed(() => {
    const [hour, minute] = form.send_time.split(':').map(Number);

    return { hour: hour % 12 === 0 ? 12 : hour % 12, minute, pm: hour >= 12 };
});

function setSendTime(hour12: number, minute: number, pm: boolean): void {
    const hour = (hour12 % 12) + (pm ? 12 : 0);
    form.send_time = `${String(hour).padStart(2, '0')}:${String(minute).padStart(2, '0')}`;
}

function stepHour(step: number): void {
    setSendTime(
        ((sendTime.value.hour - 1 + step + 12) % 12) + 1,
        sendTime.value.minute,
        sendTime.value.pm,
    );
}

function stepMinute(step: number): void {
    setSendTime(
        sendTime.value.hour,
        (sendTime.value.minute + step + 60) % 60,
        sendTime.value.pm,
    );
}

function toggleMeridiem(): void {
    setSendTime(sendTime.value.hour, sendTime.value.minute, !sendTime.value.pm);
}

function recipientError(index: number): string | undefined {
    return (form.errors as Record<string, string>)[`recipients.${index}`];
}

const thClass =
    'px-3 py-2 text-left text-xs font-semibold text-[#1b1b18] dark:text-[#EDEDEC]';
const tdClass = 'px-3 py-1.5 align-middle';
const inputClass =
    'rounded-md border border-[#e3e3e0] bg-white px-3 py-1.5 text-sm dark:border-[#3E3E3A] dark:bg-[#161615] dark:text-[#EDEDEC]';
const smallInputClass =
    'rounded-md border border-[#e3e3e0] bg-white px-2 py-1 text-xs dark:border-[#3E3E3A] dark:bg-[#161615] dark:text-[#EDEDEC]';
const labelClass = 'text-sm text-[#1b1b18] dark:text-[#EDEDEC]';
const spinButtonClass =
    'flex h-6 w-10 items-center justify-center rounded border border-[#e3e3e0] text-xs text-[#706f6c] hover:bg-[#f5f5f3] dark:border-[#3E3E3A] dark:hover:bg-[#1f1f1d]';
const spinValueClass =
    'flex h-7 w-10 items-center justify-center rounded border border-[#e3e3e0] text-xs dark:border-[#3E3E3A] dark:text-[#EDEDEC]';
</script>

<template>
    <Head :title="`Reports: ${property.name}`" />

    <div class="mb-6 flex items-center justify-between">
        <h1
            class="font-display text-2xl font-semibold text-[#1b1b18] dark:text-[#EDEDEC]"
        >
            Reports
        </h1>
        <div class="flex gap-2">
            <button
                type="button"
                class="rounded-md border border-[#e3e3e0] px-4 py-2 text-sm font-medium text-[#1b1b18] dark:border-[#3E3E3A] dark:text-[#EDEDEC]"
                @click="deliveriesOpen = !deliveriesOpen"
            >
                E-mail Log
            </button>
            <button
                type="button"
                class="rounded-md border border-[#e3e3e0] px-4 py-2 text-sm font-medium text-[#1b1b18] dark:border-[#3E3E3A] dark:text-[#EDEDEC]"
                @click="activityLogsOpen = !activityLogsOpen"
            >
                Activity Logs
            </button>
        </div>
    </div>

    <div
        v-if="deliveriesOpen"
        class="mb-6 flex flex-col gap-2 rounded-lg border border-[#e3e3e0] p-4 dark:border-[#3E3E3A]"
    >
        <h3
            class="text-sm font-semibold tracking-wide text-[#706f6c] uppercase dark:text-[#A1A09A]"
        >
            E-mail Log
        </h3>
        <p
            v-if="deliveries.length === 0"
            class="text-sm text-[#706f6c] dark:text-[#A1A09A]"
        >
            No report has been e-mailed yet.
        </p>
        <ul
            v-else
            class="flex max-h-72 flex-col gap-1.5 overflow-y-auto text-sm"
        >
            <li
                v-for="delivery in deliveries"
                :key="delivery.id"
                class="text-[#1b1b18] dark:text-[#EDEDEC]"
            >
                {{ delivery.report }} ({{ delivery.period }}) to
                {{ delivery.recipients.join(', ') }} —
                <span :class="deliveryStatusClass[delivery.status]">{{
                    delivery.status === 'queued'
                        ? 'Queued'
                        : delivery.status === 'sent'
                          ? 'Sent'
                          : 'Failed'
                }}</span>
                <span v-if="delivery.error" class="text-xs text-red-600"
                    >: {{ delivery.error }}</span
                >
                <span class="text-xs text-[#706f6c] dark:text-[#A1A09A]"
                    >—
                    {{
                        delivery.scheduled
                            ? 'Scheduled'
                            : (delivery.user ?? 'System')
                    }}, {{ propertyTime.dateTime(delivery.at) }}</span
                >
            </li>
        </ul>
    </div>

    <div
        v-if="activityLogsOpen"
        class="mb-6 flex flex-col gap-2 rounded-lg border border-[#e3e3e0] p-4 dark:border-[#3E3E3A]"
    >
        <h3
            class="text-sm font-semibold tracking-wide text-[#706f6c] uppercase dark:text-[#A1A09A]"
        >
            Activity Logs
        </h3>
        <p
            v-if="activityLogs.length === 0"
            class="text-sm text-[#706f6c] dark:text-[#A1A09A]"
        >
            No activity yet.
        </p>
        <ul
            v-else
            class="flex max-h-72 flex-col gap-1.5 overflow-y-auto text-sm"
        >
            <li
                v-for="(log, index) in activityLogs"
                :key="index"
                class="text-[#1b1b18] dark:text-[#EDEDEC]"
            >
                {{ log.action }}
                <span class="text-xs text-[#706f6c] dark:text-[#A1A09A]"
                    >— {{ log.user ?? 'System' }},
                    {{ propertyTime.dateTime(log.at) }}</span
                >
            </li>
        </ul>
    </div>

    <p v-if="statusError" class="mb-3 text-sm text-red-600">
        {{ statusError }}
    </p>
    <p
        v-if="closedUntil !== null"
        class="mb-3 text-sm text-[#706f6c] dark:text-[#A1A09A]"
    >
        Restrict Reports is on: dated reports only cover business dates the
        night audit has closed<template v-if="closedUntil > '1970-01-01'">
            (up to {{ closedUntil }})</template
        ><template v-else> (none closed yet)</template>.
    </p>

    <div
        class="overflow-x-auto rounded-lg border border-[#e3e3e0] dark:border-[#3E3E3A]"
    >
        <table class="w-full text-sm">
            <thead class="bg-[#f5f5f3] dark:bg-[#1f1f1d]">
                <tr>
                    <th :class="thClass">Name</th>
                    <th :class="thClass">Edit</th>
                    <th :class="thClass">Status</th>
                    <th :class="thClass">Start Date</th>
                    <th :class="thClass">End Date</th>
                    <th :class="thClass">Select Staff</th>
                    <th :class="thClass">Select Columns</th>
                    <th :class="thClass">Download</th>
                </tr>
            </thead>
            <tbody>
                <tr
                    v-for="report in reports"
                    :key="report.key"
                    class="border-t border-[#e3e3e0] text-[#1b1b18] dark:border-[#3E3E3A] dark:text-[#EDEDEC]"
                >
                    <td :class="tdClass">{{ report.settings.name }}</td>
                    <td :class="tdClass">
                        <button
                            type="button"
                            class="text-[#706f6c] hover:text-[#1b1b18] dark:text-[#A1A09A] dark:hover:text-[#EDEDEC]"
                            :title="`Edit ${report.settings.name}`"
                            @click="openEdit(report)"
                        >
                            ✎
                        </button>
                    </td>
                    <td :class="tdClass">
                        <input
                            type="checkbox"
                            class="h-4 w-4"
                            :checked="report.settings.send_report"
                            :title="
                                report.settings.send_report
                                    ? 'Emailed daily'
                                    : 'Not emailed'
                            "
                            @change="toggleStatus(report, $event)"
                        />
                    </td>
                    <td :class="tdClass">
                        <input
                            v-if="report.dates !== 'none'"
                            v-model="filters[report.key].start"
                            type="date"
                            :max="maxDate(report.key)"
                            :class="smallInputClass"
                        />
                    </td>
                    <td :class="tdClass">
                        <input
                            v-if="report.dates === 'range'"
                            v-model="filters[report.key].end"
                            type="date"
                            :min="filters[report.key].start"
                            :max="maxDate(report.key)"
                            :class="smallInputClass"
                        />
                    </td>
                    <td :class="tdClass">
                        <select
                            v-if="report.staff"
                            v-model="filters[report.key].staff"
                            :class="smallInputClass"
                        >
                            <option value="">--Select--</option>
                            <option
                                v-for="user in staff"
                                :key="user.id"
                                :value="String(user.id)"
                            >
                                {{ user.name }}
                            </option>
                        </select>
                    </td>
                    <td :class="tdClass">
                        <button
                            v-if="report.columns.length > 0"
                            type="button"
                            class="text-[#706f6c] hover:text-[#1b1b18] dark:text-[#A1A09A] dark:hover:text-[#EDEDEC]"
                            :title="`Select columns for ${report.settings.name}`"
                            @click="selectingColumnsFor = report"
                        >
                            ✎
                            <span
                                v-if="report.settings.hidden_columns.length > 0"
                                class="text-xs"
                                >({{
                                    report.columns.length -
                                    report.settings.hidden_columns.length
                                }}/{{ report.columns.length }})</span
                            >
                        </button>
                    </td>
                    <td :class="tdClass">
                        <div class="flex gap-1.5">
                            <template
                                v-for="type in report.downloads"
                                :key="type"
                            >
                                <a
                                    v-if="
                                        report.available_downloads.includes(
                                            type,
                                        )
                                    "
                                    :href="downloadUrl(report, type)"
                                    class="rounded border border-[#16a34a] px-1.5 py-0.5 text-[10px] font-semibold text-[#16a34a] hover:bg-[#16a34a] hover:text-white"
                                    :title="`Download ${report.settings.name}`"
                                >
                                    {{ downloadLabels[type] }}
                                </a>
                                <span
                                    v-else
                                    class="cursor-not-allowed rounded border border-[#e3e3e0] px-1.5 py-0.5 text-[10px] font-semibold text-[#a1a09a] dark:border-[#3E3E3A]"
                                    title="This download isn't built yet"
                                >
                                    {{ downloadLabels[type] }}
                                </span>
                            </template>
                            <button
                                v-if="report.available_downloads.length > 0"
                                type="button"
                                :disabled="sendingReport !== null"
                                class="rounded border border-[#337ab7] px-1.5 py-0.5 text-[10px] font-semibold text-[#337ab7] hover:bg-[#337ab7] hover:text-white disabled:opacity-50"
                                :title="`E-mail ${report.settings.name} now to its recipients`"
                                @click="emailNow(report)"
                            >
                                {{
                                    sendingReport === report.key
                                        ? '…'
                                        : 'E-MAIL'
                                }}
                            </button>
                        </div>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>

    <TableColumnSettingsModal
        :show="selectingColumnsFor !== null"
        title="Select columns for excel download"
        :columns="selectingColumnsFor?.columns ?? []"
        :model-value="columnVisibility"
        @close="selectingColumnsFor = null"
        @save="saveColumns"
    />

    <div
        v-if="editing"
        class="fixed inset-0 z-50 flex items-center justify-center bg-black/30 p-4"
        @click.self="closeEdit"
    >
        <form
            class="flex max-h-[90vh] w-full max-w-xl flex-col rounded-lg border border-[#e3e3e0] bg-white dark:border-[#3E3E3A] dark:bg-[#161615]"
            @submit.prevent="saveEdit"
        >
            <div
                class="flex items-center justify-between border-b border-[#e3e3e0] px-6 py-4 dark:border-[#3E3E3A]"
            >
                <h2
                    class="font-display text-lg font-semibold text-[#1b1b18] dark:text-[#EDEDEC]"
                >
                    {{ editing.settings.name }}
                </h2>
                <button
                    type="button"
                    class="text-sm text-[#706f6c] hover:text-[#1b1b18] dark:text-[#A1A09A] dark:hover:text-[#EDEDEC]"
                    @click="closeEdit"
                >
                    ✕
                </button>
            </div>

            <div class="flex flex-col gap-4 overflow-y-auto px-6 py-4">
                <div
                    class="grid grid-cols-[minmax(0,1fr)_minmax(0,1fr)] items-center gap-x-4 gap-y-3"
                >
                    <label for="report_name" :class="labelClass">Name :</label>
                    <div>
                        <input
                            id="report_name"
                            v-model="form.name"
                            type="text"
                            :class="[inputClass, 'w-full']"
                        />
                        <p
                            v-if="form.errors.name"
                            class="mt-1 text-xs text-red-600"
                        >
                            {{ form.errors.name }}
                        </p>
                    </div>

                    <label for="report_subject" :class="labelClass"
                        >Define the email subject :</label
                    >
                    <div>
                        <input
                            id="report_subject"
                            v-model="form.email_subject"
                            type="text"
                            :class="[inputClass, 'w-full']"
                        />
                        <p
                            v-if="form.errors.email_subject"
                            class="mt-1 text-xs text-red-600"
                        >
                            {{ form.errors.email_subject }}
                        </p>
                    </div>

                    <label for="report_heading" :class="labelClass"
                        >Define the heading :</label
                    >
                    <div>
                        <input
                            id="report_heading"
                            v-model="form.heading"
                            type="text"
                            :class="[inputClass, 'w-full']"
                        />
                        <p
                            v-if="form.errors.heading"
                            class="mt-1 text-xs text-red-600"
                        >
                            {{ form.errors.heading }}
                        </p>
                    </div>

                    <span :class="labelClass">Report send time</span>
                    <div>
                        <div class="flex items-center gap-2">
                            <div class="flex flex-col items-center gap-1">
                                <button
                                    type="button"
                                    :class="spinButtonClass"
                                    aria-label="Hour up"
                                    @click="stepHour(1)"
                                >
                                    ▲
                                </button>
                                <span :class="spinValueClass">{{
                                    String(sendTime.hour).padStart(2, '0')
                                }}</span>
                                <button
                                    type="button"
                                    :class="spinButtonClass"
                                    aria-label="Hour down"
                                    @click="stepHour(-1)"
                                >
                                    ▼
                                </button>
                            </div>
                            <div class="flex flex-col items-center gap-1">
                                <button
                                    type="button"
                                    :class="spinButtonClass"
                                    aria-label="Minute up"
                                    @click="stepMinute(1)"
                                >
                                    ▲
                                </button>
                                <span :class="spinValueClass">{{
                                    String(sendTime.minute).padStart(2, '0')
                                }}</span>
                                <button
                                    type="button"
                                    :class="spinButtonClass"
                                    aria-label="Minute down"
                                    @click="stepMinute(-1)"
                                >
                                    ▼
                                </button>
                            </div>
                            <div class="flex flex-col items-center gap-1">
                                <button
                                    type="button"
                                    :class="spinButtonClass"
                                    aria-label="AM / PM"
                                    @click="toggleMeridiem"
                                >
                                    ▲
                                </button>
                                <span :class="spinValueClass">{{
                                    sendTime.pm ? 'PM' : 'AM'
                                }}</span>
                                <button
                                    type="button"
                                    :class="spinButtonClass"
                                    aria-label="AM / PM"
                                    @click="toggleMeridiem"
                                >
                                    ▼
                                </button>
                            </div>
                            <span
                                class="text-xs text-[#706f6c] dark:text-[#A1A09A]"
                                >{{ propertyTime.timeZone }}</span
                            >
                        </div>
                        <p
                            v-if="form.errors.send_time"
                            class="mt-1 text-xs text-red-600"
                        >
                            {{ form.errors.send_time }}
                        </p>
                    </div>

                    <label for="report_send" :class="labelClass"
                        >Do you wish to send this report :</label
                    >
                    <div>
                        <input
                            id="report_send"
                            v-model="form.send_report"
                            type="checkbox"
                            class="h-4 w-4"
                        />
                    </div>

                    <label for="report_same_as_internal" :class="labelClass"
                        >Enable if report recipients are same as internal
                        recipients :</label
                    >
                    <div>
                        <input
                            id="report_same_as_internal"
                            v-model="form.same_as_internal"
                            type="checkbox"
                            class="h-4 w-4"
                            @change="toggleSameAsInternal"
                        />
                    </div>
                </div>

                <div class="flex flex-col gap-2">
                    <button
                        type="button"
                        class="self-start text-sm font-medium text-blue-600 hover:underline dark:text-blue-400"
                        @click="addRecipient"
                    >
                        + Add
                        {{ form.same_as_internal ? 'Internal' : '' }} Email
                        Recipient
                    </button>
                    <p
                        v-if="form.same_as_internal"
                        class="text-xs text-[#706f6c] dark:text-[#A1A09A]"
                    >
                        Internal recipients are shared by every report that uses
                        them; changes here apply to all of them.
                    </p>
                    <div
                        v-for="(email, index) in form.recipients"
                        :key="index"
                        class="rounded-md border border-[#e3e3e0] bg-[#f5f5f3] px-3 py-2 dark:border-[#3E3E3A] dark:bg-[#1f1f1d]"
                    >
                        <div class="flex items-center gap-2">
                            <label
                                :for="`recipient_${index}`"
                                class="text-sm text-[#1b1b18] dark:text-[#EDEDEC]"
                                >Email ID:</label
                            >
                            <input
                                :id="`recipient_${index}`"
                                v-model="form.recipients[index]"
                                type="email"
                                :class="[inputClass, 'flex-1']"
                            />
                            <button
                                type="button"
                                class="px-2 text-sm text-[#706f6c] hover:text-red-600"
                                :aria-label="`Remove ${email || 'recipient'}`"
                                @click="removeRecipient(index)"
                            >
                                ✕
                            </button>
                        </div>
                        <p
                            v-if="recipientError(index)"
                            class="mt-1 text-xs text-red-600"
                        >
                            {{ recipientError(index) }}
                        </p>
                    </div>
                    <p
                        v-if="form.errors.recipients"
                        class="text-xs text-red-600"
                    >
                        {{ form.errors.recipients }}
                    </p>
                </div>

                <div class="flex flex-col gap-1">
                    <label for="report_description" :class="labelClass"
                        >Description :</label
                    >
                    <textarea
                        id="report_description"
                        v-model="form.description"
                        rows="3"
                        :class="inputClass"
                    />
                    <p
                        v-if="form.errors.description"
                        class="text-xs text-red-600"
                    >
                        {{ form.errors.description }}
                    </p>
                </div>
            </div>

            <div
                class="flex gap-2 border-t border-[#e3e3e0] px-6 py-4 dark:border-[#3E3E3A]"
            >
                <button
                    type="submit"
                    :disabled="form.processing"
                    class="rounded-md bg-[#1b1b18] px-5 py-2 text-sm font-medium text-white disabled:opacity-60 dark:bg-[#EDEDEC] dark:text-[#1b1b18]"
                >
                    {{ form.processing ? 'Saving…' : 'Save' }}
                </button>
                <button
                    type="button"
                    class="rounded-md border border-[#e3e3e0] px-4 py-2 text-sm font-medium text-[#1b1b18] dark:border-[#3E3E3A] dark:text-[#EDEDEC]"
                    @click="openEdit(editing)"
                >
                    ↻ Reset
                </button>
            </div>
        </form>
    </div>
</template>
