<script setup lang="ts">
import WebCheckinPageController from '@/actions/App/Http/Controllers/WebCheckinPageController';
import { Head, useForm, usePage } from '@inertiajs/vue3';
import { computed, onMounted, ref } from 'vue';

const props = defineProps<{
    token: string;
    property: { name: string };
    booking: {
        booking_ref_id: string;
        check_in: string;
        check_out: string;
        nights: number;
        rooms: string[];
        guests: number;
    };
    settings: {
        require_id_document: boolean;
        require_signature: boolean;
        require_address: boolean;
        ask_arrival_time: boolean;
        instructions: string;
        terms: string;
    };
    idTypes: string[];
    status: 'pending' | 'completed';
    submittedAt: string | null;
    closedReason: string | null;
    hasIdDocument: boolean;
    prefill: Record<string, string>;
    /** Settings → Privacy & Terms: the property's privacy policy page, and whether marketing consent is asked. */
    legal: { privacy_url: string; marketing_opt_in: boolean };
}>();

const page = usePage<{ flash?: { success?: string | null } }>();
const editing = ref(props.status !== 'completed');

const form = useForm({
    first_name: props.prefill.first_name ?? '',
    last_name: props.prefill.last_name ?? '',
    email: props.prefill.email ?? '',
    phone: props.prefill.phone ?? '',
    nationality: props.prefill.nationality ?? '',
    address: props.prefill.address ?? '',
    postal_code: props.prefill.postal_code ?? '',
    id_type: props.prefill.id_type ?? '',
    id_number: props.prefill.id_number ?? '',
    arrival_time: props.prefill.arrival_time ?? '',
    special_requests: props.prefill.special_requests ?? '',
    id_document: null as File | null,
    signature: '',
    accept_terms: false,
    accept_privacy: false,
    marketing: false,
});

/** A closed or expired link reports on a key the form doesn't hold. */
const formError = computed(
    () => (form.errors as Record<string, string | undefined>).form,
);

const needsDocument = computed(
    () => props.settings.require_id_document && !props.hasIdDocument,
);

// Signature pad: pointer strokes on a canvas, sent as a PNG data URL.
const canvas = ref<HTMLCanvasElement | null>(null);
const signed = ref(false);
let drawing = false;

function context(): CanvasRenderingContext2D | null {
    return canvas.value?.getContext('2d') ?? null;
}

function setUpCanvas(): void {
    const element = canvas.value;

    if (!element) {
        return;
    }

    const ratio = window.devicePixelRatio || 1;
    element.width = element.clientWidth * ratio;
    element.height = element.clientHeight * ratio;
    const ctx = context();

    if (ctx) {
        ctx.scale(ratio, ratio);
        ctx.lineWidth = 2;
        ctx.lineCap = 'round';
        ctx.strokeStyle = '#111827';
    }
}

function point(event: PointerEvent): [number, number] {
    const rect = (canvas.value as HTMLCanvasElement).getBoundingClientRect();

    return [event.clientX - rect.left, event.clientY - rect.top];
}

function startStroke(event: PointerEvent): void {
    drawing = true;
    canvas.value?.setPointerCapture(event.pointerId);
    const [x, y] = point(event);
    context()?.beginPath();
    context()?.moveTo(x, y);
}

function stroke(event: PointerEvent): void {
    if (!drawing) {
        return;
    }

    const [x, y] = point(event);
    context()?.lineTo(x, y);
    context()?.stroke();
    signed.value = true;
}

function endStroke(): void {
    drawing = false;
}

function clearSignature(): void {
    const element = canvas.value;

    if (element) {
        context()?.clearRect(0, 0, element.width, element.height);
    }

    signed.value = false;
}

onMounted(setUpCanvas);

function pickDocument(event: Event): void {
    form.id_document = (event.target as HTMLInputElement).files?.[0] ?? null;
}

function submit(): void {
    form.signature =
        signed.value && canvas.value ? canvas.value.toDataURL('image/png') : '';
    form.post(WebCheckinPageController.submit(props.token).url, {
        // Multipart only when an ID file is attached; otherwise a plain form post.
        forceFormData: form.id_document instanceof File,
        preserveScroll: true,
        onSuccess: () => {
            editing.value = false;
            clearSignature();
        },
    });
}

function startEditing(): void {
    editing.value = true;
    setTimeout(setUpCanvas, 0);
}

const inputClass =
    'mt-1 w-full rounded-md border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 focus:border-emerald-600 focus:ring-1 focus:ring-emerald-600 focus:outline-none';
const labelClass = 'block text-sm font-medium text-gray-700';
</script>

<template>
    <Head :title="`Online check-in · ${property.name}`" />

    <div class="min-h-screen bg-gray-50 px-4 py-8 text-gray-900">
        <main class="mx-auto flex max-w-xl flex-col gap-5">
            <header>
                <p class="text-sm text-gray-500">{{ property.name }}</p>
                <h1 class="text-2xl font-semibold">Online check-in</h1>
            </header>

            <section
                class="rounded-lg border border-gray-200 bg-white p-4 text-sm"
            >
                <div class="flex justify-between gap-3">
                    <span class="text-gray-500">Booking</span>
                    <span class="font-medium">{{
                        booking.booking_ref_id
                    }}</span>
                </div>
                <div class="mt-1 flex justify-between gap-3">
                    <span class="text-gray-500">Stay</span>
                    <span
                        >{{ booking.check_in }} → {{ booking.check_out }} ({{
                            booking.nights
                        }}
                        {{ booking.nights === 1 ? 'night' : 'nights' }})</span
                    >
                </div>
                <div class="mt-1 flex justify-between gap-3">
                    <span class="text-gray-500">Rooms</span>
                    <span class="text-right">{{
                        booking.rooms.join(', ')
                    }}</span>
                </div>
            </section>

            <div
                v-if="page.props.flash?.success"
                class="rounded-lg border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-800"
                role="status"
            >
                {{ page.props.flash.success }}
            </div>

            <section
                v-if="!editing"
                class="rounded-lg border border-emerald-200 bg-white p-5 text-sm"
            >
                <h2 class="text-base font-semibold text-emerald-800">
                    You're checked in online
                </h2>
                <p class="mt-1 text-gray-600">
                    The front desk has your details. Show your ID at arrival to
                    collect your key.
                </p>
                <button
                    v-if="!closedReason"
                    type="button"
                    class="mt-3 text-sm font-medium text-emerald-700 underline underline-offset-4"
                    @click="startEditing"
                >
                    Change my details
                </button>
            </section>

            <section
                v-else-if="closedReason"
                class="rounded-lg border border-amber-200 bg-amber-50 p-5 text-sm text-amber-900"
            >
                {{ closedReason }}
            </section>

            <form v-else class="flex flex-col gap-5" @submit.prevent="submit">
                <p
                    v-if="settings.instructions"
                    class="text-sm whitespace-pre-line text-gray-600"
                >
                    {{ settings.instructions }}
                </p>
                <p
                    v-if="formError"
                    class="rounded-md bg-red-50 p-3 text-sm text-red-700"
                >
                    {{ formError }}
                </p>

                <fieldset
                    class="flex flex-col gap-3 rounded-lg border border-gray-200 bg-white p-4"
                >
                    <legend class="px-1 text-sm font-semibold">
                        Your details
                    </legend>
                    <div class="grid gap-3 sm:grid-cols-2">
                        <label :class="labelClass" for="wc-first-name"
                            >First name
                            <input
                                id="wc-first-name"
                                v-model="form.first_name"
                                required
                                autocomplete="given-name"
                                :class="inputClass"
                            />
                            <span
                                v-if="form.errors.first_name"
                                class="text-xs text-red-600"
                                >{{ form.errors.first_name }}</span
                            >
                        </label>
                        <label :class="labelClass" for="wc-last-name"
                            >Last name
                            <input
                                id="wc-last-name"
                                v-model="form.last_name"
                                autocomplete="family-name"
                                :class="inputClass"
                            />
                        </label>
                        <label :class="labelClass" for="wc-email"
                            >E-mail
                            <input
                                id="wc-email"
                                v-model="form.email"
                                type="email"
                                required
                                autocomplete="email"
                                :class="inputClass"
                            />
                            <span
                                v-if="form.errors.email"
                                class="text-xs text-red-600"
                                >{{ form.errors.email }}</span
                            >
                        </label>
                        <label :class="labelClass" for="wc-phone"
                            >Phone
                            <input
                                id="wc-phone"
                                v-model="form.phone"
                                type="tel"
                                required
                                autocomplete="tel"
                                :class="inputClass"
                            />
                            <span
                                v-if="form.errors.phone"
                                class="text-xs text-red-600"
                                >{{ form.errors.phone }}</span
                            >
                        </label>
                        <label :class="labelClass" for="wc-nationality"
                            >Nationality
                            <input
                                id="wc-nationality"
                                v-model="form.nationality"
                                autocomplete="country-name"
                                :class="inputClass"
                            />
                        </label>
                        <label
                            v-if="settings.ask_arrival_time"
                            :class="labelClass"
                            for="wc-arrival"
                            >Arrival time
                            <input
                                id="wc-arrival"
                                v-model="form.arrival_time"
                                type="time"
                                :class="inputClass"
                            />
                            <span
                                v-if="form.errors.arrival_time"
                                class="text-xs text-red-600"
                                >{{ form.errors.arrival_time }}</span
                            >
                        </label>
                    </div>
                    <div class="grid gap-3 sm:grid-cols-[1fr_8rem]">
                        <label :class="labelClass" for="wc-address"
                            >Home address<span
                                v-if="!settings.require_address"
                                class="font-normal text-gray-500"
                            >
                                (optional)</span
                            >
                            <input
                                id="wc-address"
                                v-model="form.address"
                                :required="settings.require_address"
                                autocomplete="street-address"
                                :class="inputClass"
                            />
                            <span
                                v-if="form.errors.address"
                                class="text-xs text-red-600"
                                >{{ form.errors.address }}</span
                            >
                        </label>
                        <label :class="labelClass" for="wc-postal"
                            >Postal code
                            <input
                                id="wc-postal"
                                v-model="form.postal_code"
                                autocomplete="postal-code"
                                :class="inputClass"
                            />
                        </label>
                    </div>
                </fieldset>

                <fieldset
                    v-if="settings.require_id_document"
                    class="flex flex-col gap-3 rounded-lg border border-gray-200 bg-white p-4"
                >
                    <legend class="px-1 text-sm font-semibold">
                        ID document
                    </legend>
                    <div class="grid gap-3 sm:grid-cols-2">
                        <label :class="labelClass" for="wc-id-type"
                            >ID type
                            <select
                                id="wc-id-type"
                                v-model="form.id_type"
                                required
                                :class="inputClass"
                            >
                                <option value="" disabled>Choose</option>
                                <option
                                    v-for="type in idTypes"
                                    :key="type"
                                    :value="type"
                                >
                                    {{ type }}
                                </option>
                            </select>
                            <span
                                v-if="form.errors.id_type"
                                class="text-xs text-red-600"
                                >{{ form.errors.id_type }}</span
                            >
                        </label>
                        <label :class="labelClass" for="wc-id-number"
                            >ID number
                            <input
                                id="wc-id-number"
                                v-model="form.id_number"
                                required
                                :class="inputClass"
                            />
                            <span
                                v-if="form.errors.id_number"
                                class="text-xs text-red-600"
                                >{{ form.errors.id_number }}</span
                            >
                        </label>
                    </div>
                    <label :class="labelClass" for="wc-id-file">
                        Photo or scan of the ID (JPG, PNG or PDF, up to 5
                        MB)<span
                            v-if="hasIdDocument"
                            class="font-normal text-gray-500"
                        >
                            · already uploaded; add a new one to replace
                            it</span
                        >
                        <input
                            id="wc-id-file"
                            type="file"
                            accept="image/jpeg,image/png,application/pdf"
                            :required="needsDocument"
                            class="mt-1 block w-full text-sm"
                            @change="pickDocument"
                        />
                        <span
                            v-if="form.errors.id_document"
                            class="text-xs text-red-600"
                            >{{ form.errors.id_document }}</span
                        >
                    </label>
                </fieldset>

                <label
                    class="rounded-lg border border-gray-200 bg-white p-4"
                    :class="labelClass"
                    for="wc-requests"
                    >Anything we should know?
                    <textarea
                        id="wc-requests"
                        v-model="form.special_requests"
                        rows="2"
                        maxlength="1000"
                        :class="inputClass"
                    ></textarea>
                </label>

                <fieldset
                    v-if="settings.require_signature"
                    class="rounded-lg border border-gray-200 bg-white p-4"
                >
                    <legend class="px-1 text-sm font-semibold">
                        Signature
                    </legend>
                    <p class="text-xs text-gray-500">
                        Sign with your finger or mouse. It goes on your
                        registration card.
                    </p>
                    <canvas
                        ref="canvas"
                        class="mt-2 h-36 w-full touch-none rounded-md border border-dashed border-gray-400 bg-white"
                        aria-label="Signature pad"
                        @pointerdown="startStroke"
                        @pointermove="stroke"
                        @pointerup="endStroke"
                        @pointerleave="endStroke"
                    ></canvas>
                    <div class="mt-1 flex justify-between">
                        <span
                            v-if="form.errors.signature"
                            class="text-xs text-red-600"
                            >{{ form.errors.signature }}</span
                        >
                        <button
                            type="button"
                            class="ml-auto text-xs text-gray-600 underline"
                            @click="clearSignature"
                        >
                            Clear
                        </button>
                    </div>
                </fieldset>

                <label
                    v-if="settings.terms"
                    class="flex items-start gap-2 text-sm"
                    for="wc-terms"
                >
                    <input
                        id="wc-terms"
                        v-model="form.accept_terms"
                        type="checkbox"
                        required
                        class="mt-1"
                    />
                    <span>
                        I accept the hotel's terms:
                        <span
                            class="mt-1 block max-h-32 overflow-y-auto rounded border border-gray-200 bg-white p-2 text-xs whitespace-pre-line text-gray-600"
                            >{{ settings.terms }}</span
                        >
                        <span
                            v-if="form.errors.accept_terms"
                            class="text-xs text-red-600"
                            >{{ form.errors.accept_terms }}</span
                        >
                    </span>
                </label>

                <label class="flex items-start gap-2 text-sm" for="wc-privacy">
                    <input
                        id="wc-privacy"
                        v-model="form.accept_privacy"
                        type="checkbox"
                        required
                        class="mt-1"
                    />
                    <span>
                        I have read the
                        <a
                            :href="legal.privacy_url"
                            target="_blank"
                            rel="noopener"
                            class="underline"
                            >privacy policy</a
                        >
                        and consent to {{ property.name }} using these details,
                        including my ID, for my stay and the records the law
                        requires.
                        <span
                            v-if="form.errors.accept_privacy"
                            class="block text-xs text-red-600"
                            >{{ form.errors.accept_privacy }}</span
                        >
                    </span>
                </label>

                <label
                    v-if="legal.marketing_opt_in"
                    class="flex items-start gap-2 text-sm"
                    for="wc-marketing"
                >
                    <input
                        id="wc-marketing"
                        v-model="form.marketing"
                        type="checkbox"
                        class="mt-1"
                    />
                    <span
                        >Send me offers and news (optional; you can stop them
                        any time).</span
                    >
                </label>

                <button
                    type="submit"
                    :disabled="form.processing"
                    class="rounded-md bg-emerald-700 px-4 py-3 text-sm font-semibold text-white disabled:opacity-60"
                >
                    {{ form.processing ? 'Submitting…' : 'Complete check-in' }}
                </button>
            </form>
        </main>
    </div>
</template>
