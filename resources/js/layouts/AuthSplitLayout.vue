<script setup lang="ts">
/**
 * Log in, create account, forgot / reset password: the product showcase on the left (wide
 * screens), the form on the right. Follows the system's light or dark mode.
 */
/** The Smart Booking Chart's bars: in house, confirmed, on hold, and blocked / out of order. */
type Booking = {
    start: number;
    span: number;
    label: string;
    tone: 'in_house' | 'confirmed' | 'hold' | 'blocked';
    openStart?: boolean;
    openEnd?: boolean;
};

const days = [
    'Mon 19',
    'Tue 20',
    'Wed 21',
    'Thu 22',
    'Fri 23',
    'Sat 24',
    'Sun 25',
];
const rows: { room: string; bookings: Booking[] }[] = [
    {
        room: '101',
        bookings: [
            { start: 2, span: 3, label: 'OKAFOR', tone: 'in_house' },
            { start: 6, span: 2, label: 'REYES', tone: 'confirmed' },
        ],
    },
    {
        room: '102',
        bookings: [
            {
                start: 2,
                span: 2,
                label: 'LINDQVIST',
                tone: 'in_house',
                openStart: true,
            },
            { start: 5, span: 3, label: 'AHMED', tone: 'confirmed' },
        ],
    },
    {
        room: '103',
        bookings: [{ start: 3, span: 4, label: 'TANAKA', tone: 'confirmed' }],
    },
    {
        room: '204',
        bookings: [
            { start: 2, span: 2, label: 'BLOCKED', tone: 'blocked' },
            { start: 5, span: 2, label: 'MOREAU (ON HOLD)', tone: 'hold' },
        ],
    },
    {
        room: '205',
        bookings: [
            {
                start: 2,
                span: 5,
                label: 'BRENNAN',
                tone: 'in_house',
                openStart: true,
            },
        ],
    },
    {
        room: '301',
        bookings: [
            { start: 4, span: 2, label: 'SILVA', tone: 'confirmed' },
            {
                start: 7,
                span: 2,
                label: 'KOWALSKI (ON HOLD)',
                tone: 'hold',
                openEnd: true,
            },
        ],
    },
];

const toneClass: Record<Booking['tone'], string> = {
    in_house: 'bg-[#16a34a] text-white',
    confirmed: 'bg-[#ea580c] text-white',
    hold: 'bg-[#d97706] text-white',
    blocked:
        'text-[#1b1b18] bg-[repeating-linear-gradient(45deg,#cbd5e1_0_6px,#94a3b8_6px_12px)]',
};

function bookingClass(booking: Booking): string {
    const radius = booking.openStart
        ? 'rounded-r-md'
        : booking.openEnd
          ? 'rounded-l-md'
          : 'rounded-md';

    return `${toneClass[booking.tone]} ${radius}`;
}
</script>

<template>
    <div
        class="flex min-h-screen w-full bg-[#FDFDFC] font-sans text-[#1b1b18] antialiased dark:bg-[#0a0a0a] dark:text-[#EDEDEC]"
    >
        <aside
            aria-label="Vasuttam PMS product overview"
            class="relative hidden min-h-screen w-[54%] max-w-[54%] flex-none flex-col overflow-hidden bg-[#1b1b18] px-14 pt-10 pb-9 text-[#EDEDEC] lg:flex dark:border-r dark:border-[#3E3E3A] dark:bg-[#161615]"
        >
            <div
                aria-hidden="true"
                class="pointer-events-none absolute inset-0 [background-image:linear-gradient(rgba(255,250,237,0.07)_1px,transparent_1px),linear-gradient(90deg,rgba(255,250,237,0.07)_1px,transparent_1px)] [mask-image:radial-gradient(ellipse_85%_75%_at_25%_35%,#000_25%,transparent_80%)] [background-size:56px_56px] [background-position:-1px_-1px]"
            ></div>
            <div
                aria-hidden="true"
                class="pointer-events-none absolute -right-[18%] -bottom-[28%] aspect-square w-3/4 rounded-full bg-[radial-gradient(circle,rgba(245,48,3,0.2)_0%,transparent_62%)]"
            ></div>

            <header class="relative flex items-center gap-3">
                <img
                    src="/brand/hospitality-mark-64.webp"
                    srcset="
                        /brand/hospitality-mark-64.webp  1x,
                        /brand/hospitality-mark-128.webp 2x
                    "
                    width="40"
                    height="40"
                    alt=""
                    decoding="async"
                    class="block h-10 w-10 shrink-0"
                />
                <span class="font-display text-[19px] font-bold tracking-tight"
                    >Vasuttam PMS</span
                >
            </header>

            <div
                class="relative flex max-w-[620px] flex-1 flex-col justify-center gap-9 py-14"
            >
                <div class="flex flex-col gap-5">
                    <span
                        class="text-[13px] font-semibold tracking-[0.14em] text-[#A1A09A] uppercase"
                        >Property management for hotel groups</span
                    >
                    <h2
                        class="font-display m-0 text-[clamp(40px,3.7vw,60px)] leading-[1.04] font-bold tracking-[-0.035em] text-balance"
                    >
                        Every room, folio, and front-desk shift—<span
                            class="text-[#f53003] dark:text-[#FF4433]"
                            >orchestrated</span
                        >
                        in one place.
                    </h2>
                    <p
                        class="m-0 max-w-[48ch] text-[17px] leading-relaxed text-pretty text-[#A1A09A]"
                    >
                        Tape charts, folio and ledger accounting, POS outlets,
                        night audit and channel management for every property in
                        your portfolio.
                    </p>
                </div>

                <div
                    class="rounded-2xl border border-white/14 bg-white/[0.04] p-[18px] pb-4 shadow-[0_30px_60px_-30px_rgba(0,0,0,0.45)] backdrop-blur-md"
                >
                    <div class="mb-3.5 flex items-center justify-between gap-3">
                        <div class="flex min-w-0 items-baseline gap-2.5">
                            <span class="font-display text-sm font-medium"
                                >Tape chart</span
                            >
                            <span class="truncate text-[13px] text-[#A1A09A]"
                                >Harbor House · Oct 19–25</span
                            >
                        </div>
                        <span
                            class="flex items-center gap-1.5 text-xs text-[#A1A09A]"
                            ><span
                                class="h-1.5 w-1.5 rounded-full bg-green-500"
                            ></span
                            >Live</span
                        >
                    </div>

                    <div
                        class="grid grid-cols-[44px_repeat(7,minmax(0,1fr))] pb-2 text-[11px] tracking-[0.02em] text-[#A1A09A]"
                    >
                        <span></span>
                        <span
                            v-for="(day, index) in days"
                            :key="day"
                            :class="
                                index === 0
                                    ? 'font-semibold text-[#f53003] dark:text-[#FF4433]'
                                    : ''
                            "
                            >{{ day }}</span
                        >
                    </div>

                    <div
                        v-for="row in rows"
                        :key="row.room"
                        class="grid h-8 grid-cols-[44px_repeat(7,minmax(0,1fr))] items-center gap-x-[3px] border-t border-white/[0.08]"
                    >
                        <span
                            class="col-start-1 row-start-1 text-xs text-[#A1A09A]"
                            >{{ row.room }}</span
                        >
                        <span
                            v-for="booking in row.bookings"
                            :key="booking.label"
                            class="row-start-1 flex h-[22px] items-center overflow-hidden px-2 text-[11px] font-semibold whitespace-nowrap"
                            :class="bookingClass(booking)"
                            :style="{
                                gridColumn: `${booking.start} / span ${booking.span}`,
                            }"
                        >
                            {{ booking.label }}
                        </span>
                    </div>
                </div>

                <div
                    class="-mt-6 flex flex-wrap gap-x-4 gap-y-1 text-xs text-[#A1A09A]"
                >
                    <span class="flex items-center gap-1.5"
                        ><span
                            class="h-2.5 w-2.5 rounded-sm bg-[#16a34a]"
                        ></span
                        >In house</span
                    >
                    <span class="flex items-center gap-1.5"
                        ><span
                            class="h-2.5 w-2.5 rounded-sm bg-[#ea580c]"
                        ></span
                        >Confirmed</span
                    >
                    <span class="flex items-center gap-1.5"
                        ><span
                            class="h-2.5 w-2.5 rounded-sm bg-[#d97706]"
                        ></span
                        >On hold</span
                    >
                    <span class="flex items-center gap-1.5"
                        ><span
                            class="h-2.5 w-2.5 rounded-sm bg-[repeating-linear-gradient(45deg,#cbd5e1_0_3px,#94a3b8_3px_6px)]"
                        ></span
                        >Blocked</span
                    >
                </div>

                <div class="flex flex-wrap gap-2">
                    <span
                        class="flex items-center gap-2 rounded-full border border-white/14 bg-white/[0.04] px-3 py-1.5 text-[13px]"
                        ><span class="font-display font-bold">94%</span
                        ><span class="text-[#A1A09A]">Occupancy</span></span
                    >
                    <span
                        class="flex items-center gap-2 rounded-full border border-white/14 bg-white/[0.04] px-3 py-1.5 text-[13px]"
                        ><span
                            class="h-1.5 w-1.5 rounded-full bg-green-500"
                        ></span
                        >Front Desk Synced</span
                    >
                    <span
                        class="flex items-center gap-2 rounded-full border border-white/14 bg-white/[0.04] px-3 py-1.5 text-[13px]"
                        ><span
                            class="h-1.5 w-1.5 rounded-full bg-[#f53003] dark:bg-[#FF4433]"
                        ></span
                        >Night Audit Ready</span
                    >
                </div>
            </div>

            <footer
                class="relative flex flex-wrap items-center justify-between gap-3 border-t border-white/[0.08] pt-5 text-[13px] text-[#A1A09A]"
            >
                <span class="flex items-center gap-2"
                    ><span
                        class="h-[7px] w-[7px] rounded-full bg-green-500 shadow-[0_0_0_3px_rgba(34,197,94,0.18)]"
                    ></span
                    >All systems operational</span
                >
                <span class="flex items-center gap-2"
                    ><span
                        aria-hidden="true"
                        class="h-[7px] w-[7px] rotate-45 border-[1.5px] border-current"
                    ></span
                    >Property-scoped access · Encrypted in transit</span
                >
            </footer>
        </aside>

        <main class="flex min-h-screen min-w-0 flex-1 flex-col">
            <div class="flex min-h-9 items-center px-6 pt-6 sm:px-10 lg:hidden">
                <div class="flex items-center gap-2.5">
                    <img
                        src="/brand/hospitality-mark-64.webp"
                        srcset="
                            /brand/hospitality-mark-64.webp  1x,
                            /brand/hospitality-mark-128.webp 2x
                        "
                        width="34"
                        height="34"
                        alt=""
                        decoding="async"
                        class="block h-[34px] w-[34px] shrink-0"
                    />
                    <span
                        class="font-display text-[17px] font-bold tracking-tight"
                        >Vasuttam PMS</span
                    >
                </div>
            </div>

            <div
                class="flex flex-1 items-center justify-center px-6 pt-10 pb-14 sm:px-10 lg:px-16"
            >
                <div class="flex w-full max-w-[448px] flex-col gap-7">
                    <slot />
                </div>
            </div>
        </main>
    </div>
</template>
