<script setup lang="ts">
import Modal from '@/components/Modal.vue';
import OutletBillModal from '@/components/OutletBillModal.vue';
import AdminLayout from '@/layouts/AdminLayout.vue';
import {
    connectPrinter,
    forgetPrinter,
    printFrom,
    qzPrinters,
    saveQzPrinter,
    savedPrinter,
    supportedKinds,
    type PrinterKind,
    type PrinterSlot,
} from '@/lib/escposPrinter';
import { useCan } from '@/lib/useCan';
import { usePropertyTime } from '@/lib/usePropertyTime';
import admin from '@/routes/admin';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';

defineOptions({ layout: AdminLayout });

type Item = {
    id: number;
    name: string;
    category: string;
    price: number | null;
    tax_rate: number;
};
type BillLine = {
    id: number;
    outlet_item_id: number | null;
    name: string;
    quantity: number;
    unit_price: number;
    amount: number;
    tax_rate: number;
    tax_amount: number;
};
type Kot = {
    id: number;
    kot_number: string;
    notes: string | null;
    is_cancellation: boolean;
    items: { outlet_item_id: number | null; name: string; quantity: number }[];
    created_at: string | null;
    printed_at: string | null;
};
type Bill = {
    id: number;
    bill_number: string;
    status: 'open' | 'posted' | 'settled' | 'void';
    status_label: string;
    guest_name: string | null;
    table_number: string | null;
    outlet_table_id: number | null;
    kots: Kot[];
    booking_ref_id?: string | null;
    receipt_number?: string | null;
    sub_total: number;
    discount: number;
    tax_total: number;
    total: number;
    notes: string | null;
    items: BillLine[];
    created_at: string | null;
    void_reason: string | null;
};
type InHouseRoom = {
    sub_booking_id: number;
    booking_id: number;
    booking_ref_id: string;
    room: string;
    category: string | null;
    guest: string | null;
    windows: { sub_folio_id: number; label: string }[];
};
type CartLine = {
    outlet_item_id: number;
    name: string;
    quantity: number;
    unit_price: number;
    tax_rate: number;
    price_overridden: boolean;
};
type PlanTable = {
    id: number;
    name: string;
    seats: number;
    area: string | null;
    pos_x: number;
    pos_y: number;
    shape: 'square' | 'round' | 'rect';
    open_bill_ids: number[];
};
type GuestOrder = {
    id: number;
    table: string | null;
    guest_name: string | null;
    notes: string | null;
    items: { name: string; quantity: number; unit_price: number }[];
    total: number;
    created_at: string | null;
};

const props = defineProps<{
    property: { id: number; name: string };
    businessDate: string;
    outlets: { id: number; name: string }[];
    outletId: number | null;
    printers: {
        paper: string;
        network: boolean;
        cash_drawer: boolean;
        kot_paper: string;
        kot_network: boolean;
    } | null;
    items: Item[];
    bills: Bill[];
    inHouseRooms: InHouseRoom[];
    paymentModes: string[];
    tables: PlanTable[];
    guestOrders: GuestOrder[];
    qrOrdering: boolean;
}>();

const can = useCan();
const page = usePage();
const propertyTime = usePropertyTime();

const search = ref('');
const activeBillId = ref<number | null>(null);
const cart = ref<CartLine[]>([]);
const guestName = ref('');
const tableNumber = ref('');
/** The plan's table a new bill is for. */
const tableId = ref<number | null>(null);
const discount = ref(0);
const dirty = ref(false);

const activeBill = computed(
    () => props.bills.find((bill) => bill.id === activeBillId.value) ?? null,
);
const openBills = computed(() =>
    props.bills.filter((bill) => bill.status === 'open'),
);
const closedBills = computed(() =>
    props.bills.filter((bill) => bill.status !== 'open'),
);

const itemsByCategory = computed(() => {
    const term = search.value.trim().toLowerCase();
    const groups = new Map<string, Item[]>();

    for (const item of props.items) {
        if (term !== '' && !item.name.toLowerCase().includes(term)) {
            continue;
        }

        groups.set(item.category, [...(groups.get(item.category) ?? []), item]);
    }

    return [...groups.entries()];
});

/** The server reprices the bill; this is the same sum for the screen. */
const totals = computed(() => {
    const subTotal = round(
        cart.value.reduce(
            (sum, line) => sum + line.unit_price * line.quantity,
            0,
        ),
    );
    const ratio =
        subTotal > 0
            ? (subTotal - Math.min(discount.value, subTotal)) / subTotal
            : 0;
    const tax = round(
        cart.value.reduce(
            (sum, line) =>
                sum +
                round((line.unit_price * line.quantity * line.tax_rate) / 100) *
                    ratio,
            0,
        ),
    );

    return {
        subTotal,
        tax,
        total: round(subTotal - Math.min(discount.value, subTotal) + tax),
    };
});

/** Errors the server reports on keys the form doesn't hold (payment.amount, bill). */
function errorOf(form: { errors: object }, key: string): string | undefined {
    return (form.errors as Record<string, string | undefined>)[key];
}

function round(value: number): number {
    return Math.round(value * 100) / 100;
}

function money(value: number): string {
    return value.toFixed(2);
}

function loadBill(bill: Bill | null): void {
    activeBillId.value = bill?.id ?? null;
    guestName.value = bill?.guest_name ?? '';
    tableNumber.value = bill?.table_number ?? '';
    tableId.value = bill?.outlet_table_id ?? null;
    discount.value = bill?.discount ?? 0;
    cart.value = (bill?.items ?? [])
        .filter((line) => line.outlet_item_id !== null)
        .map((line) => {
            const catalogue = props.items.find(
                (item) => item.id === line.outlet_item_id,
            );

            return {
                outlet_item_id: line.outlet_item_id as number,
                name: line.name,
                quantity: line.quantity,
                unit_price: line.unit_price,
                tax_rate: line.tax_rate,
                price_overridden: catalogue?.price !== line.unit_price,
            };
        });
    dirty.value = false;
}

// After a save the server sends the bills again: keep showing the one being billed.
watch(
    () => props.bills,
    () => {
        const current = activeBill.value;
        loadBill(current && current.status === 'open' ? current : null);
    },
);

function addItem(item: Item): void {
    if (item.price === null) {
        return;
    }

    const line = cart.value.find(
        (entry) => entry.outlet_item_id === item.id && !entry.price_overridden,
    );

    if (line) {
        line.quantity++;
    } else {
        cart.value.push({
            outlet_item_id: item.id,
            name: item.name,
            quantity: 1,
            unit_price: item.price,
            tax_rate: item.tax_rate,
            price_overridden: false,
        });
    }

    dirty.value = true;
}

function changeQuantity(line: CartLine, step: number): void {
    line.quantity = Math.max(1, line.quantity + step);
    dirty.value = true;
}

function removeLine(index: number): void {
    cart.value.splice(index, 1);
    dirty.value = true;
}

const saveForm = useForm({});

function save(): void {
    const payload = {
        guest_name: guestName.value || null,
        table_number: tableNumber.value || null,
        ...(activeBillId.value === null && tableId.value !== null
            ? { outlet_table_id: tableId.value }
            : {}),
        discount: discount.value || 0,
        items: cart.value.map((line) => ({
            outlet_item_id: line.outlet_item_id,
            quantity: line.quantity,
            ...(line.price_overridden ? { unit_price: line.unit_price } : {}),
        })),
    };

    saveForm.transform(() => payload);

    if (activeBillId.value === null) {
        saveForm.post(
            admin.pos.bills.store({
                property: props.property.id,
                outlet: props.outletId as number,
            }).url,
            {
                preserveScroll: true,
                // The new bill is the newest open one.
                onSuccess: () => loadBill(openBills.value[0] ?? null),
            },
        );
    } else {
        saveForm.put(
            admin.pos.bills.update({
                property: props.property.id,
                outletBill: activeBillId.value,
            }).url,
            { preserveScroll: true },
        );
    }
}

function newBill(): void {
    loadBill(null);
}

// ── Floor plan ──
const view = ref<'plan' | 'menu'>(props.tables.length ? 'plan' : 'menu');
const PLAN_COLUMNS = 12;
const planRows = computed(() =>
    Math.max(1, ...props.tables.map((table) => table.pos_y + 1)),
);

function planTableAt(x: number, y: number): PlanTable | undefined {
    return props.tables.find((table) => table.pos_x === x && table.pos_y === y);
}

/** A table's open bill opens it; a free table starts a new bill for it. */
function pickTable(table: PlanTable): void {
    const bill = props.bills.find(
        (entry) => entry.id === table.open_bill_ids[0],
    );

    if (bill) {
        loadBill(bill);
    } else {
        loadBill(null);
        tableId.value = table.id;
        tableNumber.value = table.name;
    }

    view.value = 'menu';
}

function tableTotal(table: PlanTable): number {
    return table.open_bill_ids.reduce(
        (sum, id) =>
            sum + (props.bills.find((bill) => bill.id === id)?.total ?? 0),
        0,
    );
}

function moveTable(event: Event): void {
    const value = (event.target as HTMLSelectElement).value;

    if (activeBillId.value !== null) {
        router.post(
            admin.pos.bills.moveTable({
                property: props.property.id,
                outletBill: activeBillId.value,
            }).url,
            { outlet_table_id: value === '' ? null : Number(value) },
            { preserveScroll: true },
        );
    }
}

// ── QR orders: refreshed while the page is open ──
let refreshTimer: number | undefined;

onMounted(() => {
    refreshTimer = window.setInterval(() => {
        if (!dirty.value && !saveForm.processing) {
            router.reload({ only: ['guestOrders', 'tables', 'bills'] });
        }
    }, 15000);
});

onBeforeUnmount(() => window.clearInterval(refreshTimer));

const acceptKot = ref(true);
const orderProcessing = ref<number | null>(null);

function acceptOrder(order: GuestOrder): void {
    router.post(
        admin.pos.guestOrders.accept({
            property: props.property.id,
            outletGuestOrder: order.id,
        }).url,
        { send_kot: acceptKot.value },
        {
            preserveScroll: true,
            onStart: () => (orderProcessing.value = order.id),
            onFinish: () => (orderProcessing.value = null),
        },
    );
}

const rejectTarget = ref<GuestOrder | null>(null);
const rejectForm = useForm({ reason: '' });

function submitReject(): void {
    if (rejectTarget.value) {
        rejectForm.post(
            admin.pos.guestOrders.reject({
                property: props.property.id,
                outletGuestOrder: rejectTarget.value.id,
            }).url,
            {
                preserveScroll: true,
                onSuccess: () => {
                    rejectTarget.value = null;
                    rejectForm.reset();
                },
            },
        );
    }
}

// ── Split bill: move some quantities to a new bill ──
const splitOpen = ref(false);
const splitQuantities = ref<Record<number, number>>({});
const splitForm = useForm({ items: [] as { id: number; quantity: number }[] });

function openSplit(): void {
    splitQuantities.value = Object.fromEntries(
        (activeBill.value?.items ?? []).map((line) => [line.id, 0]),
    );
    splitForm.clearErrors();
    splitOpen.value = true;
}

const splitMoving = computed(() =>
    Object.values(splitQuantities.value).reduce(
        (sum, quantity) => sum + quantity,
        0,
    ),
);

function submitSplit(): void {
    if (activeBillId.value === null) {
        return;
    }

    splitForm.items = Object.entries(splitQuantities.value).map(
        ([id, quantity]) => ({ id: Number(id), quantity }),
    );
    splitForm.post(
        admin.pos.bills.split({
            property: props.property.id,
            outletBill: activeBillId.value,
        }).url,
        {
            preserveScroll: true,
            onSuccess: () => {
                splitOpen.value = false;
                // The new bill is the newest open one.
                loadBill(openBills.value[0] ?? null);
            },
        },
    );
}

function switchOutlet(id: number): void {
    router.get(
        admin.pos.show(props.property.id).url,
        { outlet: id },
        { preserveScroll: true },
    );
}

// Post to room.
const postOpen = ref(false);
const roomSearch = ref('');
const postForm = useForm({
    sub_booking_id: null as number | null,
    sub_folio_id: null as number | null,
    take_payment: false,
    payment_mode: 'Cash',
    amount: 0,
    reference: '',
});

const matchingRooms = computed(() => {
    const term = roomSearch.value.trim().toLowerCase();

    return props.inHouseRooms.filter(
        (room) =>
            term === '' ||
            [room.room, room.booking_ref_id, room.guest ?? ''].some((value) =>
                value.toLowerCase().includes(term),
            ),
    );
});

const selectedRoom = computed(
    () =>
        props.inHouseRooms.find(
            (room) => room.sub_booking_id === postForm.sub_booking_id,
        ) ?? null,
);

function openPost(): void {
    postForm.reset();
    postForm.clearErrors();
    postForm.amount = activeBill.value?.total ?? 0;
    roomSearch.value = '';
    postOpen.value = true;
}

function submitPost(): void {
    if (activeBillId.value === null) {
        return;
    }

    postForm
        .transform((data) => ({
            sub_booking_id: data.sub_booking_id,
            sub_folio_id: data.sub_folio_id,
            ...(data.take_payment
                ? {
                      payment: {
                          payment_mode: data.payment_mode,
                          amount: data.amount,
                          reference: data.reference || null,
                      },
                  }
                : {}),
        }))
        .post(
            admin.pos.bills.postToRoom({
                property: props.property.id,
                outletBill: activeBillId.value,
            }).url,
            {
                preserveScroll: true,
                onSuccess: () => (postOpen.value = false),
            },
        );
}

// Settle at the outlet.
const settleOpen = ref(false);
const settleForm = useForm({ payment_mode: 'Cash', reference: '' });
/** Split between payers: one row per payment, adding up to the bill. */
const splitPayments = ref<
    { payer: string; payment_mode: string; amount: number; reference: string }[]
>([]);
const splitWays = ref(2);

function openSettle(): void {
    settleForm.reset();
    settleForm.clearErrors();
    splitPayments.value = [];
    settleOpen.value = true;
}

/** Divides the bill evenly; the last payer takes the rounding. */
function splitEvenly(): void {
    const total = activeBill.value?.total ?? 0;
    const ways = Math.max(2, Math.min(20, splitWays.value));
    const share = Math.floor((total / ways) * 100) / 100;

    splitPayments.value = Array.from({ length: ways }, (_, index) => ({
        payer: `Guest ${index + 1}`,
        payment_mode: settleForm.payment_mode,
        amount: index === ways - 1 ? round(total - share * (ways - 1)) : share,
        reference: '',
    }));
}

const splitPaid = computed(() =>
    round(
        splitPayments.value.reduce(
            (sum, payment) => sum + (Number(payment.amount) || 0),
            0,
        ),
    ),
);

function submitSettle(): void {
    if (activeBillId.value === null) {
        return;
    }

    const billId = activeBillId.value;

    settleForm.transform((data) =>
        splitPayments.value.length
            ? {
                  payments: splitPayments.value.map((payment) => ({
                      ...payment,
                      reference: payment.reference || null,
                      payer: payment.payer || null,
                  })),
              }
            : data,
    );
    settleForm.post(
        admin.pos.bills.settle({
            property: props.property.id,
            outletBill: billId,
        }).url,
        {
            preserveScroll: true,
            onSuccess: () => {
                settleOpen.value = false;
                const bill = props.bills.find((entry) => entry.id === billId);

                // A network printer already printed it on the server; a printer on this computer
                // prints the receipt now (opening the drawer for cash).
                if (bill && !props.printers?.network && direct.value.bill) {
                    printBill(bill, true);
                }
            },
        },
    );
}

// Void.
const voidTarget = ref<Bill | null>(null);
const voidForm = useForm({ reason: '' });

function openVoid(bill: Bill): void {
    voidForm.reset();
    voidForm.clearErrors();
    voidTarget.value = bill;
}

function submitVoid(): void {
    if (voidTarget.value === null) {
        return;
    }

    voidForm.post(
        admin.pos.bills.void({
            property: props.property.id,
            outletBill: voidTarget.value.id,
        }).url,
        {
            preserveScroll: true,
            onSuccess: () => (voidTarget.value = null),
        },
    );
}

// Kitchen tickets: what the saved bill has that its tickets haven't sent yet (or sent too many of).
const kotNotes = ref('');
const kotProcessing = ref(false);

const kitchenPending = computed(() => {
    const bill = activeBill.value;

    if (!bill) {
        return false;
    }

    const balance = new Map<string, number>();
    const keyOf = (line: { outlet_item_id: number | null; name: string }) =>
        line.outlet_item_id === null
            ? `name:${line.name}`
            : `item:${line.outlet_item_id}`;

    for (const line of bill.items) {
        balance.set(
            keyOf(line),
            (balance.get(keyOf(line)) ?? 0) + line.quantity,
        );
    }

    for (const line of bill.kots.flatMap((kot) => kot.items)) {
        balance.set(
            keyOf(line),
            (balance.get(keyOf(line)) ?? 0) - line.quantity,
        );
    }

    return [...balance.values()].some((quantity) => quantity !== 0);
});

function sendKot(): void {
    const bill = activeBill.value;

    if (!bill) {
        return;
    }

    router.post(
        admin.pos.bills.kots.store({
            property: props.property.id,
            outletBill: bill.id,
        }).url,
        { notes: kotNotes.value || null },
        {
            preserveScroll: true,
            onStart: () => (kotProcessing.value = true),
            onFinish: () => (kotProcessing.value = false),
            onSuccess: () => {
                kotNotes.value = '';
                const kot = props.bills
                    .find((entry) => entry.id === bill.id)
                    ?.kots.at(-1);

                // Without a network kitchen printer the ticket prints from this browser.
                if (kot && !kot.printed_at) {
                    printKot(kot);
                }
            },
        },
    );
}

// Printing: the bill's paper is picked here (the outlet's setting by default).
const paper = ref(props.printers?.paper ?? 'a4');
const paperOptions = [
    { value: 'a4', label: 'A4' },
    { value: 'thermal_80', label: '80mm' },
    { value: 'thermal_58', label: '58mm' },
];

watch(
    () => props.printers?.paper,
    (outletPaper) => (paper.value = outletPaper ?? 'a4'),
);

/** Prints a page in a hidden frame, so no window opens (and no pop-up blocker gets in the way). */
function printInFrame(url: string): void {
    const frame = document.createElement('iframe');
    frame.style.position = 'fixed';
    frame.style.width = '0';
    frame.style.height = '0';
    frame.style.border = '0';
    frame.src = url;
    document.body.appendChild(frame);
    setTimeout(() => frame.remove(), 60_000);
}

// Printers on this computer (QZ Tray, WebUSB, Web Serial): when one is connected for a slot,
// its prints skip the dialog and go straight to it.
const printersOpen = ref(false);
/** The bill opened in the bill modal (PDF, print, e-mail). */
const viewBillId = ref<number | null>(null);
const directKinds = supportedKinds();
const direct = ref<Record<PrinterSlot, ReturnType<typeof savedPrinter>>>({
    bill: savedPrinter('bill'),
    kot: savedPrinter('kot'),
});
const directError = ref('');

// QZ Tray: the printers installed on this computer, listed for the slot being set up.
const qzChoice = ref<{
    slot: PrinterSlot;
    printers: string[];
    selected: string;
} | null>(null);
const qzLoading = ref(false);

async function listQzPrinters(slot: PrinterSlot): Promise<void> {
    directError.value = '';
    qzLoading.value = true;

    try {
        const found = await qzPrinters();
        qzChoice.value = {
            slot,
            printers: found,
            selected: direct.value[slot]?.printerName ?? found[0] ?? '',
        };
    } catch (error) {
        directError.value =
            error instanceof Error
                ? error.message
                : 'QZ Tray could not be reached.';
    } finally {
        qzLoading.value = false;
    }
}

function useQzPrinter(): void {
    if (qzChoice.value?.selected) {
        direct.value[qzChoice.value.slot] = saveQzPrinter(
            qzChoice.value.slot,
            qzChoice.value.selected,
        );
    }

    qzChoice.value = null;
}

const kindLabels: Record<PrinterKind, string> = {
    usb: 'USB',
    serial: 'serial',
    qz: 'QZ Tray',
};

async function connectDirect(
    slot: PrinterSlot,
    kind: Exclude<PrinterKind, 'qz'>,
): Promise<void> {
    directError.value = '';

    try {
        direct.value[slot] = await connectPrinter(slot, kind);
    } catch (error) {
        directError.value =
            error instanceof Error && error.name !== 'NotFoundError'
                ? error.message
                : '';
    }
}

function forgetDirect(slot: PrinterSlot): void {
    forgetPrinter(slot);
    direct.value[slot] = null;
}

async function printDirect(slot: PrinterSlot, url: string): Promise<void> {
    directError.value = '';

    try {
        await printFrom(slot, url);
    } catch (error) {
        directError.value =
            error instanceof Error
                ? error.message
                : 'The printer did not print.';
    }
}

/** The thermal paper a direct print uses (A4 isn't a receipt printer's). */
function thermalPaper(): string {
    return paper.value === 'a4'
        ? props.printers?.paper === 'thermal_58'
            ? 'thermal_58'
            : 'thermal_80'
        : paper.value;
}

function printBill(bill: Bill, openDrawer = false): void {
    if (direct.value.bill) {
        void printDirect(
            'bill',
            admin.pos.bills.print(
                { property: props.property.id, outletBill: bill.id },
                {
                    query: {
                        paper: thermalPaper(),
                        format: 'escpos',
                        ...(openDrawer ? { drawer: 1 } : {}),
                    },
                },
            ).url,
        );

        return;
    }

    printInFrame(
        admin.pos.bills.print(
            { property: props.property.id, outletBill: bill.id },
            { query: { paper: paper.value, autoprint: 1 } },
        ).url,
    );
}

function openDrawer(): void {
    if (props.outletId === null) {
        return;
    }

    if (props.printers?.network) {
        router.post(
            admin.pos.drawer.open({
                property: props.property.id,
                outlet: props.outletId,
            }).url,
            {},
            { preserveScroll: true },
        );
    } else {
        void printDirect(
            'bill',
            admin.pos.drawer.escpos({
                property: props.property.id,
                outlet: props.outletId,
            }).url,
        );
    }
}

function sendBillToPrinter(bill: Bill): void {
    router.post(
        admin.pos.bills.sendToPrinter({
            property: props.property.id,
            outletBill: bill.id,
        }).url,
        { paper: paper.value },
        { preserveScroll: true },
    );
}

function printKot(kot: Kot): void {
    if (direct.value.kot) {
        void printDirect(
            'kot',
            admin.pos.kots.print(
                { property: props.property.id, outletKot: kot.id },
                { query: { format: 'escpos' } },
            ).url,
        );

        return;
    }

    printInFrame(
        admin.pos.kots.print(
            { property: props.property.id, outletKot: kot.id },
            { query: { autoprint: 1 } },
        ).url,
    );
}

function sendKotToPrinter(kot: Kot): void {
    router.post(
        admin.pos.kots.sendToPrinter({
            property: props.property.id,
            outletKot: kot.id,
        }).url,
        {},
        { preserveScroll: true },
    );
}

function escPosUrl(bill: Bill): string {
    return admin.pos.bills.print(
        { property: props.property.id, outletBill: bill.id },
        {
            query: {
                paper: paper.value === 'a4' ? 'thermal_80' : paper.value,
                format: 'escpos',
            },
        },
    ).url;
}

const voidPermissions = {
    open: 'pos.cancel_order',
    posted: 'pos.undo_push_to_room',
    settled: 'pos.undo_settle',
} as const;

function mayVoid(bill: Bill): boolean {
    return bill.status !== 'void' && can(voidPermissions[bill.status]);
}

const statusClasses: Record<Bill['status'], string> = {
    open: 'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-200',
    posted: 'bg-blue-100 text-blue-800 dark:bg-blue-900/40 dark:text-blue-200',
    settled:
        'bg-green-100 text-green-800 dark:bg-green-900/40 dark:text-green-200',
    void: 'bg-[#f5f5f4] text-[#706f6c] line-through dark:bg-[#1D1D1B] dark:text-[#A1A09A]',
};

const inputClass =
    'w-full rounded-md border border-[#e3e3e0] bg-white px-3 py-1.5 text-sm dark:border-[#3E3E3A] dark:bg-[#161615] dark:text-[#EDEDEC]';
const labelClass =
    'mb-1 block text-xs font-medium text-[#706f6c] dark:text-[#A1A09A]';
const primaryButton =
    'rounded-md bg-[#1b1b18] px-4 py-2 text-sm font-medium text-white disabled:opacity-40 dark:bg-[#EDEDEC] dark:text-[#1b1b18]';
const secondaryButton =
    'rounded-md border border-[#e3e3e0] px-4 py-2 text-sm font-medium disabled:opacity-40 dark:border-[#3E3E3A] dark:text-[#EDEDEC]';
</script>

<template>
    <Head :title="`POS: ${property.name}`" />

    <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1
                class="font-display text-2xl font-semibold text-[#1b1b18] dark:text-[#EDEDEC]"
            >
                POS
            </h1>
            <p class="text-sm text-[#706f6c] dark:text-[#A1A09A]">
                Business date {{ businessDate }} ·
                <Link
                    :href="admin.pos.report(property.id).url"
                    class="text-[#f53003] hover:underline dark:text-[#FF4433]"
                    >Reports</Link
                >
            </p>
        </div>
        <div v-if="outlets.length" class="flex flex-wrap items-center gap-2">
            <button
                type="button"
                class="rounded-md border border-[#e3e3e0] px-2 py-1 text-xs dark:border-[#3E3E3A] dark:text-[#EDEDEC]"
                @click="printersOpen = true"
            >
                Printers<template v-if="direct.bill || direct.kot">
                    ({{
                        [direct.bill, direct.kot].filter(Boolean).length
                    }}
                    connected)</template
                >
            </button>
            <button
                v-if="
                    printers?.cash_drawer && (printers.network || direct.bill)
                "
                type="button"
                class="rounded-md border border-[#e3e3e0] px-2 py-1 text-xs dark:border-[#3E3E3A] dark:text-[#EDEDEC]"
                @click="openDrawer"
            >
                Open drawer
            </button>
            <label
                class="flex items-center gap-1 text-xs text-[#706f6c] dark:text-[#A1A09A]"
            >
                Paper
                <select
                    v-model="paper"
                    class="rounded-md border border-[#e3e3e0] bg-white px-2 py-1 text-xs dark:border-[#3E3E3A] dark:bg-[#161615] dark:text-[#EDEDEC]"
                >
                    <option
                        v-for="option in paperOptions"
                        :key="option.value"
                        :value="option.value"
                    >
                        {{ option.label }}
                    </option>
                </select>
            </label>
            <button
                v-for="outlet in outlets"
                :key="outlet.id"
                type="button"
                class="rounded-md border px-3 py-1.5 text-sm font-medium"
                :class="
                    outlet.id === outletId
                        ? 'border-[#1b1b18] bg-[#1b1b18] text-white dark:border-[#EDEDEC] dark:bg-[#EDEDEC] dark:text-[#1b1b18]'
                        : 'border-[#e3e3e0] dark:border-[#3E3E3A] dark:text-[#EDEDEC]'
                "
                @click="switchOutlet(outlet.id)"
            >
                {{ outlet.name }}
            </button>
        </div>
    </div>

    <p
        v-if="!outlets.length"
        class="text-sm text-[#706f6c] dark:text-[#A1A09A]"
    >
        No active outlet. Add one under Settings → Outlets.
    </p>

    <template v-else>
        <!-- Orders from the table QR codes -->
        <section
            v-if="guestOrders.length"
            class="mb-4 rounded-lg border border-amber-300 bg-amber-50 p-4 dark:border-amber-800 dark:bg-amber-950/30"
            data-test="guest-orders"
        >
            <div class="mb-2 flex flex-wrap items-center justify-between gap-2">
                <h2
                    class="text-sm font-semibold text-amber-900 dark:text-amber-200"
                >
                    {{ guestOrders.length }} QR order(s) waiting
                </h2>
                <label
                    class="flex items-center gap-2 text-xs text-amber-900 dark:text-amber-200"
                    ><input v-model="acceptKot" type="checkbox" /> Send to the
                    kitchen when accepted</label
                >
            </div>
            <ul class="flex flex-col gap-2">
                <li
                    v-for="order in guestOrders"
                    :key="order.id"
                    class="flex flex-wrap items-center gap-3 rounded-md bg-white px-3 py-2 text-sm dark:bg-[#161615] dark:text-[#EDEDEC]"
                >
                    <span class="font-semibold"
                        >Table {{ order.table ?? '—' }}</span
                    >
                    <span class="min-w-0 flex-1">
                        {{
                            order.items
                                .map(
                                    (line) => `${line.quantity} × ${line.name}`,
                                )
                                .join(', ')
                        }}
                        <span
                            v-if="order.guest_name"
                            class="text-xs text-[#706f6c]"
                        >
                            · {{ order.guest_name }}</span
                        >
                        <span
                            v-if="order.notes"
                            class="block text-xs text-[#706f6c]"
                            >Note: {{ order.notes }}</span
                        >
                    </span>
                    <span class="text-xs text-[#706f6c]"
                        >{{ propertyTime.dateTime(order.created_at) }} ·
                        {{ money(order.total) }}</span
                    >
                    <button
                        type="button"
                        :class="primaryButton"
                        :disabled="orderProcessing === order.id"
                        :data-test="`accept-order-${order.id}`"
                        @click="acceptOrder(order)"
                    >
                        Accept
                    </button>
                    <button
                        type="button"
                        class="text-xs text-red-700 hover:underline"
                        @click="((rejectTarget = order), rejectForm.reset())"
                    >
                        Reject
                    </button>
                </li>
            </ul>
        </section>

        <div v-if="tables.length" class="mb-3 flex gap-1">
            <button
                type="button"
                class="rounded-md border px-3 py-1.5 text-sm font-medium"
                :class="
                    view === 'plan'
                        ? 'border-[#1b1b18] bg-[#1b1b18] text-white dark:bg-[#EDEDEC] dark:text-[#1b1b18]'
                        : 'border-[#e3e3e0] dark:border-[#3E3E3A] dark:text-[#EDEDEC]'
                "
                data-test="view-plan"
                @click="view = 'plan'"
            >
                Tables
            </button>
            <button
                type="button"
                class="rounded-md border px-3 py-1.5 text-sm font-medium"
                :class="
                    view === 'menu'
                        ? 'border-[#1b1b18] bg-[#1b1b18] text-white dark:bg-[#EDEDEC] dark:text-[#1b1b18]'
                        : 'border-[#e3e3e0] dark:border-[#3E3E3A] dark:text-[#EDEDEC]'
                "
                @click="view = 'menu'"
            >
                Menu
            </button>
            <span
                v-if="qrOrdering"
                class="ml-2 self-center text-xs text-[#706f6c] dark:text-[#A1A09A]"
                >QR ordering is on</span
            >
        </div>

        <div class="grid gap-4 lg:grid-cols-3">
            <!-- Floor plan -->
            <section
                v-if="view === 'plan' && tables.length"
                class="rounded-lg border border-[#e3e3e0] p-4 lg:col-span-2 dark:border-[#3E3E3A]"
                data-test="floor-plan"
            >
                <div
                    class="grid gap-2"
                    :style="{
                        gridTemplateColumns: `repeat(${PLAN_COLUMNS}, minmax(0, 1fr))`,
                    }"
                >
                    <template v-for="y in planRows" :key="`row-${y}`">
                        <div
                            v-for="x in PLAN_COLUMNS"
                            :key="`${x}-${y}`"
                            class="aspect-square"
                        >
                            <button
                                v-if="planTableAt(x - 1, y - 1)"
                                type="button"
                                class="flex h-full w-full flex-col items-center justify-center border text-xs font-semibold"
                                :class="[
                                    planTableAt(x - 1, y - 1)!.shape === 'round'
                                        ? 'rounded-full'
                                        : 'rounded-md',
                                    planTableAt(x - 1, y - 1)!.open_bill_ids
                                        .length
                                        ? 'border-amber-500 bg-amber-100 text-amber-900 dark:bg-amber-900/40 dark:text-amber-100'
                                        : 'border-emerald-500 bg-emerald-50 text-emerald-900 dark:bg-emerald-950/40 dark:text-emerald-100',
                                ]"
                                :data-test="`pos-table-${planTableAt(x - 1, y - 1)!.id}`"
                                :title="
                                    planTableAt(x - 1, y - 1)!.open_bill_ids
                                        .length
                                        ? 'Open the table\'s bill'
                                        : 'Start a bill for this table'
                                "
                                @click="pickTable(planTableAt(x - 1, y - 1)!)"
                            >
                                {{ planTableAt(x - 1, y - 1)!.name }}
                                <span class="font-normal">{{
                                    planTableAt(x - 1, y - 1)!.open_bill_ids
                                        .length
                                        ? money(
                                              tableTotal(
                                                  planTableAt(x - 1, y - 1)!,
                                              ),
                                          )
                                        : `${planTableAt(x - 1, y - 1)!.seats} seats`
                                }}</span>
                            </button>
                        </div>
                    </template>
                </div>
                <p class="mt-3 text-xs text-[#706f6c] dark:text-[#A1A09A]">
                    Green: free · amber: has an open bill. Click a table to bill
                    it.
                </p>
            </section>

            <!-- Catalogue -->
            <section
                v-else
                class="rounded-lg border border-[#e3e3e0] p-4 lg:col-span-2 dark:border-[#3E3E3A]"
            >
                <input
                    v-model="search"
                    type="search"
                    placeholder="Search items"
                    :class="inputClass"
                    class="mb-4"
                />
                <p
                    v-if="!itemsByCategory.length"
                    class="text-sm text-[#706f6c] dark:text-[#A1A09A]"
                >
                    No items. Tick items in the outlet's catalogue and price
                    them in a price plan.
                </p>
                <div
                    v-for="[category, categoryItems] in itemsByCategory"
                    :key="category"
                    class="mb-4"
                >
                    <h3
                        class="mb-2 text-xs font-semibold tracking-wide text-[#706f6c] uppercase dark:text-[#A1A09A]"
                    >
                        {{ category }}
                    </h3>
                    <div
                        class="grid grid-cols-2 gap-2 sm:grid-cols-3 xl:grid-cols-4"
                    >
                        <button
                            v-for="item in categoryItems"
                            :key="item.id"
                            type="button"
                            class="flex flex-col items-start rounded-md border border-[#e3e3e0] p-3 text-left text-sm hover:bg-[#f5f5f4] disabled:cursor-not-allowed disabled:opacity-50 dark:border-[#3E3E3A] dark:text-[#EDEDEC] dark:hover:bg-[#1D1D1B]"
                            :disabled="item.price === null"
                            :title="
                                item.price === null
                                    ? 'Not on a price plan for today'
                                    : `Add ${item.name}`
                            "
                            @click="addItem(item)"
                        >
                            <span class="font-medium">{{ item.name }}</span>
                            <span
                                class="text-xs text-[#706f6c] dark:text-[#A1A09A]"
                                >{{
                                    item.price === null
                                        ? 'No price'
                                        : money(item.price)
                                }}<template v-if="item.tax_rate">
                                    + {{ item.tax_rate }}% tax</template
                                ></span
                            >
                        </button>
                    </div>
                </div>
            </section>

            <!-- Bill -->
            <section
                class="flex flex-col gap-3 rounded-lg border border-[#e3e3e0] p-4 dark:border-[#3E3E3A]"
            >
                <div class="flex flex-wrap items-center gap-2">
                    <button
                        type="button"
                        class="rounded-md border px-2 py-1 text-xs font-medium"
                        :class="
                            activeBillId === null
                                ? 'border-[#1b1b18] dark:border-[#EDEDEC] dark:text-[#EDEDEC]'
                                : 'border-[#e3e3e0] dark:border-[#3E3E3A] dark:text-[#EDEDEC]'
                        "
                        @click="newBill"
                    >
                        + New bill
                    </button>
                    <button
                        v-for="bill in openBills"
                        :key="bill.id"
                        type="button"
                        class="rounded-md border px-2 py-1 text-xs font-medium"
                        :class="
                            activeBillId === bill.id
                                ? 'border-[#1b1b18] dark:border-[#EDEDEC] dark:text-[#EDEDEC]'
                                : 'border-[#e3e3e0] dark:border-[#3E3E3A] dark:text-[#EDEDEC]'
                        "
                        @click="loadBill(bill)"
                    >
                        #{{ bill.bill_number
                        }}<template v-if="bill.guest_name">
                            · {{ bill.guest_name }}</template
                        >
                    </button>
                </div>

                <h2
                    class="font-display text-lg font-semibold text-[#1b1b18] dark:text-[#EDEDEC]"
                >
                    {{
                        activeBill
                            ? `Bill #${activeBill.bill_number}`
                            : 'New bill'
                    }}
                </h2>

                <div class="grid grid-cols-3 gap-2">
                    <label class="col-span-2">
                        <span :class="labelClass">Guest name</span>
                        <input
                            v-model="guestName"
                            type="text"
                            placeholder="Walk-in"
                            :class="inputClass"
                            @input="dirty = true"
                        />
                    </label>
                    <label>
                        <span :class="labelClass">Table</span>
                        <select
                            v-if="tables.length && activeBill"
                            :value="activeBill.outlet_table_id ?? ''"
                            :class="inputClass"
                            data-test="bill-table"
                            @change="moveTable"
                        >
                            <option value="">—</option>
                            <option
                                v-for="table in tables"
                                :key="table.id"
                                :value="table.id"
                            >
                                {{ table.name }}
                            </option>
                        </select>
                        <select
                            v-else-if="tables.length"
                            v-model="tableId"
                            :class="inputClass"
                            @change="
                                tableNumber =
                                    tables.find((table) => table.id === tableId)
                                        ?.name ?? ''
                            "
                        >
                            <option :value="null">—</option>
                            <option
                                v-for="table in tables"
                                :key="table.id"
                                :value="table.id"
                            >
                                {{ table.name }}
                            </option>
                        </select>
                        <input
                            v-else
                            v-model="tableNumber"
                            type="text"
                            maxlength="20"
                            :class="inputClass"
                            @input="dirty = true"
                        />
                    </label>
                </div>

                <p
                    v-if="!cart.length"
                    class="text-sm text-[#706f6c] dark:text-[#A1A09A]"
                >
                    Click items to add them.
                </p>
                <ul
                    v-else
                    class="flex flex-col divide-y divide-[#e3e3e0] dark:divide-[#3E3E3A]"
                >
                    <li
                        v-for="(line, index) in cart"
                        :key="`${line.outlet_item_id}-${index}`"
                        class="flex items-center gap-2 py-2 text-sm dark:text-[#EDEDEC]"
                    >
                        <span class="min-w-0 flex-1 truncate">{{
                            line.name
                        }}</span>
                        <button
                            type="button"
                            class="h-6 w-6 rounded border border-[#e3e3e0] dark:border-[#3E3E3A]"
                            aria-label="One less"
                            @click="changeQuantity(line, -1)"
                        >
                            −
                        </button>
                        <span class="w-6 text-center">{{ line.quantity }}</span>
                        <button
                            type="button"
                            class="h-6 w-6 rounded border border-[#e3e3e0] dark:border-[#3E3E3A]"
                            aria-label="One more"
                            @click="changeQuantity(line, 1)"
                        >
                            +
                        </button>
                        <input
                            v-if="can('pos.override_item_price')"
                            v-model.number="line.unit_price"
                            type="number"
                            min="0"
                            step="0.01"
                            class="w-20 rounded-md border border-[#e3e3e0] bg-white px-2 py-1 text-right text-sm dark:border-[#3E3E3A] dark:bg-[#161615]"
                            aria-label="Price"
                            @input="
                                ((line.price_overridden = true), (dirty = true))
                            "
                        />
                        <span v-else class="w-20 text-right">{{
                            money(line.unit_price)
                        }}</span>
                        <button
                            type="button"
                            class="text-red-600"
                            aria-label="Remove"
                            @click="removeLine(index)"
                        >
                            ✕
                        </button>
                    </li>
                </ul>

                <label v-if="can('pos.apply_discount')">
                    <span :class="labelClass">Discount</span>
                    <input
                        v-model.number="discount"
                        type="number"
                        min="0"
                        step="0.01"
                        :class="inputClass"
                        @input="dirty = true"
                    />
                </label>

                <dl class="grid grid-cols-2 gap-1 text-sm dark:text-[#EDEDEC]">
                    <dt>Sub total</dt>
                    <dd class="text-right">{{ money(totals.subTotal) }}</dd>
                    <template v-if="discount">
                        <dt>Discount</dt>
                        <dd class="text-right">
                            −{{ money(Math.min(discount, totals.subTotal)) }}
                        </dd>
                    </template>
                    <dt>Tax</dt>
                    <dd class="text-right">{{ money(totals.tax) }}</dd>
                    <dt class="font-semibold">Total</dt>
                    <dd class="text-right font-semibold">
                        {{ money(totals.total) }}
                    </dd>
                </dl>

                <div
                    v-if="Object.keys(saveForm.errors).length"
                    class="text-xs text-red-600"
                >
                    <p v-for="(message, key) in saveForm.errors" :key="key">
                        {{ message }}
                    </p>
                </div>

                <button
                    type="button"
                    :class="primaryButton"
                    :disabled="
                        !cart.length ||
                        saveForm.processing ||
                        (activeBillId !== null && !dirty)
                    "
                    @click="save"
                >
                    {{ activeBillId === null ? 'Open bill' : 'Save bill' }}
                </button>

                <div
                    v-if="activeBill"
                    class="flex flex-col gap-2 rounded-md border border-dashed border-[#e3e3e0] p-2 dark:border-[#3E3E3A]"
                >
                    <div class="flex gap-2">
                        <input
                            v-model="kotNotes"
                            type="text"
                            maxlength="500"
                            placeholder="Kitchen note (optional)"
                            :class="inputClass"
                        />
                        <button
                            type="button"
                            :class="secondaryButton"
                            class="shrink-0"
                            :disabled="
                                dirty || !kitchenPending || kotProcessing
                            "
                            :title="
                                kitchenPending
                                    ? 'Send the new items to the kitchen'
                                    : 'Nothing new for the kitchen'
                            "
                            @click="sendKot"
                        >
                            Send KOT
                        </button>
                    </div>
                    <ul
                        v-if="activeBill.kots.length"
                        class="flex flex-col gap-1 text-xs text-[#706f6c] dark:text-[#A1A09A]"
                    >
                        <li
                            v-for="kot in activeBill.kots"
                            :key="kot.id"
                            class="flex items-center gap-2"
                        >
                            <span
                                class="min-w-0 flex-1 truncate"
                                :class="
                                    kot.is_cancellation
                                        ? 'text-red-700 dark:text-red-400'
                                        : ''
                                "
                            >
                                KOT #{{ kot.kot_number }}:
                                {{
                                    kot.items
                                        .map(
                                            (line) =>
                                                `${line.quantity > 0 ? '' : '−'}${Math.abs(line.quantity)} ${line.name}`,
                                        )
                                        .join(', ')
                                }}
                            </span>
                            <button
                                type="button"
                                class="hover:underline"
                                @click="printKot(kot)"
                            >
                                Print
                            </button>
                            <button
                                v-if="printers?.kot_network"
                                type="button"
                                class="hover:underline"
                                @click="sendKotToPrinter(kot)"
                            >
                                Printer
                            </button>
                        </li>
                    </ul>
                </div>

                <div v-if="activeBill" class="flex flex-wrap gap-2">
                    <button
                        type="button"
                        :class="secondaryButton"
                        :disabled="dirty || !activeBill.total"
                        @click="openPost"
                    >
                        Post to Room
                    </button>
                    <button
                        type="button"
                        :class="secondaryButton"
                        :disabled="dirty || !activeBill.total"
                        @click="openSettle"
                    >
                        Settle
                    </button>
                    <button
                        type="button"
                        :class="secondaryButton"
                        :disabled="
                            dirty ||
                            activeBill.items.length < 1 ||
                            activeBill.items.reduce(
                                (sum, line) => sum + line.quantity,
                                0,
                            ) < 2
                        "
                        data-test="split-bill"
                        @click="openSplit"
                    >
                        Split
                    </button>
                    <button
                        type="button"
                        :class="secondaryButton"
                        :disabled="dirty"
                        title="Pro-forma bill"
                        @click="printBill(activeBill)"
                    >
                        Print
                    </button>
                    <button
                        v-if="mayVoid(activeBill)"
                        type="button"
                        class="rounded-md border border-red-300 px-4 py-2 text-sm font-medium text-red-700 dark:border-red-800 dark:text-red-400"
                        @click="openVoid(activeBill)"
                    >
                        Void
                    </button>
                </div>
                <p
                    v-if="activeBill && dirty"
                    class="text-xs text-amber-700 dark:text-amber-400"
                >
                    Save the bill before sending it to the kitchen, printing,
                    posting or settling it.
                </p>
                <p
                    v-if="errorOf(page.props, 'printer')"
                    class="text-xs text-red-600"
                >
                    {{ errorOf(page.props, 'printer') }}
                </p>
                <p v-if="directError" class="text-xs text-red-600">
                    {{ directError }}
                </p>
            </section>
        </div>

        <!-- Today's closed bills -->
        <section
            class="mt-6 rounded-lg border border-[#e3e3e0] dark:border-[#3E3E3A]"
        >
            <h2
                class="border-b border-[#e3e3e0] px-4 py-3 text-sm font-semibold tracking-wide text-[#706f6c] uppercase dark:border-[#3E3E3A] dark:text-[#A1A09A]"
            >
                Today's bills
            </h2>
            <p
                v-if="!closedBills.length"
                class="px-4 py-3 text-sm text-[#706f6c] dark:text-[#A1A09A]"
            >
                No bills posted, settled or voided today.
            </p>
            <div v-else class="overflow-x-auto">
                <table class="w-full text-left text-sm dark:text-[#EDEDEC]">
                    <thead
                        class="text-xs text-[#706f6c] uppercase dark:text-[#A1A09A]"
                    >
                        <tr>
                            <th class="px-4 py-2">Bill</th>
                            <th class="px-4 py-2">Opened</th>
                            <th class="px-4 py-2">Guest</th>
                            <th class="px-4 py-2">Status</th>
                            <th class="px-4 py-2">Booking / Receipt</th>
                            <th class="px-4 py-2 text-right">Total</th>
                            <th class="px-4 py-2"></th>
                        </tr>
                    </thead>
                    <tbody
                        class="divide-y divide-[#e3e3e0] dark:divide-[#3E3E3A]"
                    >
                        <tr v-for="bill in closedBills" :key="bill.id">
                            <td class="px-4 py-2">
                                <button
                                    type="button"
                                    class="font-medium text-[#f53003] hover:underline dark:text-[#FF4433]"
                                    @click="viewBillId = bill.id"
                                >
                                    #{{ bill.bill_number }}
                                </button>
                            </td>
                            <td class="px-4 py-2">
                                {{ propertyTime.dateTime(bill.created_at) }}
                            </td>
                            <td class="px-4 py-2">
                                {{ bill.guest_name ?? 'Walk-in' }}
                            </td>
                            <td class="px-4 py-2">
                                <span
                                    class="rounded px-2 py-0.5 text-xs"
                                    :class="statusClasses[bill.status]"
                                    :title="bill.void_reason ?? ''"
                                    >{{ bill.status_label }}</span
                                >
                            </td>
                            <td class="px-4 py-2">
                                {{
                                    [bill.booking_ref_id, bill.receipt_number]
                                        .filter(Boolean)
                                        .join(' · ') || '—'
                                }}
                            </td>
                            <td class="px-4 py-2 text-right">
                                {{ money(bill.total) }}
                            </td>
                            <td class="px-4 py-2 text-xs">
                                <div class="flex justify-end gap-3">
                                    <button
                                        type="button"
                                        class="hover:underline"
                                        @click="printBill(bill)"
                                    >
                                        Print
                                    </button>
                                    <button
                                        v-if="printers?.network"
                                        type="button"
                                        class="hover:underline"
                                        @click="sendBillToPrinter(bill)"
                                    >
                                        Printer
                                    </button>
                                    <a
                                        :href="escPosUrl(bill)"
                                        class="hover:underline"
                                        title="Raw ESC/POS file for a thermal printer or print agent"
                                        >ESC/POS</a
                                    >
                                    <button
                                        v-if="mayVoid(bill)"
                                        type="button"
                                        class="text-red-700 hover:underline dark:text-red-400"
                                        @click="openVoid(bill)"
                                    >
                                        Void
                                    </button>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>
    </template>

    <Modal
        :show="postOpen"
        title="Post to Room"
        max-width="max-w-lg"
        @close="postOpen = false"
    >
        <form class="flex flex-col gap-3" @submit.prevent="submitPost">
            <input
                v-model="roomSearch"
                type="search"
                placeholder="Room, reservation ID or guest"
                :class="inputClass"
            />
            <div
                class="max-h-56 overflow-y-auto rounded-md border border-[#e3e3e0] dark:border-[#3E3E3A]"
            >
                <label
                    v-for="room in matchingRooms"
                    :key="room.sub_booking_id"
                    class="flex cursor-pointer items-center gap-2 px-3 py-2 text-sm hover:bg-[#f5f5f4] dark:text-[#EDEDEC] dark:hover:bg-[#1D1D1B]"
                >
                    <input
                        v-model="postForm.sub_booking_id"
                        type="radio"
                        :value="room.sub_booking_id"
                        @change="postForm.sub_folio_id = null"
                    />
                    <span class="font-medium">{{ room.room }}</span>
                    <span class="text-[#706f6c] dark:text-[#A1A09A]"
                        >{{ room.category }} · {{ room.booking_ref_id
                        }}<template v-if="room.guest">
                            · {{ room.guest }}</template
                        ></span
                    >
                </label>
                <p
                    v-if="!inHouseRooms.length"
                    class="px-3 py-2 text-sm text-[#706f6c] dark:text-[#A1A09A]"
                >
                    No guest is checked in at {{ property.name }}. Bills can
                    only be charged to an in-house room: check the guest in from
                    the booking chart first, or settle the bill at the outlet.
                </p>
                <p
                    v-else-if="!matchingRooms.length"
                    class="px-3 py-2 text-sm text-[#706f6c] dark:text-[#A1A09A]"
                >
                    No checked-in room matches.
                </p>
            </div>
            <span
                v-if="postForm.errors.sub_booking_id"
                class="text-xs text-red-600"
                >{{ postForm.errors.sub_booking_id }}</span
            >

            <label v-if="selectedRoom">
                <span :class="labelClass">Billing window</span>
                <select v-model="postForm.sub_folio_id" :class="inputClass">
                    <option :value="null">
                        The room's (routing rules apply)
                    </option>
                    <option
                        v-for="window in selectedRoom.windows"
                        :key="window.sub_folio_id"
                        :value="window.sub_folio_id"
                    >
                        {{ window.label }}
                    </option>
                </select>
                <span
                    v-if="postForm.errors.sub_folio_id"
                    class="text-xs text-red-600"
                    >{{ postForm.errors.sub_folio_id }}</span
                >
            </label>

            <label class="flex items-center gap-2 text-sm dark:text-[#EDEDEC]">
                <input v-model="postForm.take_payment" type="checkbox" />
                Take a payment now (credited to the same folio)
            </label>
            <div v-if="postForm.take_payment" class="grid grid-cols-2 gap-3">
                <label>
                    <span :class="labelClass">Payment mode</span>
                    <select v-model="postForm.payment_mode" :class="inputClass">
                        <option
                            v-for="mode in paymentModes"
                            :key="mode"
                            :value="mode"
                        >
                            {{ mode }}
                        </option>
                    </select>
                </label>
                <label>
                    <span :class="labelClass">Amount</span>
                    <input
                        v-model.number="postForm.amount"
                        type="number"
                        min="0.01"
                        step="0.01"
                        :max="activeBill?.total"
                        :class="inputClass"
                    />
                </label>
                <label class="col-span-2">
                    <span :class="labelClass">Reference (optional)</span>
                    <input
                        v-model="postForm.reference"
                        type="text"
                        :class="inputClass"
                    />
                </label>
                <span
                    v-if="errorOf(postForm, 'payment.amount')"
                    class="col-span-2 text-xs text-red-600"
                    >{{ errorOf(postForm, 'payment.amount') }}</span
                >
            </div>
            <span
                v-if="errorOf(postForm, 'bill')"
                class="text-xs text-red-600"
                >{{ errorOf(postForm, 'bill') }}</span
            >

            <div class="flex justify-end gap-2">
                <button
                    type="button"
                    :class="secondaryButton"
                    @click="postOpen = false"
                >
                    Cancel
                </button>
                <button
                    type="submit"
                    :class="primaryButton"
                    :disabled="
                        postForm.sub_booking_id === null || postForm.processing
                    "
                >
                    Post {{ money(activeBill?.total ?? 0) }}
                </button>
            </div>
        </form>
    </Modal>

    <OutletBillModal
        :property-id="property.id"
        :bill-id="viewBillId"
        @close="viewBillId = null"
    />

    <Modal
        :show="printersOpen"
        title="Printers on this computer"
        max-width="max-w-lg"
        @close="printersOpen = false"
    >
        <div class="flex flex-col gap-4 text-sm dark:text-[#EDEDEC]">
            <p class="text-[#706f6c] dark:text-[#A1A09A]">
                Connect a receipt printer plugged into this computer to print
                bills and kitchen tickets straight to it, without the print
                dialog. Without one, prints use the browser's print
                dialog<template
                    v-if="printers?.network || printers?.kot_network"
                    >, or the outlet's network printers</template
                >.
            </p>
            <div
                v-for="slot in ['bill', 'kot'] as PrinterSlot[]"
                :key="slot"
                class="flex flex-col gap-2 rounded-md border border-[#e3e3e0] p-3 dark:border-[#3E3E3A]"
            >
                <div class="flex items-center justify-between">
                    <span class="font-medium">{{
                        slot === 'bill'
                            ? 'Bill printer'
                            : 'Kitchen (KOT) printer'
                    }}</span>
                    <span class="text-xs text-[#706f6c] dark:text-[#A1A09A]">{{
                        direct[slot]
                            ? `${direct[slot]!.label} (${kindLabels[direct[slot]!.kind]})`
                            : 'Not connected'
                    }}</span>
                </div>
                <div class="flex flex-wrap gap-2">
                    <button
                        type="button"
                        :class="secondaryButton"
                        :disabled="qzLoading"
                        @click="listQzPrinters(slot)"
                    >
                        QZ Tray
                    </button>
                    <button
                        v-if="directKinds.includes('usb')"
                        type="button"
                        :class="secondaryButton"
                        @click="connectDirect(slot, 'usb')"
                    >
                        Connect USB
                    </button>
                    <button
                        v-if="directKinds.includes('serial')"
                        type="button"
                        :class="secondaryButton"
                        @click="connectDirect(slot, 'serial')"
                    >
                        Connect serial / Bluetooth
                    </button>
                    <button
                        v-if="direct[slot]"
                        type="button"
                        class="text-xs text-red-700 hover:underline dark:text-red-400"
                        @click="forgetDirect(slot)"
                    >
                        Disconnect
                    </button>
                </div>
                <div v-if="qzChoice?.slot === slot" class="flex gap-2">
                    <select v-model="qzChoice.selected" :class="inputClass">
                        <option
                            v-for="name in qzChoice.printers"
                            :key="name"
                            :value="name"
                        >
                            {{ name }}
                        </option>
                    </select>
                    <button
                        type="button"
                        :class="primaryButton"
                        class="shrink-0"
                        :disabled="!qzChoice.selected"
                        @click="useQzPrinter"
                    >
                        Use
                    </button>
                </div>
            </div>
            <p class="text-xs text-[#706f6c] dark:text-[#A1A09A]">
                QZ Tray (free from qz.io, any browser) prints to a printer
                installed in Windows or macOS by its name. USB and serial work
                in Chrome or Edge without it; on Windows a USB printer needs the
                WinUSB driver (Zadig) for that.
            </p>
            <p v-if="directError" class="text-xs text-red-600">
                {{ directError }}
            </p>
        </div>
    </Modal>

    <Modal :show="settleOpen" title="Settle Bill" @close="settleOpen = false">
        <form class="flex flex-col gap-3" @submit.prevent="submitSettle">
            <p class="text-sm dark:text-[#EDEDEC]">
                Receive {{ money(activeBill?.total ?? 0) }} for bill #{{
                    activeBill?.bill_number
                }}.
            </p>
            <template v-if="!splitPayments.length">
                <label>
                    <span :class="labelClass">Payment mode</span>
                    <select
                        v-model="settleForm.payment_mode"
                        :class="inputClass"
                    >
                        <option
                            v-for="mode in paymentModes"
                            :key="mode"
                            :value="mode"
                        >
                            {{ mode }}
                        </option>
                    </select>
                </label>
                <label>
                    <span :class="labelClass">Reference (optional)</span>
                    <input
                        v-model="settleForm.reference"
                        type="text"
                        :class="inputClass"
                    />
                </label>
                <div
                    class="flex items-center gap-2 text-sm dark:text-[#EDEDEC]"
                >
                    Split between
                    <input
                        v-model.number="splitWays"
                        type="number"
                        min="2"
                        max="20"
                        class="w-16 rounded-md border border-[#e3e3e0] px-2 py-1 text-sm dark:border-[#3E3E3A] dark:bg-[#161615]"
                        data-test="split-ways"
                    />
                    payers
                    <button
                        type="button"
                        :class="secondaryButton"
                        data-test="split-evenly"
                        @click="splitEvenly"
                    >
                        Split
                    </button>
                </div>
            </template>
            <template v-else>
                <div
                    v-for="(payment, index) in splitPayments"
                    :key="index"
                    class="grid grid-cols-12 gap-2"
                >
                    <input
                        v-model="payment.payer"
                        type="text"
                        maxlength="100"
                        placeholder="Payer"
                        class="col-span-4"
                        :class="inputClass"
                    />
                    <select
                        v-model="payment.payment_mode"
                        class="col-span-4"
                        :class="inputClass"
                    >
                        <option
                            v-for="mode in paymentModes"
                            :key="mode"
                            :value="mode"
                        >
                            {{ mode }}
                        </option>
                    </select>
                    <input
                        v-model.number="payment.amount"
                        type="number"
                        min="0.01"
                        step="0.01"
                        class="col-span-3"
                        :class="inputClass"
                        :aria-label="`Amount ${index + 1}`"
                    />
                    <button
                        type="button"
                        class="col-span-1 text-red-600"
                        aria-label="Remove"
                        @click="splitPayments.splice(index, 1)"
                    >
                        ✕
                    </button>
                </div>
                <div class="flex items-center justify-between text-xs">
                    <button
                        type="button"
                        class="hover:underline dark:text-[#EDEDEC]"
                        @click="
                            splitPayments.push({
                                payer: '',
                                payment_mode: settleForm.payment_mode,
                                amount: 0,
                                reference: '',
                            })
                        "
                    >
                        + Add payment
                    </button>
                    <span
                        :class="
                            Math.abs(splitPaid - (activeBill?.total ?? 0)) <
                            0.01
                                ? 'text-emerald-700'
                                : 'text-red-600'
                        "
                        >Paid {{ money(splitPaid) }} of
                        {{ money(activeBill?.total ?? 0) }}</span
                    >
                </div>
                <button
                    type="button"
                    class="self-start text-xs text-[#706f6c] hover:underline"
                    @click="splitPayments = []"
                >
                    One payment instead
                </button>
            </template>
            <span
                v-if="errorOf(settleForm, 'payments')"
                class="text-xs text-red-600"
                >{{ errorOf(settleForm, 'payments') }}</span
            >
            <span
                v-if="errorOf(settleForm, 'bill')"
                class="text-xs text-red-600"
                >{{ errorOf(settleForm, 'bill') }}</span
            >
            <div class="flex justify-end gap-2">
                <button
                    type="button"
                    :class="secondaryButton"
                    @click="settleOpen = false"
                >
                    Cancel
                </button>
                <button
                    type="submit"
                    :class="primaryButton"
                    :disabled="
                        settleForm.processing ||
                        (splitPayments.length > 0 &&
                            Math.abs(splitPaid - (activeBill?.total ?? 0)) >=
                                0.01)
                    "
                    data-test="settle-submit"
                >
                    Settle
                </button>
            </div>
        </form>
    </Modal>

    <Modal
        :show="splitOpen"
        :title="`Split Bill #${activeBill?.bill_number ?? ''}`"
        @close="splitOpen = false"
    >
        <form class="flex flex-col gap-3" @submit.prevent="submitSplit">
            <p class="text-sm text-[#706f6c] dark:text-[#A1A09A]">
                Choose what moves to a new bill for the same table. What the
                kitchen already has moves with it.
            </p>
            <div
                v-for="line in activeBill?.items ?? []"
                :key="line.id"
                class="flex items-center gap-3 text-sm dark:text-[#EDEDEC]"
            >
                <span class="min-w-0 flex-1 truncate"
                    >{{ line.name }}
                    <span class="text-xs text-[#706f6c]"
                        >(of {{ line.quantity }})</span
                    ></span
                >
                <button
                    type="button"
                    class="h-6 w-6 rounded border border-[#e3e3e0] dark:border-[#3E3E3A]"
                    aria-label="Move one less"
                    @click="
                        splitQuantities[line.id] = Math.max(
                            0,
                            (splitQuantities[line.id] ?? 0) - 1,
                        )
                    "
                >
                    −
                </button>
                <span
                    class="w-6 text-center"
                    :data-test="`split-qty-${line.id}`"
                    >{{ splitQuantities[line.id] ?? 0 }}</span
                >
                <button
                    type="button"
                    class="h-6 w-6 rounded border border-[#e3e3e0] dark:border-[#3E3E3A]"
                    aria-label="Move one more"
                    :data-test="`split-more-${line.id}`"
                    @click="
                        splitQuantities[line.id] = Math.min(
                            line.quantity,
                            (splitQuantities[line.id] ?? 0) + 1,
                        )
                    "
                >
                    +
                </button>
            </div>
            <span
                v-if="errorOf(splitForm, 'items') || errorOf(splitForm, 'bill')"
                class="text-xs text-red-600"
                >{{
                    errorOf(splitForm, 'items') ?? errorOf(splitForm, 'bill')
                }}</span
            >
            <div class="flex justify-end gap-2">
                <button
                    type="button"
                    :class="secondaryButton"
                    @click="splitOpen = false"
                >
                    Cancel
                </button>
                <button
                    type="submit"
                    :class="primaryButton"
                    :disabled="splitForm.processing || splitMoving === 0"
                    data-test="split-submit"
                >
                    Move {{ splitMoving }} item(s) to a new bill
                </button>
            </div>
        </form>
    </Modal>

    <Modal
        :show="rejectTarget !== null"
        :title="`Reject order from table ${rejectTarget?.table ?? ''}`"
        @close="rejectTarget = null"
    >
        <form class="flex flex-col gap-3" @submit.prevent="submitReject">
            <label>
                <span :class="labelClass">Reason (the guest sees it)</span>
                <input
                    v-model="rejectForm.reason"
                    type="text"
                    required
                    maxlength="255"
                    :class="inputClass"
                />
            </label>
            <div class="flex justify-end gap-2">
                <button
                    type="button"
                    :class="secondaryButton"
                    @click="rejectTarget = null"
                >
                    Cancel
                </button>
                <button
                    type="submit"
                    class="rounded-md bg-red-600 px-4 py-2 text-sm font-medium text-white disabled:opacity-40"
                    :disabled="rejectForm.processing || !rejectForm.reason"
                >
                    Reject
                </button>
            </div>
        </form>
    </Modal>

    <Modal
        :show="voidTarget !== null"
        :title="`Void Bill #${voidTarget?.bill_number ?? ''}`"
        @close="voidTarget = null"
    >
        <form class="flex flex-col gap-3" @submit.prevent="submitVoid">
            <p
                v-if="voidTarget?.status === 'posted'"
                class="text-sm dark:text-[#EDEDEC]"
            >
                The charges come off the guest's folio, and any payment taken
                with the bill is cancelled.
            </p>
            <p
                v-else-if="voidTarget?.status === 'settled'"
                class="text-sm dark:text-[#EDEDEC]"
            >
                The bill's payment is cancelled.
            </p>
            <label>
                <span :class="labelClass">Reason</span>
                <input
                    v-model="voidForm.reason"
                    type="text"
                    required
                    maxlength="255"
                    :class="inputClass"
                />
            </label>
            <span
                v-if="voidForm.errors.reason || errorOf(voidForm, 'bill')"
                class="text-xs text-red-600"
                >{{ voidForm.errors.reason ?? errorOf(voidForm, 'bill') }}</span
            >
            <div class="flex justify-end gap-2">
                <button
                    type="button"
                    :class="secondaryButton"
                    @click="voidTarget = null"
                >
                    Cancel
                </button>
                <button
                    type="submit"
                    class="rounded-md bg-red-600 px-4 py-2 text-sm font-medium text-white disabled:opacity-40"
                    :disabled="voidForm.processing || !voidForm.reason"
                >
                    Void
                </button>
            </div>
        </form>
    </Modal>
</template>
