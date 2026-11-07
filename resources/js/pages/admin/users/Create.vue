<script setup lang="ts">
import PhoneCountryCodeSelect, {
    type PhoneCountryOption,
} from '@/components/PhoneCountryCodeSelect.vue';
import AdminLayout from '@/layouts/AdminLayout.vue';
import admin from '@/routes/admin';
import { Form, Head, Link } from '@inertiajs/vue3';
import { ref } from 'vue';

defineOptions({ layout: AdminLayout });

defineProps<{
    roleOptions: { value: string; label: string }[];
    statusOptions: { value: string; label: string }[];
    countries: PhoneCountryOption[];
}>();

const mobileCountryCode = ref('+91');
const initialMobile = '';
</script>

<template>
    <Head title="Create user" />

    <h1
        class="font-display mb-6 text-2xl font-semibold text-[#1b1b18] dark:text-[#EDEDEC]"
    >
        Create user
    </h1>

    <Form
        :action="admin.users.store()"
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

        <div>
            <label
                for="email"
                class="mb-1 block text-sm font-medium text-[#1b1b18] dark:text-[#EDEDEC]"
                >Email</label
            >
            <input
                id="email"
                type="email"
                name="email"
                required
                class="block w-full rounded-md border border-[#e3e3e0] bg-white px-3 py-2 text-sm text-[#1b1b18] focus:border-[#f53003] focus:ring-1 focus:ring-[#f53003] focus:outline-none dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]"
            />
            <p v-if="errors.email" class="mt-1 text-sm text-red-600">
                {{ errors.email }}
            </p>
        </div>

        <div>
            <label
                for="mobile"
                class="mb-1 block text-sm font-medium text-[#1b1b18] dark:text-[#EDEDEC]"
                >Mobile number</label
            >
            <div class="flex gap-2">
                <PhoneCountryCodeSelect
                    v-model="mobileCountryCode"
                    :countries="countries"
                />
                <input
                    type="hidden"
                    name="mobile_country_code"
                    :value="mobileCountryCode"
                />
                <input
                    id="mobile"
                    type="tel"
                    name="mobile"
                    inputmode="numeric"
                    placeholder="81234 56789"
                    :value="initialMobile"
                    class="block w-full rounded-md border border-[#e3e3e0] bg-white px-3 py-2 text-sm text-[#1b1b18] focus:border-[#f53003] focus:ring-1 focus:ring-[#f53003] focus:outline-none dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]"
                />
            </div>
            <p
                v-if="errors.mobile || errors.mobile_country_code"
                class="mt-1 text-sm text-red-600"
            >
                {{ errors.mobile || errors.mobile_country_code }}
            </p>
        </div>

        <div>
            <label
                for="password"
                class="mb-1 block text-sm font-medium text-[#1b1b18] dark:text-[#EDEDEC]"
                >Password</label
            >
            <input
                id="password"
                type="password"
                name="password"
                required
                autocomplete="new-password"
                class="block w-full rounded-md border border-[#e3e3e0] bg-white px-3 py-2 text-sm text-[#1b1b18] focus:border-[#f53003] focus:ring-1 focus:ring-[#f53003] focus:outline-none dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]"
            />
            <p v-if="errors.password" class="mt-1 text-sm text-red-600">
                {{ errors.password }}
            </p>
        </div>

        <div v-if="roleOptions.length">
            <label
                for="role"
                class="mb-1 block text-sm font-medium text-[#1b1b18] dark:text-[#EDEDEC]"
                >Role</label
            >
            <select
                id="role"
                name="role"
                required
                class="block w-full rounded-md border border-[#e3e3e0] bg-white px-3 py-2 text-sm text-[#1b1b18] focus:border-[#f53003] focus:ring-1 focus:ring-[#f53003] focus:outline-none dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]"
            >
                <option
                    v-for="option in roleOptions"
                    :key="option.value"
                    :value="option.value"
                    :selected="option.value === 'user'"
                >
                    {{ option.label }}
                </option>
            </select>
            <p v-if="errors.role" class="mt-1 text-sm text-red-600">
                {{ errors.role }}
            </p>
        </div>

        <div>
            <label
                for="status"
                class="mb-1 block text-sm font-medium text-[#1b1b18] dark:text-[#EDEDEC]"
                >Status</label
            >
            <select
                id="status"
                name="status"
                required
                value="active"
                class="block w-full rounded-md border border-[#e3e3e0] bg-white px-3 py-2 text-sm text-[#1b1b18] focus:border-[#f53003] focus:ring-1 focus:ring-[#f53003] focus:outline-none dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]"
            >
                <option
                    v-for="option in statusOptions"
                    :key="option.value"
                    :value="option.value"
                >
                    {{ option.label }}
                </option>
            </select>
            <p v-if="errors.status" class="mt-1 text-sm text-red-600">
                {{ errors.status }}
            </p>
        </div>

        <div class="mt-2 flex items-center gap-3">
            <button
                type="submit"
                :disabled="processing"
                class="inline-flex justify-center rounded-md border border-black bg-[#1b1b18] px-4 py-2 text-sm font-medium text-white hover:bg-black disabled:opacity-50 dark:border-[#eeeeec] dark:bg-[#eeeeec] dark:text-[#1C1C1A] dark:hover:bg-white"
            >
                {{ processing ? 'Creating...' : 'Create user' }}
            </button>
            <Link
                :href="admin.users.index()"
                class="text-sm font-medium text-[#706f6c] dark:text-[#A1A09A]"
            >
                Cancel
            </Link>
        </div>
    </Form>
</template>
