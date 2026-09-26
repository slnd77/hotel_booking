<script setup lang="ts">
import { Head } from '@inertiajs/vue3';

defineProps<{
    property: {
        name: string;
    };
}>();
</script>

<template>
    <Head :title="`${property.name} — Booking Engine`" />

    <div
        class="flex min-h-screen items-center justify-center bg-[#f5f5f4] px-6"
    >
        <div
            class="max-w-md rounded-lg border border-[#e3e3e0] bg-white p-8 text-center"
        >
            <h1 class="font-display text-xl font-semibold text-[#1b1b18]">
                Booking Engine Unavailable
            </h1>
            <p class="mt-3 text-sm text-[#706f6c]">
                Online booking for {{ property.name }} is currently disabled.
                Please contact the property directly to make a reservation.
            </p>
        </div>
    </div>
</template>
