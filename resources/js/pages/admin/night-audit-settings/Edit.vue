<script setup lang="ts">
import AdminLayout from '@/layouts/AdminLayout.vue';
import { usePropertyTime } from '@/lib/usePropertyTime';
import admin from '@/routes/admin';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';

defineOptions({ layout: AdminLayout });

type Settings = {
    rollover_type: string;
    current_rollover_date: string;
    default_rollover_time: string;
    pre_rollover_notification: number | null;
    restrict_reports: boolean;
    validate_excess_payment: boolean;
    freeze_past_dated_data: boolean;
    payment_reconciliation: boolean;
    pos_rollover: boolean;
    require_cashier_shift: boolean;
    excess_payment_tolerance: number;
    validate_invoice: boolean;
    invoice_start_date: string | null;
    pos_rollover_start_date: string | null;
    room_rollover_start_date: string | null;
};

const props = defineProps<{
    property: { id: number; name: string };
    settings: Settings;
    rolloverTypes: { value: string; label: string }[];
    activityLogs: { action: string; user: string | null; at: string | null }[];
}>();

const propertyTime = usePropertyTime();
const activityLogsOpen = ref(false);

const form = useForm<Settings>({ ...props.settings });

type Field = { key: keyof Settings; label: string; help: string };

const checkboxes: Field[] = [
    {
        key: 'restrict_reports',
        label: 'Restrict Reports',
        help: 'Dated reports (downloads and e-mails) only cover business dates the night audit has closed. Scheduled e-mails wait until the day is closed. Inventory availability and the housekeeping report are not limited.',
    },
    {
        key: 'validate_excess_payment',
        label: 'Validate Excess Payment',
        help: 'The night audit stops while a booking staying that day has been paid more than its bill (beyond the tolerance below).',
    },
    {
        key: 'freeze_past_dated_data',
        label: 'Freeze all past dated data',
        help: 'Once the night audit closes a day: stays that ended by then and payments dated then can no longer be changed, and nothing new can be dated into a closed day.',
    },
    {
        key: 'payment_reconciliation',
        label: 'Payment Reconciliation Flag',
        help: 'The night audit only closes a day once every payment of that day is marked reconciled (Night audits page).',
    },
    {
        key: 'pos_rollover',
        label: 'Pos Roll Over Flag',
        help: 'POS bills take the night audit business date, and the day does not close while a POS bill is still open.',
    },
    {
        key: 'require_cashier_shift',
        label: 'Require Open Cashier Shift',
        help: 'Staff can only record a payment while they have a cashier shift open at the property (Cashier Shift page), so every receipt is counted in a shift.',
    },
];

const help = {
    rollover_type:
        'Night Audit Off: the day closes only when you run the night audit. Automated Night Audit: it closes by itself at the roll-over time.',
    current_rollover_date:
        'The business date the property is on — the next one the night audit closes. It moves on by itself after each night audit.',
    default_rollover_time:
        'Property local time on the next day when the business date closes automatically (e.g. 02:00 closes 30/09 at 2 AM on 01/10).',
    pre_rollover_notification:
        'With Automated Night Audit: this many minutes before the roll-over, every admin page shows a banner with the arrivals still to check in (they become no-shows) and payments still to reconcile.',
    excess_payment_tolerance:
        'Overpayment up to this amount is ignored by "Validate Excess Payment".',
    validate_invoice:
        'The night audit stops while a booking checked out that day has no invoice, or one whose amount no longer matches its bill. The Night audits page lists them and can generate or update the invoices.',
    invoice_start_date:
        'Invoice validation covers check-outs from this date up to the day being closed, so older ones are not checked. Blank: only the day being closed.',
    pos_rollover_start_date:
        'Open POS bills from this business date on keep the night audit from closing.',
    room_rollover_start_date:
        'No-shows are only marked for arrivals on or after this date, so older unchecked bookings stay as they are.',
};

function submit(): void {
    form.put(admin.nightAuditSettings.update(props.property.id).url, {
        preserveScroll: true,
    });
}

const labelClass =
    'flex items-center gap-1.5 text-sm font-semibold text-[#1b1b18] dark:text-[#EDEDEC]';
const inputClass =
    'rounded-md border border-[#e3e3e0] bg-white px-3 py-1.5 text-sm dark:border-[#3E3E3A] dark:bg-[#161615] dark:text-[#EDEDEC]';
</script>

<template>
    <Head :title="`Night Audit Settings: ${property.name}`" />

    <div class="mb-6 flex items-center justify-between">
        <h1
            class="font-display text-2xl font-semibold text-[#1b1b18] dark:text-[#EDEDEC]"
        >
            Rollover Configuration
        </h1>
        <div class="flex items-center gap-3">
            <button
                type="button"
                class="rounded-md border border-[#e3e3e0] px-4 py-2 text-sm font-medium text-[#1b1b18] dark:border-[#3E3E3A] dark:text-[#EDEDEC]"
                @click="activityLogsOpen = !activityLogsOpen"
            >
                Activity Logs
            </button>
            <Link
                :href="admin.nightAudits.edit(property.id).url"
                class="rounded-md border border-[#e3e3e0] px-4 py-2 text-sm font-medium text-[#1b1b18] dark:border-[#3E3E3A] dark:text-[#EDEDEC]"
            >
                Night audits
            </Link>
        </div>
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

    <form class="flex max-w-3xl flex-col gap-4" @submit.prevent="submit">
        <div
            class="grid grid-cols-[minmax(0,18rem)_auto_minmax(0,1fr)] items-center gap-x-3 gap-y-3"
        >
            <label for="rollover_type" :class="labelClass"
                >Rollover Types
                <span
                    :title="help.rollover_type"
                    class="cursor-help text-[#706f6c]"
                    >ⓘ</span
                ></label
            >
            <span>:</span>
            <div>
                <select
                    id="rollover_type"
                    v-model="form.rollover_type"
                    :class="[inputClass, 'w-64']"
                >
                    <option
                        v-for="type in rolloverTypes"
                        :key="type.value"
                        :value="type.value"
                    >
                        {{ type.label }}
                    </option>
                </select>
                <p
                    v-if="form.errors.rollover_type"
                    class="mt-1 text-xs text-red-600"
                >
                    {{ form.errors.rollover_type }}
                </p>
            </div>

            <label for="current_rollover_date" :class="labelClass"
                >Current Rollover Date
                <span
                    :title="help.current_rollover_date"
                    class="cursor-help text-[#706f6c]"
                    >ⓘ</span
                ></label
            >
            <span>:</span>
            <div>
                <input
                    id="current_rollover_date"
                    v-model="form.current_rollover_date"
                    type="date"
                    :class="inputClass"
                />
                <p
                    v-if="form.errors.current_rollover_date"
                    class="mt-1 text-xs text-red-600"
                >
                    {{ form.errors.current_rollover_date }}
                </p>
            </div>

            <label for="default_rollover_time" :class="labelClass"
                >Default Roll-over Time
                <span
                    :title="help.default_rollover_time"
                    class="cursor-help text-[#706f6c]"
                    >ⓘ</span
                ></label
            >
            <span>:</span>
            <div>
                <input
                    id="default_rollover_time"
                    v-model="form.default_rollover_time"
                    type="time"
                    :class="inputClass"
                />
                <span class="ml-2 text-xs text-[#706f6c] dark:text-[#A1A09A]">{{
                    propertyTime.timeZone
                }}</span>
                <p
                    v-if="form.errors.default_rollover_time"
                    class="mt-1 text-xs text-red-600"
                >
                    {{ form.errors.default_rollover_time }}
                </p>
            </div>

            <label for="pre_rollover_notification" :class="labelClass"
                >Pre-rollover Notification
                <span
                    :title="help.pre_rollover_notification"
                    class="cursor-help text-[#706f6c]"
                    >ⓘ</span
                ></label
            >
            <span>:</span>
            <div>
                <input
                    id="pre_rollover_notification"
                    v-model="form.pre_rollover_notification"
                    type="number"
                    min="0"
                    max="1440"
                    :class="[inputClass, 'w-24']"
                />
                <span class="ml-2 text-xs text-[#706f6c] dark:text-[#A1A09A]"
                    >minutes</span
                >
                <p
                    v-if="form.errors.pre_rollover_notification"
                    class="mt-1 text-xs text-red-600"
                >
                    {{ form.errors.pre_rollover_notification }}
                </p>
            </div>

            <template v-for="field in checkboxes" :key="field.key">
                <label :for="field.key" :class="labelClass"
                    >{{ field.label }}
                    <span :title="field.help" class="cursor-help text-[#706f6c]"
                        >ⓘ</span
                    ></label
                >
                <span>:</span>
                <div>
                    <input
                        :id="field.key"
                        v-model="form[field.key]"
                        type="checkbox"
                        class="h-4 w-4"
                    />
                </div>
            </template>

            <label for="excess_payment_tolerance" :class="labelClass"
                >Excess Payment Tolerance Value
                <span
                    :title="help.excess_payment_tolerance"
                    class="cursor-help text-[#706f6c]"
                    >ⓘ</span
                ></label
            >
            <span>:</span>
            <div>
                <input
                    id="excess_payment_tolerance"
                    v-model="form.excess_payment_tolerance"
                    type="number"
                    min="0"
                    step="0.01"
                    :class="[inputClass, 'w-28']"
                />
                <p
                    v-if="form.errors.excess_payment_tolerance"
                    class="mt-1 text-xs text-red-600"
                >
                    {{ form.errors.excess_payment_tolerance }}
                </p>
            </div>

            <label for="validate_invoice" :class="labelClass"
                >Validate Invoice
                <span
                    :title="help.validate_invoice"
                    class="cursor-help text-[#706f6c]"
                    >ⓘ</span
                ></label
            >
            <span>:</span>
            <div>
                <input
                    id="validate_invoice"
                    v-model="form.validate_invoice"
                    type="checkbox"
                    class="h-4 w-4"
                />
            </div>

            <label for="invoice_start_date" :class="labelClass"
                >Invoice Start Date Validation
                <span
                    :title="help.invoice_start_date"
                    class="cursor-help text-[#706f6c]"
                    >ⓘ</span
                ></label
            >
            <span>:</span>
            <div>
                <input
                    id="invoice_start_date"
                    v-model="form.invoice_start_date"
                    type="date"
                    :class="inputClass"
                />
            </div>

            <label for="pos_rollover_start_date" :class="labelClass"
                >POS Rollover Start Date
                <span
                    :title="help.pos_rollover_start_date"
                    class="cursor-help text-[#706f6c]"
                    >ⓘ</span
                ></label
            >
            <span>:</span>
            <div>
                <input
                    id="pos_rollover_start_date"
                    v-model="form.pos_rollover_start_date"
                    type="date"
                    :class="inputClass"
                />
            </div>

            <label for="room_rollover_start_date" :class="labelClass"
                >Room Rollover Start Date
                <span
                    :title="help.room_rollover_start_date"
                    class="cursor-help text-[#706f6c]"
                    >ⓘ</span
                ></label
            >
            <span>:</span>
            <div>
                <input
                    id="room_rollover_start_date"
                    v-model="form.room_rollover_start_date"
                    type="date"
                    :class="inputClass"
                />
                <p
                    v-if="form.errors.room_rollover_start_date"
                    class="mt-1 text-xs text-red-600"
                >
                    {{ form.errors.room_rollover_start_date }}
                </p>
            </div>
        </div>

        <div class="mt-4">
            <button
                type="submit"
                :disabled="form.processing"
                class="rounded-md bg-[#1b1b18] px-5 py-2 text-sm font-medium text-white disabled:opacity-60 dark:bg-[#EDEDEC] dark:text-[#1b1b18]"
            >
                {{ form.processing ? 'Saving…' : 'Save' }}
            </button>
        </div>
    </form>
</template>
