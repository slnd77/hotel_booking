<script setup lang="ts">
import ConfirmablePasswordController from '@/actions/Laravel/Fortify/Http/Controllers/ConfirmablePasswordController';
import GuestLayout from '@/layouts/GuestLayout.vue';
import { Head, useForm } from '@inertiajs/vue3';

defineOptions({ layout: GuestLayout });

/** Asked before sensitive settings such as two-factor authentication; valid for a few hours afterwards. */
const form = useForm({ password: '' });

function submit(): void {
    form.post(ConfirmablePasswordController.store().url, {
        onFinish: () => form.reset(),
    });
}
</script>

<template>
    <Head title="Confirm password" />

    <h1
        class="font-display mb-2 text-2xl font-semibold text-[#1b1b18] dark:text-[#EDEDEC]"
    >
        Confirm your password
    </h1>
    <p class="mb-6 text-sm text-[#706f6c] dark:text-[#A1A09A]">
        This is a secure area. Please confirm your password to continue.
    </p>

    <form class="flex flex-col gap-4" @submit.prevent="submit">
        <div>
            <label
                for="password"
                class="mb-1 block text-sm font-medium text-[#1b1b18] dark:text-[#EDEDEC]"
                >Password</label
            >
            <input
                id="password"
                v-model="form.password"
                type="password"
                required
                autofocus
                autocomplete="current-password"
                class="block w-full rounded-md border border-[#e3e3e0] bg-white px-3 py-2 text-sm text-[#1b1b18] focus:border-[#f53003] focus:ring-1 focus:ring-[#f53003] focus:outline-none dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]"
            />
            <p v-if="form.errors.password" class="mt-1 text-sm text-red-600">
                {{ form.errors.password }}
            </p>
        </div>

        <button
            type="submit"
            :disabled="form.processing"
            class="mt-2 inline-flex justify-center rounded-md border border-black bg-[#1b1b18] px-4 py-2 text-sm font-medium text-white hover:bg-black disabled:opacity-50 dark:border-[#eeeeec] dark:bg-[#eeeeec] dark:text-[#1C1C1A] dark:hover:bg-white"
        >
            {{ form.processing ? 'Confirming...' : 'Confirm' }}
        </button>
    </form>
</template>
