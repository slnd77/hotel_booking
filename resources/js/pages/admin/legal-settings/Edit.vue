<script setup lang="ts">
import AdminLayout from '@/layouts/AdminLayout.vue';
import admin from '@/routes/admin';
import { usePropertyTime } from '@/lib/usePropertyTime';
import { Head, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';

defineOptions({ layout: AdminLayout });

type Settings = {
    privacy_policy: string;
    terms: string;
    marketing_opt_in: boolean;
    grievance_officer_name: string | null;
    grievance_officer_email: string | null;
    grievance_officer_phone: string | null;
    version: string | null;
};

type GstCheck = {
    key: string;
    label: string;
    status: 'pass' | 'warn' | 'fail';
    detail: string;
};

const props = defineProps<{
    property: { id: number; name: string };
    settings: Settings;
    publicUrls: { privacy: string; terms: string };
    consentCounts: Record<string, { granted: number; refused: number }>;
    gstChecks: GstCheck[];
    activityLogs: { action: string; user: string | null; at: string | null }[];
}>();

const propertyTime = usePropertyTime();
const activityLogsOpen = ref(false);

const form = useForm({
    privacy_policy: props.settings.privacy_policy,
    terms: props.settings.terms,
    marketing_opt_in: props.settings.marketing_opt_in,
    grievance_officer_name: props.settings.grievance_officer_name ?? '',
    grievance_officer_email: props.settings.grievance_officer_email ?? '',
    grievance_officer_phone: props.settings.grievance_officer_phone ?? '',
});

function submit(): void {
    form.put(admin.legalSettings.update(props.property.id).url, {
        preserveScroll: true,
    });
}

const purposes: { key: string; label: string }[] = [
    { key: 'terms', label: 'Terms of booking' },
    { key: 'privacy', label: 'Privacy policy' },
    { key: 'marketing', label: 'Marketing' },
];

const statusClass: Record<GstCheck['status'], string> = {
    pass: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300',
    warn: 'bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-300',
    fail: 'bg-red-100 text-red-700 dark:bg-red-950 dark:text-red-300',
};

const labelClass = 'text-sm font-semibold text-[#1b1b18] dark:text-[#EDEDEC]';
const inputClass =
    'w-full rounded-md border border-[#e3e3e0] bg-white px-3 py-1.5 text-sm dark:border-[#3E3E3A] dark:bg-[#161615] dark:text-[#EDEDEC]';
const sectionClass =
    'flex flex-col gap-3 rounded-lg border border-[#e3e3e0] p-4 dark:border-[#3E3E3A]';
</script>

<template>
    <Head :title="`Privacy & Terms: ${property.name}`" />

    <div class="mb-6 flex flex-wrap items-center justify-between gap-3">
        <h1
            class="font-display text-2xl font-semibold text-[#1b1b18] dark:text-[#EDEDEC]"
        >
            Privacy &amp; Terms
        </h1>
        <button
            type="button"
            class="rounded-md border border-[#e3e3e0] px-4 py-2 text-sm font-medium text-[#1b1b18] dark:border-[#3E3E3A] dark:text-[#EDEDEC]"
            @click="activityLogsOpen = !activityLogsOpen"
        >
            Activity Logs
        </button>
    </div>

    <div
        v-if="activityLogsOpen"
        class="mb-6 flex flex-col gap-2 rounded-lg border border-[#e3e3e0] p-4 dark:border-[#3E3E3A]"
    >
        <p
            v-if="activityLogs.length === 0"
            class="text-sm text-[#706f6c] dark:text-[#A1A09A]"
        >
            No changes yet.
        </p>
        <ul
            v-else
            class="flex max-h-72 flex-col gap-1.5 overflow-y-auto text-sm"
        >
            <li
                v-for="(log, index) in activityLogs"
                :key="index"
                class="text-[#1b1b18] dark:text-[#EDEDEC]"
            >
                {{ log.action }}
                <span class="text-xs text-[#706f6c] dark:text-[#A1A09A]"
                    >— {{ log.user ?? 'System' }},
                    {{ propertyTime.dateTime(log.at) }}</span
                >
            </li>
        </ul>
    </div>

    <div class="grid max-w-5xl gap-6 lg:grid-cols-[minmax(0,1fr)_20rem]">
        <form class="flex min-w-0 flex-col gap-6" @submit.prevent="submit">
            <section :class="sectionClass">
                <div
                    class="flex flex-wrap items-baseline justify-between gap-2"
                >
                    <label for="privacy_policy" :class="labelClass"
                        >Privacy policy</label
                    >
                    <a
                        :href="publicUrls.privacy"
                        target="_blank"
                        rel="noopener"
                        class="text-xs text-[#f53003] underline"
                        >View public page</a
                    >
                </div>
                <p class="text-xs text-[#706f6c] dark:text-[#A1A09A]">
                    Shown to guests at checkout and web check-in, who must
                    accept it. Plain text; <code>{property}</code> is replaced
                    by the property's name. Say what you collect, why, who you
                    share it with, how long you keep it, and how guests can ask
                    for a copy, a correction or erasure.
                </p>
                <textarea
                    id="privacy_policy"
                    v-model="form.privacy_policy"
                    rows="12"
                    :class="inputClass"
                ></textarea>
                <p
                    v-if="form.errors.privacy_policy"
                    class="text-xs text-red-600"
                >
                    {{ form.errors.privacy_policy }}
                </p>
            </section>

            <section :class="sectionClass">
                <div
                    class="flex flex-wrap items-baseline justify-between gap-2"
                >
                    <label for="terms" :class="labelClass"
                        >Terms of booking</label
                    >
                    <a
                        :href="publicUrls.terms"
                        target="_blank"
                        rel="noopener"
                        class="text-xs text-[#f53003] underline"
                        >View public page</a
                    >
                </div>
                <textarea
                    id="terms"
                    v-model="form.terms"
                    rows="10"
                    :class="inputClass"
                ></textarea>
                <p v-if="form.errors.terms" class="text-xs text-red-600">
                    {{ form.errors.terms }}
                </p>
                <p class="text-xs text-[#706f6c] dark:text-[#A1A09A]">
                    Changing either text starts a new version: consents given
                    from then on record it.
                    <template v-if="settings.version"
                        >Current version: {{ settings.version }}.</template
                    >
                </p>
            </section>

            <section :class="sectionClass">
                <h2 :class="labelClass">Grievance officer</h2>
                <p class="text-xs text-[#706f6c] dark:text-[#A1A09A]">
                    Who guests contact about their data (India's DPDP Act).
                    Printed at the end of the privacy policy.
                </p>
                <div class="grid gap-3 sm:grid-cols-3">
                    <label
                        class="flex flex-col gap-1 text-xs text-[#706f6c] dark:text-[#A1A09A]"
                    >
                        Name
                        <input
                            v-model="form.grievance_officer_name"
                            type="text"
                            :class="inputClass"
                        />
                    </label>
                    <label
                        class="flex flex-col gap-1 text-xs text-[#706f6c] dark:text-[#A1A09A]"
                    >
                        E-mail
                        <input
                            v-model="form.grievance_officer_email"
                            type="email"
                            required
                            :class="inputClass"
                        />
                        <span
                            v-if="form.errors.grievance_officer_email"
                            class="text-red-600"
                            >{{ form.errors.grievance_officer_email }}</span
                        >
                    </label>
                    <label
                        class="flex flex-col gap-1 text-xs text-[#706f6c] dark:text-[#A1A09A]"
                    >
                        Phone
                        <input
                            v-model="form.grievance_officer_phone"
                            type="text"
                            :class="inputClass"
                        />
                    </label>
                </div>
            </section>

            <section :class="sectionClass">
                <label class="flex items-start gap-2">
                    <input
                        v-model="form.marketing_opt_in"
                        type="checkbox"
                        class="mt-0.5 h-4 w-4"
                    />
                    <span>
                        <span :class="labelClass"
                            >Ask guests for marketing consent</span
                        >
                        <span
                            class="block text-xs text-[#706f6c] dark:text-[#A1A09A]"
                            >An optional, unticked box at checkout and web
                            check-in. Only guests who tick it are marked for
                            newsletters and offers.</span
                        >
                    </span>
                </label>
            </section>

            <div>
                <button
                    type="submit"
                    :disabled="form.processing"
                    class="rounded-md bg-[#1b1b18] px-5 py-2 text-sm font-medium text-white disabled:opacity-60 dark:bg-[#EDEDEC] dark:text-[#1b1b18]"
                >
                    {{ form.processing ? 'Saving…' : 'Save' }}
                </button>
            </div>
        </form>

        <aside class="flex flex-col gap-6">
            <section :class="sectionClass">
                <h2 :class="labelClass">GST invoice check</h2>
                <ul class="flex flex-col gap-2.5">
                    <li
                        v-for="check in gstChecks"
                        :key="check.key"
                        class="text-sm"
                    >
                        <div class="flex items-center gap-2">
                            <span
                                class="rounded px-1.5 py-0.5 text-[10px] font-bold uppercase"
                                :class="statusClass[check.status]"
                                >{{
                                    check.status === 'pass'
                                        ? 'OK'
                                        : check.status
                                }}</span
                            >
                            <span
                                class="font-medium text-[#1b1b18] dark:text-[#EDEDEC]"
                                >{{ check.label }}</span
                            >
                        </div>
                        <p
                            class="mt-0.5 text-xs text-[#706f6c] dark:text-[#A1A09A]"
                        >
                            {{ check.detail }}
                        </p>
                    </li>
                </ul>
            </section>

            <section :class="sectionClass">
                <h2 :class="labelClass">Consents recorded</h2>
                <table class="text-sm">
                    <thead>
                        <tr
                            class="text-left text-xs text-[#706f6c] dark:text-[#A1A09A]"
                        >
                            <th class="pb-1 font-medium">Purpose</th>
                            <th class="pb-1 text-right font-medium">Given</th>
                            <th class="pb-1 text-right font-medium">Refused</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="purpose in purposes"
                            :key="purpose.key"
                            class="text-[#1b1b18] dark:text-[#EDEDEC]"
                        >
                            <td class="py-0.5">{{ purpose.label }}</td>
                            <td class="py-0.5 text-right">
                                {{ consentCounts[purpose.key]?.granted ?? 0 }}
                            </td>
                            <td class="py-0.5 text-right">
                                {{ consentCounts[purpose.key]?.refused ?? 0 }}
                            </td>
                        </tr>
                    </tbody>
                </table>
                <p class="text-xs text-[#706f6c] dark:text-[#A1A09A]">
                    Each guest's own consents, a copy of their data and erasure
                    are under Databases → Guests.
                </p>
            </section>
        </aside>
    </div>
</template>
