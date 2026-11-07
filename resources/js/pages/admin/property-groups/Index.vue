<script setup lang="ts">
import AdminLayout from '@/layouts/AdminLayout.vue';
import admin from '@/routes/admin';
import type { PropertyGroup } from '@/types';
import { Head, Link } from '@inertiajs/vue3';
import { usePropertyTime } from '@/lib/usePropertyTime';

/** Times arrive in UTC and are shown in the property's timezone. */
const propertyTime = usePropertyTime();

defineOptions({ layout: AdminLayout });

defineProps<{
    propertyGroups: {
        data: (PropertyGroup & { created_at: string })[];
        links: { url: string | null; label: string; active: boolean }[];
    };
}>();
</script>

<template>
    <Head title="Property groups" />

    <div class="mb-6 flex items-center justify-between">
        <h1
            class="font-display text-2xl font-semibold text-[#1b1b18] dark:text-[#EDEDEC]"
        >
            Property groups
        </h1>
        <Link
            :href="admin.propertyGroups.create()"
            class="inline-flex items-center rounded-md border border-black bg-[#1b1b18] px-4 py-2 text-sm font-medium text-white hover:bg-black dark:border-[#eeeeec] dark:bg-[#eeeeec] dark:text-[#1C1C1A] dark:hover:bg-white"
        >
            Create property group
        </Link>
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
                        class="px-4 py-3 text-left text-xs font-medium tracking-wide text-[#706f6c] uppercase dark:text-[#A1A09A]"
                    >
                        Name
                    </th>
                    <th
                        class="px-4 py-3 text-left text-xs font-medium tracking-wide text-[#706f6c] uppercase dark:text-[#A1A09A]"
                    >
                        Created
                    </th>
                    <th class="px-4 py-3"></th>
                </tr>
            </thead>
            <tbody
                class="divide-y divide-[#e3e3e0] bg-white dark:divide-[#3E3E3A] dark:bg-[#161615]"
            >
                <tr
                    v-for="propertyGroup in propertyGroups.data"
                    :key="propertyGroup.id"
                >
                    <td
                        class="px-4 py-3 text-sm text-[#1b1b18] dark:text-[#EDEDEC]"
                    >
                        {{ propertyGroup.name }}
                    </td>
                    <td
                        class="px-4 py-3 text-sm text-[#706f6c] dark:text-[#A1A09A]"
                    >
                        {{ propertyTime.date(propertyGroup.created_at) }}
                    </td>
                    <td class="px-4 py-3 text-right text-sm">
                        <Link
                            :href="admin.propertyGroups.show(propertyGroup)"
                            class="mr-3 font-medium text-[#1b1b18] underline underline-offset-4 dark:text-[#EDEDEC]"
                        >
                            View
                        </Link>
                        <Link
                            :href="admin.propertyGroups.edit(propertyGroup)"
                            class="font-medium text-[#f53003] underline underline-offset-4 dark:text-[#FF4433]"
                        >
                            Edit
                        </Link>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>

    <div
        v-if="propertyGroups.links.length > 3"
        class="mt-4 flex flex-wrap gap-1"
    >
        <template v-for="(link, index) in propertyGroups.links" :key="index">
            <Link
                v-if="link.url"
                :href="link.url"
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
</template>
