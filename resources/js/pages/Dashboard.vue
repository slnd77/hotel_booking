<script setup lang="ts">
import DashboardLayout from '@/layouts/DashboardLayout.vue';
import admin from '@/routes/admin';
import { Head, Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

defineOptions({ layout: DashboardLayout });

const page = usePage();

const isAdmin = computed(
    () =>
        page.props.auth.user?.roles?.some((role) => role.name === 'admin') ??
        false,
);
</script>

<template>
    <Head title="Dashboard" />

    <h1
        class="font-display mb-6 text-2xl font-semibold text-[#1b1b18] dark:text-[#EDEDEC]"
    >
        User dashboard panel
    </h1>

    <p class="mb-6 max-w-lg text-sm text-[#706f6c] dark:text-[#A1A09A]">
        Welcome back, {{ page.props.auth.user?.name }}.
    </p>

    <div
        v-if="isAdmin"
        class="max-w-sm rounded-lg border border-[#e3e3e0] bg-white p-5 dark:border-[#3E3E3A] dark:bg-[#161615]"
    >
        <p class="text-sm font-medium text-[#1b1b18] dark:text-[#EDEDEC]">
            Admin panel
        </p>
        <p class="mt-1 mb-4 text-sm text-[#706f6c] dark:text-[#A1A09A]">
            Manage users, property groups, and properties.
        </p>
        <Link
            :href="admin.dashboard()"
            class="inline-flex items-center rounded-md border border-black bg-[#1b1b18] px-4 py-2 text-sm font-medium text-white hover:bg-black dark:border-[#eeeeec] dark:bg-[#eeeeec] dark:text-[#1C1C1A] dark:hover:bg-white"
        >
            Go to admin panel
        </Link>
    </div>
</template>
