<script setup lang="ts">
import { Head } from '@inertiajs/vue3';

defineProps<{
    property: { name: string };
    title: string;
    body: string;
    grievanceOfficer: {
        name: string | null;
        email: string | null;
        phone: string | null;
    } | null;
    version: string;
    otherDocument: { title: string; url: string };
    bookingUrl: string;
}>();
</script>

<template>
    <Head :title="`${title} — ${property.name}`" />

    <div class="min-h-screen bg-[#f5f5f4] px-4 py-10">
        <article
            class="mx-auto max-w-2xl rounded-lg border border-[#e3e3e0] bg-white p-6 sm:p-8"
        >
            <p
                class="text-xs font-semibold tracking-wide text-[#706f6c] uppercase"
            >
                {{ property.name }}
            </p>
            <h1 class="font-display mt-1 text-2xl font-semibold text-[#1b1b18]">
                {{ title }}
            </h1>

            <div
                class="mt-6 text-sm leading-relaxed whitespace-pre-line text-[#1b1b18]"
            >
                {{ body }}
            </div>

            <section
                v-if="grievanceOfficer"
                class="mt-8 rounded-md border border-[#e3e3e0] bg-[#fafaf9] p-4 text-sm"
            >
                <h2 class="font-semibold text-[#1b1b18]">Grievance officer</h2>
                <p class="mt-1 text-[#706f6c]">
                    For a copy of your data, a correction, erasure or to
                    withdraw a consent:
                </p>
                <ul class="mt-2 text-[#1b1b18]">
                    <li v-if="grievanceOfficer.name">
                        {{ grievanceOfficer.name }}
                    </li>
                    <li v-if="grievanceOfficer.email">
                        <a
                            :href="`mailto:${grievanceOfficer.email}`"
                            class="underline"
                            >{{ grievanceOfficer.email }}</a
                        >
                    </li>
                    <li v-if="grievanceOfficer.phone">
                        {{ grievanceOfficer.phone }}
                    </li>
                </ul>
            </section>

            <footer
                class="mt-8 flex flex-wrap items-center justify-between gap-3 border-t border-[#e3e3e0] pt-4 text-xs text-[#706f6c]"
            >
                <span>Version {{ version }}</span>
                <span class="flex gap-4">
                    <a :href="otherDocument.url" class="underline">{{
                        otherDocument.title
                    }}</a>
                    <a :href="bookingUrl" class="underline">Book a stay</a>
                </span>
            </footer>
        </article>
    </div>
</template>
