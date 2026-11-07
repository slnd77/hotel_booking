<script setup lang="ts">
import AdminLayout from '@/layouts/AdminLayout.vue';
import { useRedirectToProperty } from '@/lib/useRedirectToProperty';
import admin from '@/routes/admin';
import { Head } from '@inertiajs/vue3';

defineOptions({ layout: AdminLayout });

useRedirectToProperty(admin.nightAudits.edit);
</script>

<template>
    <Head title="Night audit" />

    <div class="mb-6 flex items-center justify-between">
        <h1
            class="font-display text-2xl font-semibold text-[#1b1b18] dark:text-[#EDEDEC]"
        >
            Night Audit
        </h1>
    </div>

    <p class="text-sm text-[#706f6c] dark:text-[#A1A09A]">
        Select a hotel from the dropdown at the top of the page to see its night
        audits.
    </p>
</template>
