<script setup lang="ts">
import Modal from '@/components/Modal.vue';
import admin from '@/routes/admin';
import { router } from '@inertiajs/vue3';
import { ref, watch } from 'vue';

type BillData = {
    property: {
        name: string;
        address: string;
        statutory_ids: { label: string; value: string }[];
    };
    outlet: string;
    title: string;
    is_void: boolean;
    bill_number: string;
    date: string;
    business_date: string;
    table_number: string | null;
    guest_name: string | null;
    cashier: string | null;
    currency: string;
    lines: {
        name: string;
        quantity: number;
        unit_price: number;
        amount: number;
    }[];
    sub_total: number;
    discount: number;
    taxes: { label: string; amount: number }[];
    tax_total: number;
    total: number;
    settlement: { label: string; amount: number }[];
    notes: string | null;
    void_reason: string | null;
};

const props = defineProps<{
    propertyId: number;
    /** The bill to show; null closes the modal. */
    billId: number | null;
}>();

const emit = defineEmits<{ close: [] }>();

const bill = ref<{
    id: number;
    status: string;
    status_label: string;
    data: BillData;
    guest_email: string | null;
} | null>(null);
const loadError = ref('');
const email = ref('');
const emailError = ref('');
const emailSent = ref('');
const sending = ref(false);

watch(
    () => props.billId,
    async (id) => {
        bill.value = null;
        loadError.value = '';
        emailError.value = '';
        emailSent.value = '';

        if (id === null) {
            return;
        }

        try {
            const response = await fetch(
                admin.pos.bills.show({
                    property: props.propertyId,
                    outletBill: id,
                }).url,
                {
                    headers: { Accept: 'application/json' },
                    credentials: 'same-origin',
                },
            );

            if (!response.ok) {
                throw new Error(
                    response.status === 403
                        ? "You don't have permission to view POS bills."
                        : `The bill couldn't be loaded (${response.status}).`,
                );
            }

            bill.value = await response.json();
            email.value = bill.value?.guest_email ?? '';
        } catch (error) {
            loadError.value =
                error instanceof Error
                    ? error.message
                    : "The bill couldn't be loaded.";
        }
    },
    { immediate: true },
);

function money(value: number): string {
    return value.toLocaleString(undefined, {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    });
}

function pdfUrl(): string {
    return props.billId === null
        ? ''
        : admin.pos.bills.pdf({
              property: props.propertyId,
              outletBill: props.billId,
          }).url;
}

/** Prints the bill on A4 from a hidden frame (no pop-up). */
function print(): void {
    if (props.billId === null) {
        return;
    }

    const frame = document.createElement('iframe');
    frame.style.position = 'fixed';
    frame.style.width = '0';
    frame.style.height = '0';
    frame.style.border = '0';
    frame.src = admin.pos.bills.print(
        { property: props.propertyId, outletBill: props.billId },
        { query: { paper: 'a4', autoprint: 1 } },
    ).url;
    document.body.appendChild(frame);
    setTimeout(() => frame.remove(), 60_000);
}

function sendEmail(): void {
    if (props.billId === null) {
        return;
    }

    emailError.value = '';
    emailSent.value = '';
    router.post(
        admin.pos.bills.email({
            property: props.propertyId,
            outletBill: props.billId,
        }).url,
        { email: email.value },
        {
            preserveScroll: true,
            preserveState: true,
            onStart: () => (sending.value = true),
            onFinish: () => (sending.value = false),
            onSuccess: () =>
                (emailSent.value = `The bill will be e-mailed to ${email.value}.`),
            onError: (errors) =>
                (emailError.value =
                    errors.email ?? 'The bill could not be e-mailed.'),
        },
    );
}

const buttonClass =
    'rounded-md border border-[#e3e3e0] px-3 py-1.5 text-sm font-medium dark:border-[#3E3E3A] dark:text-[#EDEDEC]';
</script>

<template>
    <Modal
        :show="billId !== null"
        :title="
            bill
                ? `${bill.data.outlet} bill #${bill.data.bill_number}`
                : 'Outlet bill'
        "
        max-width="max-w-lg"
        @close="emit('close')"
    >
        <p v-if="loadError" class="text-sm text-red-600">{{ loadError }}</p>
        <p v-else-if="!bill" class="text-sm text-[#706f6c] dark:text-[#A1A09A]">
            Loading the bill…
        </p>
        <div
            v-else
            class="flex flex-col gap-3 text-sm text-[#1b1b18] dark:text-[#EDEDEC]"
        >
            <div
                class="flex flex-wrap items-center justify-between gap-2 text-xs text-[#706f6c] dark:text-[#A1A09A]"
            >
                <span
                    >{{ bill.data.date }} · business date
                    {{ bill.data.business_date }}</span
                >
                <span
                    class="rounded px-2 py-0.5"
                    :class="
                        bill.status === 'void'
                            ? 'bg-red-50 text-red-700 dark:bg-red-950 dark:text-red-300'
                            : 'bg-[#f5f5f4] dark:bg-[#1D1D1B]'
                    "
                    >{{ bill.status_label }}</span
                >
            </div>
            <p
                v-if="bill.data.guest_name || bill.data.table_number"
                class="text-xs text-[#706f6c] dark:text-[#A1A09A]"
            >
                <template v-if="bill.data.guest_name"
                    >Guest: {{ bill.data.guest_name }}</template
                >
                <template v-if="bill.data.table_number">
                    · Table {{ bill.data.table_number }}</template
                >
            </p>

            <table class="w-full text-left">
                <thead
                    class="text-xs text-[#706f6c] uppercase dark:text-[#A1A09A]"
                >
                    <tr>
                        <th class="py-1">Item</th>
                        <th class="py-1 text-right">Qty</th>
                        <th class="py-1 text-right">Rate</th>
                        <th class="py-1 text-right">Amount</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-[#e3e3e0] dark:divide-[#3E3E3A]">
                    <tr v-for="(line, index) in bill.data.lines" :key="index">
                        <td class="py-1.5">{{ line.name }}</td>
                        <td class="py-1.5 text-right">{{ line.quantity }}</td>
                        <td class="py-1.5 text-right">
                            {{ money(line.unit_price) }}
                        </td>
                        <td class="py-1.5 text-right">
                            {{ money(line.amount) }}
                        </td>
                    </tr>
                </tbody>
            </table>

            <dl
                class="grid grid-cols-2 gap-1 border-t border-[#e3e3e0] pt-2 dark:border-[#3E3E3A]"
            >
                <dt>Sub total</dt>
                <dd class="text-right">{{ money(bill.data.sub_total) }}</dd>
                <template v-if="bill.data.discount > 0">
                    <dt>Discount</dt>
                    <dd class="text-right">−{{ money(bill.data.discount) }}</dd>
                </template>
                <template v-for="tax in bill.data.taxes" :key="tax.label">
                    <dt>{{ tax.label }}</dt>
                    <dd class="text-right">{{ money(tax.amount) }}</dd>
                </template>
                <dt class="font-semibold">Total ({{ bill.data.currency }})</dt>
                <dd class="text-right font-semibold">
                    {{ money(bill.data.total) }}
                </dd>
                <template
                    v-for="item in bill.data.settlement"
                    :key="item.label"
                >
                    <dt class="text-[#706f6c] dark:text-[#A1A09A]">
                        {{ item.label }}
                    </dt>
                    <dd class="text-right text-[#706f6c] dark:text-[#A1A09A]">
                        {{ money(item.amount) }}
                    </dd>
                </template>
            </dl>
            <p
                v-if="bill.data.void_reason"
                class="text-xs text-red-700 dark:text-red-400"
            >
                Void: {{ bill.data.void_reason }}
            </p>

            <div class="flex flex-wrap gap-2">
                <a :href="pdfUrl()" :class="buttonClass">Download PDF</a>
                <button type="button" :class="buttonClass" @click="print">
                    Print
                </button>
            </div>

            <form class="flex flex-col gap-1" @submit.prevent="sendEmail">
                <label
                    for="outlet-bill-email"
                    class="text-xs font-medium text-[#706f6c] dark:text-[#A1A09A]"
                    >Send by e-mail</label
                >
                <div class="flex gap-2">
                    <input
                        id="outlet-bill-email"
                        v-model="email"
                        type="email"
                        required
                        placeholder="guest@example.com"
                        class="min-w-0 flex-1 rounded-md border border-[#e3e3e0] bg-white px-3 py-1.5 text-sm dark:border-[#3E3E3A] dark:bg-[#161615]"
                    />
                    <button
                        type="submit"
                        class="rounded-md bg-[#1b1b18] px-4 py-1.5 text-sm font-medium text-white disabled:opacity-40 dark:bg-[#EDEDEC] dark:text-[#1b1b18]"
                        :disabled="sending || !email"
                    >
                        Send
                    </button>
                </div>
                <span v-if="emailError" class="text-xs text-red-600">{{
                    emailError
                }}</span>
                <span
                    v-if="emailSent"
                    class="text-xs text-emerald-700 dark:text-emerald-400"
                    >{{ emailSent }}</span
                >
            </form>
        </div>
    </Modal>
</template>
