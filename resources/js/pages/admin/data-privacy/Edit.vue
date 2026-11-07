<script setup lang="ts">
import AdminLayout from '@/layouts/AdminLayout.vue';
import admin from '@/routes/admin';
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import { ref } from 'vue';

defineOptions({ layout: AdminLayout });

type Retention = {
    guest_data_months: number | null;
    id_document_days: number | null;
    activity_log_months: number | null;
};
type Export = {
    id: number;
    status: 'queued' | 'ready' | 'failed' | 'expired';
    size: number | null;
    by: string | null;
    requested_at: string | null;
    expires_at: string | null;
    error: string | null;
};

const props = defineProps<{
    retention: Retention;
    exports: Export[];
    deletion: {
        id: number;
        by: string | null;
        scheduled_for: string | null;
    } | null;
    graceDays: number;
    exportDays: number;
    canDelete: boolean;
    activityLogs: {
        description: string;
        user: string | null;
        at: string | null;
    }[];
}>();

const page = usePage();
const accountName =
    page.props.businessAccount?.name ?? (page.props.name as string);

const retentionForm = useForm({ ...props.retention });
const deletionForm = useForm({ password: '', confirmation: '' });
const deleteOpen = ref(false);
const activityLogsOpen = ref(false);

function saveRetention(): void {
    retentionForm
        .transform((data) =>
            Object.fromEntries(
                Object.entries(data).map(([key, value]) => [
                    key,
                    value === null || String(value) === ''
                        ? null
                        : Number(value),
                ]),
            ),
        )
        .put(admin.dataPrivacy.retention.update().url, {
            preserveScroll: true,
        });
}

function requestExport(): void {
    router.post(
        admin.dataPrivacy.exports.store().url,
        {},
        { preserveScroll: true },
    );
}

function scheduleDeletion(): void {
    deletionForm.post(admin.dataPrivacy.deletion.store().url, {
        preserveScroll: true,
        onSuccess: () => ((deleteOpen.value = false), deletionForm.reset()),
    });
}

function cancelDeletion(): void {
    if (props.deletion) {
        router.delete(
            admin.dataPrivacy.deletion.destroy(props.deletion.id).url,
            { preserveScroll: true },
        );
    }
}

const date = (value: string | null): string =>
    value
        ? new Date(value).toLocaleDateString(undefined, {
              day: 'numeric',
              month: 'short',
              year: 'numeric',
          })
        : '—';
const size = (bytes: number | null): string =>
    bytes === null
        ? ''
        : bytes > 1048576
          ? `${(bytes / 1048576).toFixed(1)} MB`
          : `${Math.max(1, Math.round(bytes / 1024))} KB`;

const statusClass: Record<Export['status'], string> = {
    queued: 'bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-300',
    ready: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300',
    failed: 'bg-red-100 text-red-700 dark:bg-red-950 dark:text-red-300',
    expired: 'bg-[#f1f1ef] text-[#706f6c] dark:bg-[#1C1C1A]',
};

const sectionClass =
    'flex flex-col gap-3 rounded-lg border border-[#e3e3e0] p-4 dark:border-[#3E3E3A]';
const labelClass =
    'flex flex-col gap-1 text-sm font-medium text-[#1b1b18] dark:text-[#EDEDEC]';
const inputClass =
    'w-40 rounded-md border border-[#e3e3e0] bg-white px-3 py-1.5 text-sm dark:border-[#3E3E3A] dark:bg-[#161615] dark:text-[#EDEDEC]';
const hintClass = 'text-xs font-normal text-[#706f6c] dark:text-[#A1A09A]';
</script>

<template>
    <Head title="Data & privacy" />

    <div class="mb-6 flex flex-wrap items-center justify-between gap-3">
        <h1
            class="font-display text-2xl font-semibold text-[#1b1b18] dark:text-[#EDEDEC]"
        >
            Data &amp; privacy
        </h1>
        <button
            type="button"
            class="rounded-md border border-[#e3e3e0] px-4 py-2 text-sm font-medium text-[#1b1b18] dark:border-[#3E3E3A] dark:text-[#EDEDEC]"
            @click="activityLogsOpen = !activityLogsOpen"
        >
            Activity Logs
        </button>
    </div>

    <div
        v-if="activityLogsOpen"
        class="mb-6 rounded-lg border border-[#e3e3e0] p-4 dark:border-[#3E3E3A]"
    >
        <p
            v-if="activityLogs.length === 0"
            class="text-sm text-[#706f6c] dark:text-[#A1A09A]"
        >
            Nothing yet.
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
                {{ log.description }}
                <span class="text-xs text-[#706f6c] dark:text-[#A1A09A]"
                    >— {{ log.user ?? 'System' }}, {{ date(log.at) }}</span
                >
            </li>
        </ul>
    </div>

    <div
        v-if="deletion"
        class="mb-6 rounded-lg border border-red-300 bg-red-50 p-4 text-sm text-red-800 dark:border-red-900 dark:bg-red-950/40 dark:text-red-200"
        role="alert"
        data-test="deletion-scheduled"
    >
        <p class="font-semibold">
            {{ accountName }} and all its data will be deleted on
            {{ date(deletion.scheduled_for) }}.
        </p>
        <p class="mt-1">
            Requested by {{ deletion.by ?? 'an administrator' }}. Download an
            export before then if you need a copy.
        </p>
        <button
            v-if="canDelete"
            type="button"
            class="mt-3 rounded-md border border-red-400 bg-white px-3 py-1.5 text-sm font-semibold text-red-700 dark:bg-transparent"
            data-test="deletion-cancel"
            @click="cancelDeletion"
        >
            Cancel the deletion
        </button>
    </div>

    <div class="flex max-w-3xl flex-col gap-6">
        <form :class="sectionClass" @submit.prevent="saveRetention">
            <div>
                <h2
                    class="text-base font-semibold text-[#1b1b18] dark:text-[#EDEDEC]"
                >
                    Retention
                </h2>
                <p :class="hintClass">
                    How long personal data is kept. Applied every night. Leave a
                    field empty to keep that data. Bookings, invoices and
                    payments are always kept (tax law); erased guests keep them
                    under an anonymous name.
                </p>
            </div>
            <label :class="labelClass">
                Erase a guest's personal data (months after their last stay)
                <input
                    v-model="retentionForm.guest_data_months"
                    type="number"
                    min="6"
                    max="240"
                    placeholder="Keep"
                    :class="inputClass"
                    data-test="retention-guest-months"
                />
                <span :class="hintClass"
                    >Name, contact details, documents and preferences. Guests
                    with a booking still to come are skipped.</span
                >
                <span
                    v-if="retentionForm.errors.guest_data_months"
                    class="text-xs text-red-600"
                    >{{ retentionForm.errors.guest_data_months }}</span
                >
            </label>
            <label :class="labelClass">
                Delete web check-in ID documents and signatures (days after
                check-out)
                <input
                    v-model="retentionForm.id_document_days"
                    type="number"
                    min="1"
                    max="3650"
                    placeholder="Keep"
                    :class="inputClass"
                    data-test="retention-id-days"
                />
                <span
                    v-if="retentionForm.errors.id_document_days"
                    class="text-xs text-red-600"
                    >{{ retentionForm.errors.id_document_days }}</span
                >
            </label>
            <label :class="labelClass">
                Delete activity logs (months)
                <input
                    v-model="retentionForm.activity_log_months"
                    type="number"
                    min="12"
                    max="240"
                    placeholder="Keep"
                    :class="inputClass"
                    data-test="retention-log-months"
                />
                <span
                    v-if="retentionForm.errors.activity_log_months"
                    class="text-xs text-red-600"
                    >{{ retentionForm.errors.activity_log_months }}</span
                >
            </label>
            <div>
                <button
                    type="submit"
                    :disabled="retentionForm.processing"
                    class="rounded-md bg-[#ea580c] px-4 py-2 text-sm font-semibold text-white hover:bg-[#c2410c] disabled:opacity-50"
                    data-test="retention-save"
                >
                    Save retention
                </button>
            </div>
        </form>

        <section :class="sectionClass">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h2
                        class="text-base font-semibold text-[#1b1b18] dark:text-[#EDEDEC]"
                    >
                        Export all data
                    </h2>
                    <p :class="hintClass">
                        A ZIP with a CSV per table (bookings, guests, invoices,
                        payments, settings…) and the uploaded files.
                        Downloadable for {{ exportDays }} days.
                    </p>
                </div>
                <button
                    type="button"
                    class="rounded-md border border-[#ea580c] px-4 py-2 text-sm font-semibold text-[#ea580c] hover:bg-orange-50"
                    data-test="export-request"
                    @click="requestExport"
                >
                    Export data
                </button>
            </div>
            <p v-if="page.props.errors?.export" class="text-xs text-red-600">
                {{ page.props.errors.export }}
            </p>
            <table v-if="exports.length" class="w-full text-left text-sm">
                <thead
                    class="text-xs text-[#706f6c] uppercase dark:text-[#A1A09A]"
                >
                    <tr>
                        <th class="py-1">Requested</th>
                        <th class="py-1">By</th>
                        <th class="py-1">Status</th>
                        <th class="py-1"></th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="item in exports"
                        :key="item.id"
                        class="border-t border-[#e3e3e0] dark:border-[#3E3E3A] dark:text-[#EDEDEC]"
                        :data-test="`export-${item.id}`"
                    >
                        <td class="py-1.5">{{ date(item.requested_at) }}</td>
                        <td class="py-1.5">{{ item.by ?? '—' }}</td>
                        <td class="py-1.5">
                            <span
                                class="rounded px-2 py-0.5 text-xs capitalize"
                                :class="statusClass[item.status]"
                                :title="item.error ?? ''"
                                >{{ item.status }}</span
                            >
                        </td>
                        <td class="py-1.5 text-right">
                            <a
                                v-if="item.status === 'ready'"
                                :href="
                                    admin.dataPrivacy.exports.download(item.id)
                                        .url
                                "
                                class="text-xs font-semibold text-[#ea580c]"
                                >Download {{ size(item.size) }}</a
                            >
                            <span
                                v-if="item.status === 'ready'"
                                class="ml-2 text-xs text-[#706f6c]"
                                >until {{ date(item.expires_at) }}</span
                            >
                        </td>
                    </tr>
                </tbody>
            </table>
        </section>

        <section
            v-if="canDelete && !deletion"
            class="flex flex-col gap-3 rounded-lg border border-red-300 p-4 dark:border-red-900"
        >
            <div>
                <h2
                    class="text-base font-semibold text-red-700 dark:text-red-300"
                >
                    Delete the account
                </h2>
                <p :class="hintClass">
                    Deletes {{ accountName }}: every property, booking, guest,
                    invoice, payment, user and uploaded file. It happens
                    {{ graceDays }} days after you ask; until then it can be
                    cancelled. Export your data first: it can't be recovered
                    afterwards.
                </p>
            </div>
            <div v-if="!deleteOpen">
                <button
                    type="button"
                    class="rounded-md border border-red-400 px-4 py-2 text-sm font-semibold text-red-700 dark:text-red-300"
                    data-test="deletion-open"
                    @click="deleteOpen = true"
                >
                    Delete the account…
                </button>
            </div>
            <form
                v-else
                class="flex flex-col gap-3"
                @submit.prevent="scheduleDeletion"
            >
                <label :class="labelClass">
                    Your password
                    <input
                        v-model="deletionForm.password"
                        type="password"
                        autocomplete="current-password"
                        required
                        :class="inputClass"
                        class="w-64"
                        data-test="deletion-password"
                    />
                    <span
                        v-if="deletionForm.errors.password"
                        class="text-xs text-red-600"
                        >{{ deletionForm.errors.password }}</span
                    >
                </label>
                <label :class="labelClass">
                    Type DELETE to confirm
                    <input
                        v-model="deletionForm.confirmation"
                        type="text"
                        required
                        :class="inputClass"
                        data-test="deletion-confirmation"
                    />
                    <span
                        v-if="deletionForm.errors.confirmation"
                        class="text-xs text-red-600"
                        >{{ deletionForm.errors.confirmation }}</span
                    >
                </label>
                <div class="flex gap-2">
                    <button
                        type="submit"
                        :disabled="
                            deletionForm.processing ||
                            deletionForm.confirmation !== 'DELETE'
                        "
                        class="rounded-md bg-red-600 px-4 py-2 text-sm font-semibold text-white disabled:opacity-50"
                        data-test="deletion-submit"
                    >
                        Schedule deletion in {{ graceDays }} days
                    </button>
                    <button
                        type="button"
                        class="rounded-md border border-[#e3e3e0] px-4 py-2 text-sm dark:border-[#3E3E3A] dark:text-[#EDEDEC]"
                        @click="deleteOpen = false"
                    >
                        Cancel
                    </button>
                </div>
            </form>
        </section>
    </div>
</template>
