<script setup lang="ts">
import AdminLayout from '@/layouts/AdminLayout.vue';
import admin from '@/routes/admin';
import type { Property } from '@/types';
import { Head, Link, router } from '@inertiajs/vue3';

defineOptions({ layout: AdminLayout });

type SortKey = 'name' | 'abbreviation' | 'group' | 'location' | 'created_at';

const props = defineProps<{
    properties: {
        data: Property[];
        links: { url: string | null; label: string; active: boolean }[];
        from: number | null;
        to: number | null;
        total: number;
    };
    sort: SortKey;
    direction: 'asc' | 'desc';
    perPage: number;
    pageSizes: number[];
}>();

const columns: { key: Exclude<SortKey, 'created_at'>; label: string }[] = [
    { key: 'name', label: 'Name' },
    { key: 'abbreviation', label: 'Abbreviation' },
    { key: 'group', label: 'Group' },
    { key: 'location', label: 'Location' },
];

/** Sorting and page size are server-side so they cover every page, not just the visible rows. */
function visit(query: {
    sort: SortKey;
    direction: 'asc' | 'desc';
    per_page: number;
}): void {
    router.get(
        admin.properties.index({ query }).url,
        {},
        { preserveScroll: true, preserveState: true },
    );
}

function sortBy(key: SortKey): void {
    const direction =
        props.sort === key && props.direction === 'asc' ? 'desc' : 'asc';
    visit({ sort: key, direction, per_page: props.perPage });
}

function changePageSize(event: Event): void {
    visit({
        sort: props.sort,
        direction: props.direction,
        per_page: Number((event.target as HTMLSelectElement).value),
    });
}

function sortIndicator(key: SortKey): string {
    if (props.sort !== key) {
        return '↕';
    }

    return props.direction === 'asc' ? '▲' : '▼';
}
</script>

<template>
    <Head title="Properties" />

    <div class="mb-6 flex items-center justify-between">
        <h1
            class="font-display text-2xl font-semibold text-[#1b1b18] dark:text-[#EDEDEC]"
        >
            Properties
        </h1>
        <Link
            :href="admin.properties.create()"
            class="inline-flex items-center rounded-md border border-black bg-[#1b1b18] px-4 py-2 text-sm font-medium text-white hover:bg-black dark:border-[#eeeeec] dark:bg-[#eeeeec] dark:text-[#1C1C1A] dark:hover:bg-white"
        >
            Create property
        </Link>
    </div>

    <div
        class="mb-3 flex items-center gap-2 text-sm text-[#706f6c] dark:text-[#A1A09A]"
    >
        Show
        <select
            :value="perPage"
            class="rounded-md border border-[#e3e3e0] bg-white px-2 py-1 text-sm text-[#1b1b18] focus:border-[#f53003] focus:ring-1 focus:ring-[#f53003] focus:outline-none dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]"
            @change="changePageSize"
        >
            <option v-for="size in pageSizes" :key="size" :value="size">
                {{ size }}
            </option>
        </select>
        entries
    </div>

    <div
        class="overflow-x-auto rounded-lg border border-[#e3e3e0] dark:border-[#3E3E3A]"
    >
        <table
            class="min-w-full divide-y divide-[#e3e3e0] dark:divide-[#3E3E3A]"
        >
            <thead class="bg-[#f5f5f4] dark:bg-[#1D1D1B]">
                <tr>
                    <th
                        v-for="column in columns"
                        :key="column.key"
                        class="px-4 py-3 text-left text-xs font-medium tracking-wide text-[#706f6c] uppercase dark:text-[#A1A09A]"
                        :aria-sort="
                            sort === column.key
                                ? direction === 'asc'
                                    ? 'ascending'
                                    : 'descending'
                                : 'none'
                        "
                    >
                        <button
                            type="button"
                            class="inline-flex items-center gap-1.5 uppercase hover:text-[#1b1b18] dark:hover:text-[#EDEDEC]"
                            :class="{
                                'text-[#1b1b18] dark:text-[#EDEDEC]':
                                    sort === column.key,
                            }"
                            @click="sortBy(column.key)"
                        >
                            {{ column.label }}
                            <span
                                class="text-[10px]"
                                :class="sort === column.key ? '' : 'opacity-40'"
                                >{{ sortIndicator(column.key) }}</span
                            >
                        </button>
                    </th>
                    <th class="px-4 py-3"></th>
                </tr>
            </thead>
            <tbody
                class="divide-y divide-[#e3e3e0] bg-white dark:divide-[#3E3E3A] dark:bg-[#161615]"
            >
                <tr v-if="!properties.data.length">
                    <td
                        colspan="5"
                        class="px-4 py-6 text-center text-sm text-[#706f6c] dark:text-[#A1A09A]"
                    >
                        No properties yet.
                    </td>
                </tr>
                <tr v-for="property in properties.data" :key="property.id">
                    <td
                        class="px-4 py-3 text-sm text-[#1b1b18] dark:text-[#EDEDEC]"
                    >
                        {{ property.name }}
                    </td>
                    <td
                        class="px-4 py-3 text-sm text-[#706f6c] dark:text-[#A1A09A]"
                    >
                        {{ property.abbreviation }}
                    </td>
                    <td
                        class="px-4 py-3 text-sm text-[#706f6c] dark:text-[#A1A09A]"
                    >
                        {{ property.property_group?.name ?? '—' }}
                    </td>
                    <td
                        class="px-4 py-3 text-sm text-[#706f6c] dark:text-[#A1A09A]"
                    >
                        {{
                            [property.city?.name, property.country?.name]
                                .filter(Boolean)
                                .join(', ') || '—'
                        }}
                    </td>
                    <td class="px-4 py-3 text-right text-sm">
                        <Link
                            :href="admin.properties.show(property)"
                            class="mr-3 font-medium text-[#1b1b18] underline underline-offset-4 dark:text-[#EDEDEC]"
                        >
                            View
                        </Link>
                        <Link
                            :href="admin.properties.edit(property)"
                            class="font-medium text-[#f53003] underline underline-offset-4 dark:text-[#FF4433]"
                        >
                            Edit
                        </Link>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>

    <div class="mt-4 flex flex-wrap items-center justify-between gap-3">
        <p class="text-sm text-[#706f6c] dark:text-[#A1A09A]">
            <template v-if="properties.total"
                >Showing {{ properties.from }} to {{ properties.to }} of
                {{ properties.total }} entries</template
            >
        </p>
        <div v-if="properties.links.length > 3" class="flex flex-wrap gap-1">
            <template v-for="(link, index) in properties.links" :key="index">
                <Link
                    v-if="link.url"
                    :href="link.url"
                    preserve-scroll
                    class="rounded-md px-3 py-1.5 text-sm"
                    :class="
                        link.active
                            ? 'bg-[#1b1b18] text-white dark:bg-[#eeeeec] dark:text-[#1C1C1A]'
                            : 'text-[#706f6c] hover:bg-[#f5f5f4] dark:text-[#A1A09A] dark:hover:bg-[#1D1D1B]'
                    "
                    ><span v-html="link.label"
                /></Link>
                <span
                    v-else
                    class="rounded-md px-3 py-1.5 text-sm text-[#A1A09A]"
                    v-html="link.label"
                />
            </template>
        </div>
    </div>
</template>
