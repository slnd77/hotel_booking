<script setup lang="ts">
import ActivityLogsModal, {
    type ActivityLogRow,
} from '@/components/ActivityLogsModal.vue';
import admin from '@/routes/admin';
import { useForm } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, ref } from 'vue';

export type PropertyBrandingSettings = {
    website_url: string;
    accommodation_nomenclature: string;
    phone_numbers: string[];
    show_property_name: boolean;
    theme_color: string;
    header_color: string;
    text_color: string;
    logo: string | null;
    logo_url: string | null;
    background_images: { path: string; selected: boolean; url: string }[];
    banner_image: string | null;
    banner_image_url: string | null;
    support_emails: string[];
};

const props = defineProps<{
    propertyId: number;
    timezone?: string | null;
    branding: PropertyBrandingSettings;
    logs: ActivityLogRow[];
}>();

const MAX_CONTACTS = 5;
const inputClass =
    'block w-full rounded-md border border-[#e3e3e0] bg-white px-3 py-2 text-sm text-[#1b1b18] focus:border-[#f53003] focus:ring-1 focus:ring-[#f53003] focus:outline-none dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]';
const colorFields = [
    { key: 'theme_color', label: 'Theme' },
    { key: 'header_color', label: 'Header' },
    { key: 'text_color', label: 'Text' },
] as const;

const form = useForm({
    website_url: props.branding.website_url,
    accommodation_nomenclature: props.branding.accommodation_nomenclature,
    phone_numbers: props.branding.phone_numbers.length
        ? [...props.branding.phone_numbers]
        : [''],
    show_property_name: props.branding.show_property_name,
    theme_color: props.branding.theme_color,
    header_color: props.branding.header_color,
    text_color: props.branding.text_color,
    logo: null as File | null,
    remove_logo: false,
    banner_image: null as File | null,
    remove_banner_image: false,
    background_images: props.branding.background_images.map((image) => ({
        path: image.path,
        selected: image.selected,
    })),
    new_background_images: [] as File[],
    support_emails: props.branding.support_emails.length
        ? [...props.branding.support_emails]
        : [''],
});

const logsOpen = ref(false);
const savedImageUrls = new Map(
    props.branding.background_images.map((image) => [image.path, image.url]),
);

/** Object URLs for picked files, revoked when the page goes away. */
const objectUrls: string[] = [];
function previewUrl(file: File): string {
    const url = URL.createObjectURL(file);
    objectUrls.push(url);
    return url;
}
onBeforeUnmount(() => objectUrls.forEach((url) => URL.revokeObjectURL(url)));

const logoPreview = computed(() =>
    form.logo
        ? previewUrl(form.logo)
        : form.remove_logo
          ? null
          : props.branding.logo_url,
);
const bannerPreview = computed(() =>
    form.banner_image
        ? previewUrl(form.banner_image)
        : form.remove_banner_image
          ? null
          : props.branding.banner_image_url,
);
const newBackgroundPreviews = computed(() =>
    form.new_background_images.map((file) => previewUrl(file)),
);

function pickFile(event: Event): File | null {
    const input = event.target as HTMLInputElement;
    const file = input.files?.[0] ?? null;
    input.value = '';
    return file;
}

function pickLogo(event: Event): void {
    const file = pickFile(event);
    if (file) {
        form.logo = file;
        form.remove_logo = false;
    }
}

function removeLogo(): void {
    form.logo = null;
    form.remove_logo = props.branding.logo !== null;
}

function pickBanner(event: Event): void {
    const file = pickFile(event);
    if (file) {
        form.banner_image = file;
        form.remove_banner_image = false;
    }
}

function removeBanner(): void {
    form.banner_image = null;
    form.remove_banner_image = props.branding.banner_image !== null;
}

function pickBackgrounds(event: Event): void {
    const input = event.target as HTMLInputElement;
    form.new_background_images.push(...Array.from(input.files ?? []));
    input.value = '';
}

function errorFor(prefix: string): string | undefined {
    const errors = form.errors as Record<string, string>;
    const key = Object.keys(errors).find(
        (name) => name === prefix || name.startsWith(`${prefix}.`),
    );
    return key ? errors[key] : undefined;
}

function submit(): void {
    form.post(admin.properties.branding.update(props.propertyId).url, {
        preserveScroll: true,
        forceFormData: true,
    });
}
</script>

<template>
    <form class="flex max-w-4xl flex-col gap-4" @submit.prevent="submit">
        <div class="flex items-center justify-between">
            <h2
                class="font-display text-lg font-semibold text-[#1b1b18] dark:text-[#EDEDEC]"
            >
                Branding
            </h2>
            <button
                type="button"
                class="rounded-md border border-[#e3e3e0] px-3 py-1.5 text-sm text-[#1b1b18] hover:bg-[#f5f5f4] dark:border-[#3E3E3A] dark:text-[#EDEDEC] dark:hover:bg-[#1D1D1B]"
                @click="logsOpen = true"
            >
                Activity Logs
            </button>
        </div>

        <div
            class="divide-y divide-[#e3e3e0] rounded-lg border border-[#e3e3e0] dark:divide-[#3E3E3A] dark:border-[#3E3E3A]"
        >
            <div class="grid grid-cols-[14rem_1fr] items-start gap-4 p-4">
                <label
                    class="pt-2 text-sm font-semibold text-[#1b1b18] dark:text-[#EDEDEC]"
                    >Website URL</label
                >
                <div>
                    <input
                        v-model="form.website_url"
                        type="url"
                        placeholder="https://www.example.com/"
                        :class="inputClass"
                    />
                    <p
                        v-if="form.errors.website_url"
                        class="mt-1 text-sm text-red-600"
                    >
                        {{ form.errors.website_url }}
                    </p>
                </div>
            </div>

            <div class="grid grid-cols-[14rem_1fr] items-start gap-4 p-4">
                <label
                    class="pt-2 text-sm font-semibold text-[#1b1b18] dark:text-[#EDEDEC]"
                    >Accommodation Nomenclature</label
                >
                <div>
                    <input
                        v-model="form.accommodation_nomenclature"
                        type="text"
                        required
                        maxlength="50"
                        placeholder="Room"
                        :class="inputClass"
                    />
                    <p class="mt-1 text-xs text-[#706f6c] dark:text-[#A1A09A]">
                        What guests book on the booking engine, e.g. Room,
                        Cottage, Tent.
                    </p>
                    <p
                        v-if="form.errors.accommodation_nomenclature"
                        class="mt-1 text-sm text-red-600"
                    >
                        {{ form.errors.accommodation_nomenclature }}
                    </p>
                </div>
            </div>

            <div class="grid grid-cols-[14rem_1fr] items-start gap-4 p-4">
                <label
                    class="pt-2 text-sm font-semibold text-[#1b1b18] dark:text-[#EDEDEC]"
                    >Phone Number</label
                >
                <div class="flex flex-col gap-2">
                    <div
                        v-for="(_, index) in form.phone_numbers"
                        :key="`phone-${index}`"
                        class="flex items-center gap-2"
                    >
                        <input
                            v-model="form.phone_numbers[index]"
                            type="tel"
                            maxlength="20"
                            :class="inputClass"
                        />
                        <button
                            v-if="
                                index === form.phone_numbers.length - 1 &&
                                form.phone_numbers.length < MAX_CONTACTS
                            "
                            type="button"
                            class="w-6 text-lg font-semibold text-[#1b6ed6]"
                            title="Add phone number"
                            @click="form.phone_numbers.push('')"
                        >
                            +
                        </button>
                        <button
                            v-else
                            type="button"
                            class="w-6 text-[#706f6c] hover:text-red-600"
                            title="Remove phone number"
                            @click="form.phone_numbers.splice(index, 1)"
                        >
                            ✕
                        </button>
                    </div>
                    <p
                        v-if="errorFor('phone_numbers')"
                        class="text-sm text-red-600"
                    >
                        {{ errorFor('phone_numbers') }}
                    </p>
                </div>
            </div>

            <label
                class="flex items-center justify-between gap-4 p-4 text-sm font-semibold text-[#1b1b18] dark:text-[#EDEDEC]"
            >
                Show Property Name on Booking Engine
                <input v-model="form.show_property_name" type="checkbox" />
            </label>

            <div class="flex flex-col gap-3 p-4">
                <div
                    v-for="field in colorFields"
                    :key="field.key"
                    class="grid grid-cols-[14rem_auto_1fr] items-center gap-4"
                >
                    <span
                        class="text-sm font-semibold text-[#1b1b18] dark:text-[#EDEDEC]"
                    >
                        {{ field.label }}
                        <span
                            class="block text-xs font-normal text-[#706f6c] dark:text-[#A1A09A]"
                            >Color</span
                        >
                    </span>
                    <input
                        v-model="form[field.key]"
                        type="color"
                        class="h-9 w-12 cursor-pointer rounded border border-[#e3e3e0] bg-white p-0.5 dark:border-[#3E3E3A]"
                    />
                    <div>
                        <input
                            v-model="form[field.key]"
                            type="text"
                            maxlength="7"
                            class="w-28 rounded-md border border-[#e3e3e0] bg-white px-2 py-1.5 font-mono text-sm text-[#1b1b18] dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]"
                        />
                        <span
                            v-if="form.errors[field.key]"
                            class="ml-2 text-sm text-red-600"
                        >
                            {{ form.errors[field.key] }}
                        </span>
                    </div>
                </div>
            </div>

            <div class="grid grid-cols-[14rem_1fr] items-start gap-4 p-4">
                <span
                    class="text-sm font-semibold text-[#1b1b18] dark:text-[#EDEDEC]"
                >
                    Logo
                    <span
                        class="block text-xs font-normal text-[#706f6c] dark:text-[#A1A09A]"
                        >JPG, PNG or WebP, up to 2 MB</span
                    >
                </span>
                <div
                    class="flex min-h-32 items-center justify-between gap-4 rounded-md border border-[#e3e3e0] p-4 dark:border-[#3E3E3A]"
                >
                    <div class="flex flex-col items-center gap-3">
                        <label
                            class="cursor-pointer rounded-md border border-[#e3e3e0] px-3 py-1.5 text-sm text-[#1b1b18] hover:bg-[#f5f5f4] dark:border-[#3E3E3A] dark:text-[#EDEDEC]"
                        >
                            Select Image
                            <input
                                type="file"
                                accept="image/jpeg,image/png,image/webp"
                                class="hidden"
                                @change="pickLogo"
                            />
                        </label>
                        <img
                            v-if="logoPreview"
                            :src="logoPreview"
                            alt="Logo"
                            class="max-h-24 max-w-56 object-contain"
                        />
                        <span
                            v-else
                            class="text-xs text-[#706f6c] dark:text-[#A1A09A]"
                            >No logo</span
                        >
                    </div>
                    <button
                        v-if="logoPreview"
                        type="button"
                        class="text-sm text-red-600 hover:underline"
                        @click="removeLogo"
                    >
                        Remove
                    </button>
                </div>
                <p
                    v-if="form.errors.logo"
                    class="col-start-2 text-sm text-red-600"
                >
                    {{ form.errors.logo }}
                </p>
            </div>

            <div class="grid grid-cols-[14rem_1fr] items-start gap-4 p-4">
                <span
                    class="text-sm font-semibold text-[#1b1b18] dark:text-[#EDEDEC]"
                >
                    Background Image
                    <span
                        class="block text-xs font-normal text-[#706f6c] dark:text-[#A1A09A]"
                        >Image size should be 1024 × 720 px. Ticked images are
                        shown.</span
                    >
                </span>
                <div class="flex flex-col gap-3">
                    <label
                        class="w-fit cursor-pointer rounded-md border border-[#e3e3e0] px-3 py-1.5 text-sm text-[#1b1b18] hover:bg-[#f5f5f4] dark:border-[#3E3E3A] dark:text-[#EDEDEC]"
                    >
                        Select Image
                        <input
                            type="file"
                            accept="image/jpeg,image/png,image/webp"
                            multiple
                            class="hidden"
                            @change="pickBackgrounds"
                        />
                    </label>
                    <div class="flex flex-wrap gap-3">
                        <div
                            v-for="(image, index) in form.background_images"
                            :key="image.path"
                            class="flex w-36 flex-col gap-1 rounded-md border border-[#e3e3e0] p-1.5 dark:border-[#3E3E3A]"
                        >
                            <img
                                :src="savedImageUrls.get(image.path)"
                                alt="Background"
                                class="h-20 w-full rounded object-cover"
                            />
                            <div class="flex items-center justify-between">
                                <input
                                    v-model="image.selected"
                                    type="checkbox"
                                    title="Show on booking engine"
                                />
                                <button
                                    type="button"
                                    class="text-xs text-red-600 hover:underline"
                                    @click="
                                        form.background_images.splice(index, 1)
                                    "
                                >
                                    Remove
                                </button>
                            </div>
                        </div>
                        <div
                            v-for="(url, index) in newBackgroundPreviews"
                            :key="url"
                            class="flex w-36 flex-col gap-1 rounded-md border border-dashed border-[#e3e3e0] p-1.5 dark:border-[#3E3E3A]"
                        >
                            <img
                                :src="url"
                                alt="New background"
                                class="h-20 w-full rounded object-cover"
                            />
                            <div class="flex items-center justify-between">
                                <span
                                    class="text-xs text-[#706f6c] dark:text-[#A1A09A]"
                                    >New</span
                                >
                                <button
                                    type="button"
                                    class="text-xs text-red-600 hover:underline"
                                    @click="
                                        form.new_background_images.splice(
                                            index,
                                            1,
                                        )
                                    "
                                >
                                    Remove
                                </button>
                            </div>
                        </div>
                    </div>
                    <p
                        v-if="
                            errorFor('new_background_images') ||
                            errorFor('background_images')
                        "
                        class="text-sm text-red-600"
                    >
                        {{
                            errorFor('new_background_images') ??
                            errorFor('background_images')
                        }}
                    </p>
                </div>
            </div>

            <div class="grid grid-cols-[14rem_1fr] items-start gap-4 p-4">
                <span
                    class="text-sm font-semibold text-[#1b1b18] dark:text-[#EDEDEC]"
                >
                    Banner Image (Booking Engine)
                    <span
                        class="block text-xs font-normal text-[#706f6c] dark:text-[#A1A09A]"
                        >For a better view, use a 24:5 ratio (e.g. 1920×400,
                        1200×250, 2400×500).</span
                    >
                </span>
                <div class="flex flex-col gap-3">
                    <div class="flex items-center gap-3">
                        <label
                            class="cursor-pointer rounded-md border border-[#e3e3e0] px-3 py-1.5 text-sm text-[#1b1b18] hover:bg-[#f5f5f4] dark:border-[#3E3E3A] dark:text-[#EDEDEC]"
                        >
                            Select Image
                            <input
                                type="file"
                                accept="image/jpeg,image/png,image/webp"
                                class="hidden"
                                @change="pickBanner"
                            />
                        </label>
                        <button
                            v-if="bannerPreview"
                            type="button"
                            class="text-sm text-red-600 hover:underline"
                            @click="removeBanner"
                        >
                            Remove
                        </button>
                    </div>
                    <img
                        v-if="bannerPreview"
                        :src="bannerPreview"
                        alt="Banner"
                        class="aspect-[24/5] w-full max-w-xl rounded object-cover"
                    />
                    <p
                        v-if="form.errors.banner_image"
                        class="text-sm text-red-600"
                    >
                        {{ form.errors.banner_image }}
                    </p>
                </div>
            </div>

            <div class="grid grid-cols-[14rem_1fr] items-start gap-4 p-4">
                <label
                    class="pt-2 text-sm font-semibold text-[#1b1b18] dark:text-[#EDEDEC]"
                    >Support Email ID</label
                >
                <div class="flex flex-col gap-2">
                    <div
                        v-for="(_, index) in form.support_emails"
                        :key="`email-${index}`"
                        class="flex items-center gap-2"
                    >
                        <input
                            v-model="form.support_emails[index]"
                            type="email"
                            :class="inputClass"
                        />
                        <button
                            v-if="
                                index === form.support_emails.length - 1 &&
                                form.support_emails.length < MAX_CONTACTS
                            "
                            type="button"
                            class="w-6 text-lg font-semibold text-[#1b6ed6]"
                            title="Add email"
                            @click="form.support_emails.push('')"
                        >
                            +
                        </button>
                        <button
                            v-else
                            type="button"
                            class="w-6 text-[#706f6c] hover:text-red-600"
                            title="Remove email"
                            @click="form.support_emails.splice(index, 1)"
                        >
                            ✕
                        </button>
                    </div>
                    <p
                        v-if="errorFor('support_emails')"
                        class="text-sm text-red-600"
                    >
                        {{ errorFor('support_emails') }}
                    </p>
                </div>
            </div>
        </div>

        <div>
            <button
                type="submit"
                :disabled="form.processing"
                class="rounded-md border border-black bg-[#1b1b18] px-5 py-2 text-sm font-medium text-white hover:bg-black disabled:opacity-50 dark:border-[#eeeeec] dark:bg-[#eeeeec] dark:text-[#1C1C1A] dark:hover:bg-white"
            >
                Save
            </button>
        </div>

        <ActivityLogsModal
            :show="logsOpen"
            title="Branding activity logs"
            :logs="logs"
            :timezone="timezone"
            @close="logsOpen = false"
        />
    </form>
</template>
