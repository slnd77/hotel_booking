<script setup lang="ts">
import PasswordController from '@/actions/Laravel/Fortify/Http/Controllers/PasswordController';
import ProfileInformationController from '@/actions/Laravel/Fortify/Http/Controllers/ProfileInformationController';
import PhoneCountryCodeSelect, {
    type PhoneCountryOption,
} from '@/components/PhoneCountryCodeSelect.vue';
import ProfileSettingsTabs from '@/components/ProfileSettingsTabs.vue';
import AdminLayout from '@/layouts/AdminLayout.vue';
import { Head, useForm } from '@inertiajs/vue3';

defineOptions({ layout: AdminLayout });

const props = defineProps<{
    user: {
        name: string;
        email: string;
        mobile_country_code: string | null;
        mobile: string | null;
    };
    countries: PhoneCountryOption[];
    status: string | null;
}>();

const inputClass =
    'block w-full rounded-md border border-[#e3e3e0] bg-white px-3 py-2 text-sm text-[#1b1b18] focus:border-[#f53003] focus:ring-1 focus:ring-[#f53003] focus:outline-none disabled:bg-[#f5f5f4] disabled:text-[#706f6c] dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC] dark:disabled:bg-[#1D1D1B]';

const profileForm = useForm({
    name: props.user.name,
    mobile_country_code: props.user.mobile_country_code ?? '+91',
    mobile: props.user.mobile ?? '',
});
const passwordForm = useForm({
    current_password: '',
    password: '',
    password_confirmation: '',
});

function saveProfile(): void {
    profileForm.put(ProfileInformationController.update().url, {
        errorBag: 'updateProfileInformation',
        preserveScroll: true,
    });
}

function savePassword(): void {
    passwordForm.put(PasswordController.update().url, {
        errorBag: 'updatePassword',
        preserveScroll: true,
        onFinish: () => passwordForm.reset(),
    });
}
</script>

<template>
    <Head title="Profile Settings" />

    <h1
        class="font-display mb-6 text-2xl font-semibold text-[#1b1b18] dark:text-[#EDEDEC]"
    >
        Profile Settings
    </h1>

    <ProfileSettingsTabs active="profile" />

    <div class="flex max-w-xl flex-col gap-6">
        <section
            class="rounded-lg border border-[#e3e3e0] p-5 dark:border-[#3E3E3A]"
        >
            <h2
                class="text-base font-semibold text-[#1b1b18] dark:text-[#EDEDEC]"
            >
                Profile information
            </h2>
            <p class="mb-4 text-sm text-[#706f6c] dark:text-[#A1A09A]">
                Your name is shown on bookings, activity logs and settings
                changes. SMS and WhatsApp sign-in codes go to your mobile
                number.
            </p>

            <form class="flex flex-col gap-4" @submit.prevent="saveProfile">
                <label
                    class="flex flex-col gap-1 text-sm font-medium text-[#1b1b18] dark:text-[#EDEDEC]"
                >
                    Name
                    <input
                        v-model="profileForm.name"
                        type="text"
                        required
                        maxlength="255"
                        autocomplete="name"
                        :class="inputClass"
                    />
                    <span
                        v-if="profileForm.errors.name"
                        class="text-sm font-normal text-red-600"
                        >{{ profileForm.errors.name }}</span
                    >
                </label>
                <div
                    class="flex flex-col gap-1 text-sm font-medium text-[#1b1b18] dark:text-[#EDEDEC]"
                >
                    <label for="profile_mobile">Mobile number</label>
                    <div class="flex gap-2">
                        <PhoneCountryCodeSelect
                            v-model="profileForm.mobile_country_code"
                            :countries="countries"
                        />
                        <input
                            id="profile_mobile"
                            v-model="profileForm.mobile"
                            type="tel"
                            inputmode="numeric"
                            autocomplete="tel-national"
                            placeholder="81234 56789"
                            :class="inputClass"
                        />
                    </div>
                    <span
                        v-if="
                            profileForm.errors.mobile ||
                            profileForm.errors.mobile_country_code
                        "
                        class="text-sm font-normal text-red-600"
                        >{{
                            profileForm.errors.mobile ||
                            profileForm.errors.mobile_country_code
                        }}</span
                    >
                    <span
                        class="text-xs font-normal text-[#706f6c] dark:text-[#A1A09A]"
                        >Changing it turns SMS and WhatsApp codes off until you
                        turn them on again.</span
                    >
                </div>
                <label
                    class="flex flex-col gap-1 text-sm font-medium text-[#1b1b18] dark:text-[#EDEDEC]"
                >
                    E-mail
                    <input
                        :value="user.email"
                        type="email"
                        disabled
                        :class="inputClass"
                    />
                    <span
                        class="text-xs font-normal text-[#706f6c] dark:text-[#A1A09A]"
                        >Your login e-mail can only be changed by an
                        administrator.</span
                    >
                </label>
                <div class="flex items-center gap-3">
                    <button
                        type="submit"
                        :disabled="profileForm.processing"
                        class="rounded-md border border-black bg-[#1b1b18] px-4 py-2 text-sm font-medium text-white hover:bg-black disabled:opacity-50 dark:border-[#eeeeec] dark:bg-[#eeeeec] dark:text-[#1C1C1A]"
                    >
                        Save
                    </button>
                    <span
                        v-if="status === 'profile-information-updated'"
                        class="text-sm text-green-600"
                        >Profile updated.</span
                    >
                </div>
            </form>
        </section>

        <section
            class="rounded-lg border border-[#e3e3e0] p-5 dark:border-[#3E3E3A]"
        >
            <h2
                class="text-base font-semibold text-[#1b1b18] dark:text-[#EDEDEC]"
            >
                Change password
            </h2>
            <p class="mb-4 text-sm text-[#706f6c] dark:text-[#A1A09A]">
                Use a long, unique password.
            </p>

            <form class="flex flex-col gap-4" @submit.prevent="savePassword">
                <label
                    class="flex flex-col gap-1 text-sm font-medium text-[#1b1b18] dark:text-[#EDEDEC]"
                >
                    Current password
                    <input
                        v-model="passwordForm.current_password"
                        type="password"
                        required
                        autocomplete="current-password"
                        :class="inputClass"
                    />
                    <span
                        v-if="passwordForm.errors.current_password"
                        class="text-sm font-normal text-red-600"
                        >{{ passwordForm.errors.current_password }}</span
                    >
                </label>
                <label
                    class="flex flex-col gap-1 text-sm font-medium text-[#1b1b18] dark:text-[#EDEDEC]"
                >
                    New password
                    <input
                        v-model="passwordForm.password"
                        type="password"
                        required
                        autocomplete="new-password"
                        :class="inputClass"
                    />
                    <span
                        v-if="passwordForm.errors.password"
                        class="text-sm font-normal text-red-600"
                        >{{ passwordForm.errors.password }}</span
                    >
                </label>
                <label
                    class="flex flex-col gap-1 text-sm font-medium text-[#1b1b18] dark:text-[#EDEDEC]"
                >
                    Confirm new password
                    <input
                        v-model="passwordForm.password_confirmation"
                        type="password"
                        required
                        autocomplete="new-password"
                        :class="inputClass"
                    />
                </label>
                <div class="flex items-center gap-3">
                    <button
                        type="submit"
                        :disabled="passwordForm.processing"
                        class="rounded-md border border-black bg-[#1b1b18] px-4 py-2 text-sm font-medium text-white hover:bg-black disabled:opacity-50 dark:border-[#eeeeec] dark:bg-[#eeeeec] dark:text-[#1C1C1A]"
                    >
                        Update password
                    </button>
                    <span
                        v-if="status === 'password-updated'"
                        class="text-sm text-green-600"
                        >Password updated.</span
                    >
                </div>
            </form>
        </section>
    </div>
</template>
