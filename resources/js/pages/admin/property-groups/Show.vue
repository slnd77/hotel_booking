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
    propertyGroup: PropertyGroup & { created_at: string; updated_at: string };
}>();
</script>

<template>
    <Head title="Property group" />

    <div class="mb-6 flex items-center justify-between">
        <h1
            class="font-display text-2xl font-semibold text-[#1b1b18] dark:text-[#EDEDEC]"
        >
            {{ propertyGroup.name }}
        </h1>
        <div class="flex items-center gap-3">
            <Link
                :href="admin.propertyGroups.edit(propertyGroup)"
                class="inline-flex items-center rounded-md border border-black bg-[#1b1b18] px-4 py-2 text-sm font-medium text-white hover:bg-black dark:border-[#eeeeec] dark:bg-[#eeeeec] dark:text-[#1C1C1A] dark:hover:bg-white"
            >
                Edit
            </Link>
            <Link
                :href="admin.propertyGroups.index()"
                class="text-sm font-medium text-[#706f6c] dark:text-[#A1A09A]"
            >
                Back
            </Link>
        </div>
    </div>

    <dl
        class="max-w-lg divide-y divide-[#e3e3e0] rounded-lg border border-[#e3e3e0] dark:divide-[#3E3E3A] dark:border-[#3E3E3A]"
    >
        <div class="flex justify-between px-4 py-3">
            <dt class="text-sm font-medium text-[#706f6c] dark:text-[#A1A09A]">
                Name
            </dt>
            <dd class="text-sm text-[#1b1b18] dark:text-[#EDEDEC]">
                {{ propertyGroup.name }}
            </dd>
        </div>
        <div class="flex justify-between px-4 py-3">
            <dt class="text-sm font-medium text-[#706f6c] dark:text-[#A1A09A]">
                Created
            </dt>
            <dd class="text-sm text-[#1b1b18] dark:text-[#EDEDEC]">
                {{ propertyTime.date(propertyGroup.created_at) }}
            </dd>
        </div>
    </dl>
</template>
