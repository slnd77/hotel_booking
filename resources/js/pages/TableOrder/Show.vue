<script setup lang="ts">
import { Head, useForm, usePage } from '@inertiajs/vue3';
import { computed, reactive, ref } from 'vue';

type Item = {
    id: number;
    name: string;
    category: string;
    price: number;
    tax_rate: number;
};
type Order = {
    id: number;
    status: 'pending' | 'accepted' | 'rejected';
    total: number;
    items: { name: string; quantity: number }[];
    reject_reason: string | null;
};

const props = defineProps<{
    token: string;
    outlet: { name: string; property: string };
    table: string;
    ordering: boolean;
    currency: string;
    items: Item[];
    orders: Order[];
}>();

const page = usePage<{ flash?: { success?: string | null } }>();
const search = ref('');
/** item id => quantity in the basket */
const basket = reactive<Record<number, number>>({});

const categories = computed(() => {
    const term = search.value.trim().toLowerCase();
    const groups = new Map<string, Item[]>();

    for (const item of props.items) {
        if (term === '' || item.name.toLowerCase().includes(term)) {
            groups.set(item.category, [
                ...(groups.get(item.category) ?? []),
                item,
            ]);
        }
    }

    return [...groups.entries()];
});

const lines = computed(() =>
    props.items
        .filter((item) => (basket[item.id] ?? 0) > 0)
        .map((item) => ({ item, quantity: basket[item.id] })),
);
/** For the guest's eyes only: the staff's POS prices the order. */
const total = computed(() =>
    lines.value.reduce(
        (sum, line) =>
            sum +
            line.item.price * line.quantity * (1 + line.item.tax_rate / 100),
        0,
    ),
);

function change(item: Item, step: number): void {
    basket[item.id] = Math.min(20, Math.max(0, (basket[item.id] ?? 0) + step));
}

const form = useForm({
    guest_name: '',
    notes: '',
    items: [] as { outlet_item_id: number; quantity: number }[],
});

function send(): void {
    form.items = lines.value.map((line) => ({
        outlet_item_id: line.item.id,
        quantity: line.quantity,
    }));
    form.post(`/order/${props.token}`, {
        preserveScroll: true,
        onSuccess: () => {
            Object.keys(basket).forEach((key) => delete basket[Number(key)]);
            form.reset('notes', 'items');
        },
    });
}

function money(value: number): string {
    return `${props.currency} ${value.toFixed(2)}`;
}

const statusLabel: Record<Order['status'], string> = {
    pending: 'Waiting for the staff',
    accepted: 'Confirmed',
    rejected: 'Not accepted',
};
const statusClass: Record<Order['status'], string> = {
    pending: 'bg-amber-100 text-amber-800',
    accepted: 'bg-emerald-100 text-emerald-800',
    rejected: 'bg-red-100 text-red-700',
};
</script>

<template>
    <Head :title="`${outlet.name} — Table ${table}`" />

    <div class="min-h-screen bg-[#f5f5f4] pb-40">
        <header class="bg-[#1b1b18] px-4 py-4 text-white">
            <p class="text-xs tracking-wide text-white/70 uppercase">
                {{ outlet.property }}
            </p>
            <h1 class="text-xl font-semibold">{{ outlet.name }}</h1>
            <p class="text-sm text-white/80">Table {{ table }}</p>
        </header>

        <main class="mx-auto flex max-w-xl flex-col gap-4 px-4 py-4">
            <p
                v-if="page.props.flash?.success"
                class="rounded-md bg-emerald-50 px-3 py-2 text-sm text-emerald-800"
                role="status"
            >
                {{ page.props.flash.success }}
            </p>
            <p
                v-if="!ordering"
                class="rounded-md bg-amber-50 px-3 py-2 text-sm text-amber-800"
            >
                Ordering from the table is not available right now. Please ask
                the staff to take your order.
            </p>

            <section
                v-if="orders.length"
                class="rounded-lg border border-[#e3e3e0] bg-white p-3"
            >
                <h2 class="mb-2 text-sm font-semibold text-[#1b1b18]">
                    Your orders
                </h2>
                <ul class="flex flex-col gap-2 text-sm">
                    <li
                        v-for="order in orders"
                        :key="order.id"
                        class="flex flex-wrap items-center justify-between gap-2"
                    >
                        <span class="text-[#1b1b18]">{{
                            order.items
                                .map(
                                    (line) => `${line.quantity} × ${line.name}`,
                                )
                                .join(', ')
                        }}</span>
                        <span
                            class="rounded px-2 py-0.5 text-xs font-medium"
                            :class="statusClass[order.status]"
                            >{{ statusLabel[order.status] }}</span
                        >
                        <span
                            v-if="order.reject_reason"
                            class="w-full text-xs text-red-700"
                            >{{ order.reject_reason }}</span
                        >
                    </li>
                </ul>
            </section>

            <input
                v-model="search"
                type="search"
                placeholder="Search the menu"
                class="w-full rounded-md border border-[#e3e3e0] bg-white px-3 py-2 text-sm"
            />

            <p v-if="!categories.length" class="text-sm text-[#706f6c]">
                Nothing on the menu matches.
            </p>

            <section
                v-for="[category, categoryItems] in categories"
                :key="category"
            >
                <h2
                    class="mb-2 text-xs font-semibold tracking-wide text-[#706f6c] uppercase"
                >
                    {{ category }}
                </h2>
                <ul
                    class="divide-y divide-[#e3e3e0] rounded-lg border border-[#e3e3e0] bg-white"
                >
                    <li
                        v-for="item in categoryItems"
                        :key="item.id"
                        class="flex items-center justify-between gap-3 px-3 py-3"
                    >
                        <div class="min-w-0">
                            <p class="font-medium text-[#1b1b18]">
                                {{ item.name }}
                            </p>
                            <p class="text-xs text-[#706f6c]">
                                {{ money(item.price)
                                }}<template v-if="item.tax_rate">
                                    + {{ item.tax_rate }}% tax</template
                                >
                            </p>
                        </div>
                        <div
                            v-if="ordering"
                            class="flex shrink-0 items-center gap-2"
                        >
                            <button
                                v-if="basket[item.id]"
                                type="button"
                                class="h-8 w-8 rounded-full border border-[#e3e3e0] text-lg"
                                :aria-label="`One less ${item.name}`"
                                @click="change(item, -1)"
                            >
                                −
                            </button>
                            <span
                                v-if="basket[item.id]"
                                class="w-5 text-center text-sm font-semibold"
                                >{{ basket[item.id] }}</span
                            >
                            <button
                                type="button"
                                class="h-8 rounded-full bg-[#1b1b18] px-3 text-sm font-semibold text-white"
                                :aria-label="`Add ${item.name}`"
                                @click="change(item, 1)"
                            >
                                {{ basket[item.id] ? '+' : 'Add' }}
                            </button>
                        </div>
                    </li>
                </ul>
            </section>
        </main>

        <div
            v-if="ordering && lines.length"
            class="fixed inset-x-0 bottom-0 border-t border-[#e3e3e0] bg-white px-4 py-3 shadow-lg"
        >
            <form
                class="mx-auto flex max-w-xl flex-col gap-2"
                @submit.prevent="send"
            >
                <div class="flex gap-2">
                    <input
                        v-model="form.guest_name"
                        type="text"
                        maxlength="100"
                        placeholder="Your name (optional)"
                        class="w-1/2 rounded-md border border-[#e3e3e0] px-3 py-2 text-sm"
                    />
                    <input
                        v-model="form.notes"
                        type="text"
                        maxlength="500"
                        placeholder="Notes for the kitchen"
                        class="w-1/2 rounded-md border border-[#e3e3e0] px-3 py-2 text-sm"
                    />
                </div>
                <p
                    v-for="(message, key) in form.errors"
                    :key="key"
                    class="text-xs text-red-600"
                >
                    {{ message }}
                </p>
                <button
                    type="submit"
                    data-test="send-order"
                    :disabled="form.processing"
                    class="flex items-center justify-between rounded-md bg-[#1b1b18] px-4 py-3 text-sm font-semibold text-white disabled:opacity-60"
                >
                    <span
                        >Send order ·
                        {{
                            lines.reduce(
                                (count, line) => count + line.quantity,
                                0,
                            )
                        }}
                        item(s)</span
                    >
                    <span>≈ {{ money(total) }}</span>
                </button>
            </form>
        </div>
    </div>
</template>
