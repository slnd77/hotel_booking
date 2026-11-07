<script setup lang="ts">
import {
    computed,
    nextTick,
    onBeforeUnmount,
    onMounted,
    ref,
    watch,
} from 'vue';

export type AmenityCategoryGroup = {
    category: string;
    amenities: string[];
};

export type RoomDetails = {
    name: string;
    description: string | null;
    inclusion_details: string | null;
    max_adults: number;
    max_children: number;
    ideal_adults: number;
    ideal_children: number;
    images: { id: number; url: string }[];
    bed_types: { name: string; quantity: number }[];
    amenities: string[];
    categorized_amenities?: AmenityCategoryGroup[];
};

const props = defineProps<{
    show: boolean;
    room: RoomDetails | null;
    cancellationPolicy: string[];
    termsAndConditions?: string | null;
    initialTab?: Tab;
    initialImageIndex?: number;
}>();

const emit = defineEmits<{
    close: [];
}>();

type Tab =
    | 'images'
    | 'room_details'
    | 'inclusions'
    | 'cancellation_policy'
    | 'terms'
    | 'amenities'
    | 'bed_types';

const tabs: { key: Tab; label: string }[] = [
    { key: 'images', label: 'Images' },
    { key: 'room_details', label: 'Room Details' },
    { key: 'inclusions', label: 'Inclusions' },
    { key: 'cancellation_policy', label: 'Cancellation Policy' },
    { key: 'terms', label: 'T&C' },
    { key: 'amenities', label: 'Amenities' },
    { key: 'bed_types', label: 'Bed Types' },
];

const activeTab = ref<Tab>('images');
const selectedImageIndex = ref<number>(0);
const tabsContainerRef = ref<HTMLElement | null>(null);
const bottomScrollRef = ref<HTMLElement | null>(null);
const tabsScrollWidth = ref(0);
const isOverflowing = ref(false);

const innerScrollWidth = computed(() => {
    return Math.max(tabsScrollWidth.value - 48, 1);
});

let isSyncingScroll = false;
let resizeObserver: ResizeObserver | null = null;

function updateScrollWidth(): void {
    if (tabsContainerRef.value) {
        tabsScrollWidth.value = tabsContainerRef.value.scrollWidth;
        isOverflowing.value =
            tabsContainerRef.value.scrollWidth >
            tabsContainerRef.value.clientWidth;
    }
}

function prevGalleryImage(): void {
    if (!props.room || props.room.images.length <= 1) return;
    selectedImageIndex.value =
        (selectedImageIndex.value - 1 + props.room.images.length) %
        props.room.images.length;
}

function nextGalleryImage(): void {
    if (!props.room || props.room.images.length <= 1) return;
    selectedImageIndex.value =
        (selectedImageIndex.value + 1) % props.room.images.length;
}

watch(
    () => props.show,
    async (show) => {
        if (show) {
            activeTab.value = props.initialTab || 'images';
            selectedImageIndex.value = props.initialImageIndex ?? 0;
            await nextTick();
            updateScrollWidth();
            setTimeout(updateScrollWidth, 60);
            if (tabsContainerRef.value) {
                tabsContainerRef.value.scrollLeft = 0;
                if (!resizeObserver) {
                    resizeObserver = new ResizeObserver(() => {
                        updateScrollWidth();
                    });
                    resizeObserver.observe(tabsContainerRef.value);
                }
            }
            if (bottomScrollRef.value) {
                bottomScrollRef.value.scrollLeft = 0;
            }
        } else {
            resizeObserver?.disconnect();
            resizeObserver = null;
        }
    },
);

function onTabsScroll(): void {
    if (isSyncingScroll) return;
    if (!tabsContainerRef.value || !bottomScrollRef.value) return;
    isSyncingScroll = true;
    bottomScrollRef.value.scrollLeft = tabsContainerRef.value.scrollLeft;
    requestAnimationFrame(() => {
        isSyncingScroll = false;
    });
}

function onBottomScroll(): void {
    if (isSyncingScroll) return;
    if (!tabsContainerRef.value || !bottomScrollRef.value) return;
    isSyncingScroll = true;
    tabsContainerRef.value.scrollLeft = bottomScrollRef.value.scrollLeft;
    requestAnimationFrame(() => {
        isSyncingScroll = false;
    });
}

function selectTab(key: Tab, event: MouseEvent): void {
    activeTab.value = key;
    (event.currentTarget as HTMLElement | null)?.scrollIntoView({
        behavior: 'smooth',
        block: 'nearest',
        inline: 'nearest',
    });
}

function onTabsWheel(event: WheelEvent): void {
    if (!tabsContainerRef.value) return;
    if (Math.abs(event.deltaX) > Math.abs(event.deltaY)) return;
    tabsContainerRef.value.scrollLeft += event.deltaY;
}

function close(): void {
    emit('close');
}

function onKeydown(event: KeyboardEvent): void {
    if (event.key === 'Escape' && props.show) {
        close();
    }
}

onMounted(() => window.addEventListener('keydown', onKeydown));
onBeforeUnmount(() => {
    window.removeEventListener('keydown', onKeydown);
    resizeObserver?.disconnect();
    resizeObserver = null;
});
</script>

<template>
    <div
        v-if="show && room"
        class="fixed inset-0 z-50 flex items-center justify-center bg-black/30 p-4"
        @click.self="close"
    >
        <div
            class="flex max-h-[85vh] min-h-[320px] w-full max-w-2xl flex-col overflow-hidden rounded-lg border border-[#e3e3e0] bg-white shadow-xl"
        >
            <div
                class="flex items-center justify-between bg-[#1b1b18] px-6 py-3"
            >
                <h2 class="font-display text-base font-semibold text-white">
                    {{ room.name }}
                </h2>
                <button
                    type="button"
                    class="text-white/80 hover:text-white"
                    @click="close"
                >
                    <svg
                        xmlns="http://www.w3.org/2000/svg"
                        class="h-5 w-5"
                        fill="none"
                        viewBox="0 0 24 24"
                        stroke="currentColor"
                    >
                        <path
                            stroke-linecap="round"
                            stroke-linejoin="round"
                            stroke-width="2"
                            d="M6 18L18 6M6 6l12 12"
                        />
                    </svg>
                </button>
            </div>

            <div
                ref="tabsContainerRef"
                class="no-scrollbar flex items-center gap-4 overflow-x-auto border-b border-[#e3e3e0] px-6 pt-3 text-sm"
                @scroll="onTabsScroll"
                @wheel.passive="onTabsWheel"
            >
                <button
                    v-for="tab in tabs"
                    :key="tab.key"
                    type="button"
                    class="shrink-0 border-b-2 pb-2 font-medium tracking-wide whitespace-nowrap uppercase transition-colors"
                    :class="
                        activeTab === tab.key
                            ? 'border-[#f53003] text-[#f53003]'
                            : 'border-transparent text-[#706f6c] hover:text-[#1b1b18]'
                    "
                    @click="selectTab(tab.key, $event)"
                >
                    {{ tab.label }}
                </button>
            </div>

            <div class="flex-1 overflow-y-auto px-6 py-5">
                <div v-if="activeTab === 'images'">
                    <div v-if="room.images.length" class="space-y-3">
                        <!-- Main Featured Image -->
                        <div
                            class="relative flex h-64 w-full items-center justify-center overflow-hidden rounded-lg bg-[#1b1b18] sm:h-80"
                        >
                            <img
                                :src="
                                    room.images[selectedImageIndex]?.url ||
                                    room.images[0]?.url
                                "
                                :alt="room.name"
                                class="h-full w-full object-contain"
                            />

                            <!-- Navigation Arrows -->
                            <button
                                v-if="room.images.length > 1"
                                type="button"
                                class="absolute top-1/2 left-2 flex h-8 w-8 -translate-y-1/2 items-center justify-center rounded-full bg-black/60 text-white transition hover:bg-black/80 focus:outline-none"
                                aria-label="Previous image"
                                @click.stop="prevGalleryImage"
                            >
                                <svg
                                    xmlns="http://www.w3.org/2000/svg"
                                    class="h-5 w-5"
                                    fill="none"
                                    viewBox="0 0 24 24"
                                    stroke="currentColor"
                                >
                                    <path
                                        stroke-linecap="round"
                                        stroke-linejoin="round"
                                        stroke-width="2.5"
                                        d="M15 19l-7-7 7-7"
                                    />
                                </svg>
                            </button>
                            <button
                                v-if="room.images.length > 1"
                                type="button"
                                class="absolute top-1/2 right-2 flex h-8 w-8 -translate-y-1/2 items-center justify-center rounded-full bg-black/60 text-white transition hover:bg-black/80 focus:outline-none"
                                aria-label="Next image"
                                @click.stop="nextGalleryImage"
                            >
                                <svg
                                    xmlns="http://www.w3.org/2000/svg"
                                    class="h-5 w-5"
                                    fill="none"
                                    viewBox="0 0 24 24"
                                    stroke="currentColor"
                                >
                                    <path
                                        stroke-linecap="round"
                                        stroke-linejoin="round"
                                        stroke-width="2.5"
                                        d="M9 5l7 7-7 7"
                                    />
                                </svg>
                            </button>

                            <!-- Counter Badge -->
                            <div
                                class="absolute right-2 bottom-2 rounded bg-black/70 px-2 py-0.5 text-xs font-medium text-white"
                            >
                                {{ selectedImageIndex + 1 }} /
                                {{ room.images.length }}
                            </div>
                        </div>

                        <!-- Thumbnail Strip -->
                        <div
                            v-if="room.images.length > 1"
                            class="no-scrollbar flex gap-2 overflow-x-auto pt-1 pb-1"
                        >
                            <button
                                v-for="(img, idx) in room.images"
                                :key="img.id"
                                type="button"
                                class="shrink-0 overflow-hidden rounded-md transition-all focus:outline-none"
                                :class="
                                    selectedImageIndex === idx
                                        ? 'scale-105 opacity-100 ring-2 ring-[#f53003]'
                                        : 'opacity-70 hover:opacity-100'
                                "
                                @click="selectedImageIndex = idx"
                            >
                                <img
                                    :src="img.url"
                                    :alt="room.name"
                                    class="h-16 w-20 rounded object-cover"
                                />
                            </button>
                        </div>
                    </div>
                    <p v-else class="text-sm text-[#706f6c]">
                        No images uploaded for this room yet.
                    </p>
                </div>

                <div
                    v-else-if="activeTab === 'room_details'"
                    class="flex flex-col gap-3 text-sm text-[#1b1b18]"
                >
                    <p v-if="room.description">{{ room.description }}</p>
                    <p v-else class="text-[#706f6c]">
                        No description added for this room yet.
                    </p>
                    <dl class="grid grid-cols-2 gap-2 text-sm">
                        <div>
                            <dt class="text-[#706f6c]">Max Adults</dt>
                            <dd>{{ room.max_adults }}</dd>
                        </div>
                        <div>
                            <dt class="text-[#706f6c]">Max Children</dt>
                            <dd>{{ room.max_children }}</dd>
                        </div>
                        <div>
                            <dt class="text-[#706f6c]">Ideal Adults</dt>
                            <dd>{{ room.ideal_adults }}</dd>
                        </div>
                        <div>
                            <dt class="text-[#706f6c]">Ideal Children</dt>
                            <dd>{{ room.ideal_children }}</dd>
                        </div>
                    </dl>
                </div>

                <div
                    v-else-if="activeTab === 'inclusions'"
                    class="text-sm text-[#1b1b18]"
                >
                    <div
                        v-if="room.inclusion_details"
                        class="ql-editor-content leading-relaxed text-[#1b1b18]"
                        v-html="room.inclusion_details"
                    />
                    <p v-else class="text-[#706f6c]">
                        No inclusions listed for this room yet.
                    </p>
                </div>

                <div
                    v-else-if="activeTab === 'cancellation_policy'"
                    class="text-sm text-[#1b1b18]"
                >
                    <ul v-if="cancellationPolicy.length" class="list-disc pl-5">
                        <li v-for="line in cancellationPolicy" :key="line">
                            {{ line }}
                        </li>
                    </ul>
                    <p v-else class="text-[#706f6c]">
                        No cancellation policy is configured for these dates.
                    </p>
                </div>

                <div
                    v-else-if="activeTab === 'terms'"
                    class="text-sm text-[#1b1b18]"
                >
                    <div
                        v-if="termsAndConditions"
                        class="ql-editor-content leading-relaxed text-[#1b1b18]"
                        v-html="termsAndConditions"
                    />
                    <p v-else class="text-[#706f6c]">
                        Terms &amp; Conditions have not been added for this
                        property yet.
                    </p>
                </div>

                <div
                    v-else-if="activeTab === 'amenities'"
                    class="text-sm text-[#1b1b18]"
                >
                    <div
                        v-if="
                            room.categorized_amenities &&
                            room.categorized_amenities.length
                        "
                        class="flex flex-col gap-5"
                    >
                        <div
                            v-for="group in room.categorized_amenities"
                            :key="group.category"
                            class="flex flex-col gap-2"
                        >
                            <h4
                                class="border-b border-[#e3e3e0] pb-1.5 text-sm font-semibold text-[#1b1b18]"
                            >
                                {{ group.category }}
                            </h4>
                            <ul
                                class="grid grid-cols-1 gap-x-6 gap-y-1.5 sm:grid-cols-2"
                            >
                                <li
                                    v-for="amenity in group.amenities"
                                    :key="amenity"
                                    class="flex items-start gap-2 text-sm text-[#1b1b18]"
                                >
                                    <span
                                        class="mt-1.5 h-1.5 w-1.5 shrink-0 rounded-full bg-[#706f6c]"
                                    />
                                    <span>{{ amenity }}</span>
                                </li>
                            </ul>
                        </div>
                    </div>
                    <ul
                        v-else-if="room.amenities.length"
                        class="grid grid-cols-2 gap-1"
                    >
                        <li v-for="amenity in room.amenities" :key="amenity">
                            {{ amenity }}
                        </li>
                    </ul>
                    <p v-else class="text-[#706f6c]">
                        No amenities listed for this room yet.
                    </p>
                </div>

                <div
                    v-else-if="activeTab === 'bed_types'"
                    class="text-sm text-[#1b1b18]"
                >
                    <ul v-if="room.bed_types.length" class="list-disc pl-5">
                        <li v-for="bed in room.bed_types" :key="bed.name">
                            {{ bed.name }} - {{ bed.quantity }}
                        </li>
                    </ul>
                    <p v-else class="text-[#706f6c]">
                        No bed types configured for this room yet.
                    </p>
                </div>
            </div>

            <div
                v-show="isOverflowing"
                ref="bottomScrollRef"
                class="bottom-scroll overflow-x-auto border-t border-[#e3e3e0] bg-[#fafaf9] px-6 py-1.5"
                @scroll="onBottomScroll"
                @wheel.passive="onTabsWheel"
            >
                <div
                    :style="{ width: innerScrollWidth + 'px', height: '8px' }"
                    class="shrink-0"
                />
            </div>
        </div>
    </div>
</template>

<style scoped>
.no-scrollbar {
    -ms-overflow-style: none;
    scrollbar-width: none;
}
.no-scrollbar::-webkit-scrollbar {
    display: none;
}

.bottom-scroll {
    scrollbar-width: thin;
    scrollbar-color: #9c9a92 #e8e8e5;
}

.bottom-scroll::-webkit-scrollbar {
    height: 9px;
}

.bottom-scroll::-webkit-scrollbar-track {
    background: #e8e8e5;
    border-radius: 9999px;
}

.bottom-scroll::-webkit-scrollbar-thumb {
    background: #9c9a92;
    border-radius: 9999px;
}

.bottom-scroll::-webkit-scrollbar-thumb:hover {
    background: #6b6a65;
}

.ql-editor-content :deep(p) {
    margin-bottom: 0.5rem;
}
.ql-editor-content :deep(p:last-child) {
    margin-bottom: 0;
}
.ql-editor-content :deep(ul) {
    list-style-type: disc;
    padding-left: 1.25rem;
    margin-bottom: 0.5rem;
}
.ql-editor-content :deep(ol) {
    list-style-type: decimal;
    padding-left: 1.25rem;
    margin-bottom: 0.5rem;
}
.ql-editor-content :deep(li) {
    margin-bottom: 0.25rem;
}
.ql-editor-content :deep(h1) {
    font-size: 1.25rem;
    font-weight: 700;
    margin-top: 0.75rem;
    margin-bottom: 0.5rem;
}
.ql-editor-content :deep(h2) {
    font-size: 1.1rem;
    font-weight: 600;
    margin-top: 0.75rem;
    margin-bottom: 0.5rem;
}
.ql-editor-content :deep(h3) {
    font-size: 1rem;
    font-weight: 600;
    margin-top: 0.5rem;
    margin-bottom: 0.25rem;
}
.ql-editor-content :deep(blockquote) {
    border-left: 3px solid #cbd5e1;
    padding-left: 0.75rem;
    color: #64748b;
    margin: 0.5rem 0;
}
.ql-editor-content :deep(a) {
    color: #2563eb;
    text-decoration: underline;
}
.ql-editor-content :deep(.ql-align-center) {
    text-align: center;
}
.ql-editor-content :deep(.ql-align-right) {
    text-align: right;
}
.ql-editor-content :deep(.ql-align-justify) {
    text-align: justify;
}
.ql-editor-content :deep(.ql-indent-1) {
    padding-left: 1.5rem;
}
.ql-editor-content :deep(.ql-indent-2) {
    padding-left: 3rem;
}
</style>
