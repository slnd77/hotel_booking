<script setup lang="ts">
import AdminLayout from '@/layouts/AdminLayout.vue';
import admin from '@/routes/admin';
import { Form, Head, Link } from '@inertiajs/vue3';

defineOptions({ layout: AdminLayout });
</script>

<template>
    <Head title="Create property group" />

    <h1
        class="font-display mb-6 text-2xl font-semibold text-[#1b1b18] dark:text-[#EDEDEC]"
    >
        Create property group
    </h1>

    <Form
        :action="admin.propertyGroups.store()"
        #default="{ errors, processing }"
        class="flex max-w-lg flex-col gap-4"
    >
        <div>
            <label
                for="name"
                class="mb-1 block text-sm font-medium text-[#1b1b18] dark:text-[#EDEDEC]"
                >Name</label
            >
            <input
                id="name"
                type="text"
                name="name"
                required
                autofocus
                class="block w-full rounded-md border border-[#e3e3e0] bg-white px-3 py-2 text-sm text-[#1b1b18] focus:border-[#f53003] focus:ring-1 focus:ring-[#f53003] focus:outline-none dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]"
            />
            <p v-if="errors.name" class="mt-1 text-sm text-red-600">
                {{ errors.name }}
            </p>
        </div>

        <div class="mt-2 flex items-center gap-3">
            <button
                type="submit"
                :disabled="processing"
                class="inline-flex justify-center rounded-md border border-black bg-[#1b1b18] px-4 py-2 text-sm font-medium text-white hover:bg-black disabled:opacity-50 dark:border-[#eeeeec] dark:bg-[#eeeeec] dark:text-[#1C1C1A] dark:hover:bg-white"
            >
                {{ processing ? 'Creating...' : 'Create property group' }}
            </button>
            <Link
                :href="admin.propertyGroups.index()"
                class="text-sm font-medium text-[#706f6c] dark:text-[#A1A09A]"
            >
                Cancel
            </Link>
        </div>
    </Form>
</template>
