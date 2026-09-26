<script setup lang="ts">
import AddressCascadeFields, {
    type AddressValue,
} from '@/components/AddressCascadeFields.vue';
import PhoneCountryCodeSelect, {
    type PhoneCountryOption,
} from '@/components/PhoneCountryCodeSelect.vue';
import { useCan } from '@/lib/useCan';
import admin from '@/routes/admin';
import { usePage } from '@inertiajs/vue3';
import { computed, reactive, ref, watch } from 'vue';

export type GuestCompany = {
    gstin: string;
    name: string;
    email: string;
    integration_id: string;
    address: string;
};

/** A City Ledger account the guest's company can be (Add/Update Company). */
export type CompanyAccount = {
    id: number;
    name: string;
    code: string | null;
    account_type: string;
    tax_number: string | null;
    email: string | null;
    address: string | null;
    payment_terms_days: number;
    credit_limit: number | null;
    available_credit?: number | null;
};

export type GuestInternationalDetails = {
    passport_number: string;
    passport_issue_date: string;
    passport_issue_place: string;
    passport_validity: string;
    visa_issue_place: string;
    visa_number: string;
    visa_type: string;
    visa_issue_date: string;
    visa_valid_till: string;
    employed_in_india: string;
    next_destination: string;
    phone_in_india_country_code: string;
    phone_in_india: string;
    arrival_from: string;
    arrival_date: string;
    reference_address: AddressValue;
};

export type GuestRecord = {
    id: number;
    title: string | null;
    first_name: string;
    last_name: string | null;
    phone_country_code: string | null;
    phone: string | null;
    emails: string[] | null;
    gender: string | null;
    dob: string | null;
    nationality: string | null;
    purpose_of_visit: string | null;
    guest_preferences: string | null;
    subscribe_newsletter: boolean;
    anniversary: string | null;
    frequent_flyer: string | null;
    vehicle_number: string | null;
    place_of_birth: string | null;
    present_business_address: string | null;
    address: AddressValue | null;
    company: GuestCompany | null;
    city_ledger_account_id?: number | null;
    city_ledger_account?: CompanyAccount | null;
    international_details: GuestInternationalDetails | null;
};

const props = defineProps<{
    show: boolean;
    property: { id: number; name: string; country_id?: number | null };
    countries: PhoneCountryOption[];
    guest?: GuestRecord | null;
}>();

const emit = defineEmits<{
    close: [];
    saved: [guest: GuestRecord];
}>();

function defaultPhoneCountryCode(): string {
    const propertyCountry = props.countries.find(
        (country) => country.id === props.property.country_id,
    );

    return propertyCountry ? `+${propertyCountry.phonecode}` : '+91';
}

const titleOptions = ['Mr.', 'Mrs.', 'Ms.', 'Dr.'];
const genderOptions = ['Male', 'Female', 'Other'];
const purposeOptions = ['Leisure', 'Business'];
const visaTypeOptions = [
    'Tourist visa',
    'Student visa',
    'Business visa',
    'Immigration visa',
];
const employedInIndiaOptions = ['Yes', 'No'];

function emptyAddress(): AddressValue {
    return {
        address: '',
        country_id: null,
        state_id: null,
        city_id: null,
        postal_code: '',
    };
}

function emptyCompany(): GuestCompany {
    return { gstin: '', name: '', email: '', integration_id: '', address: '' };
}

function emptyInternationalDetails(): GuestInternationalDetails {
    return {
        passport_number: '',
        passport_issue_date: '',
        passport_issue_place: '',
        passport_validity: '',
        visa_issue_place: '',
        visa_number: '',
        visa_type: '',
        visa_issue_date: '',
        visa_valid_till: '',
        employed_in_india: '',
        next_destination: '',
        phone_in_india_country_code: '+91',
        phone_in_india: '',
        arrival_from: '',
        arrival_date: '',
        reference_address: emptyAddress(),
    };
}

type FormState = {
    title: string;
    first_name: string;
    last_name: string;
    phone_country_code: string;
    phone: string;
    emails: string[];
    gender: string;
    dob: string;
    nationality: string;
    purpose_of_visit: string;
    guest_preferences: string;
    subscribe_newsletter: boolean;
    anniversary: string;
    frequent_flyer: string;
    vehicle_number: string;
    place_of_birth: string;
    present_business_address: string;
    address: AddressValue;
    company: GuestCompany;
    company_account: CompanyAccount | null;
    create_city_ledger_account: boolean;
    international_details: GuestInternationalDetails;
};

function emptyForm(): FormState {
    return {
        title: '',
        first_name: '',
        last_name: '',
        phone_country_code: defaultPhoneCountryCode(),
        phone: '',
        // One e-mail input shows from the start, like Mobile Number; "+ Add another e-mail" adds more.
        emails: [''],
        gender: '',
        dob: '',
        nationality: '',
        purpose_of_visit: '',
        guest_preferences: '',
        subscribe_newsletter: false,
        anniversary: '',
        frequent_flyer: '',
        vehicle_number: '',
        place_of_birth: '',
        present_business_address: '',
        address: emptyAddress(),
        company: emptyCompany(),
        company_account: null,
        create_city_ledger_account: false,
        international_details: emptyInternationalDetails(),
    };
}

function formFromGuest(guest: GuestRecord): FormState {
    return {
        title: guest.title ?? '',
        first_name: guest.first_name,
        last_name: guest.last_name ?? '',
        phone_country_code:
            guest.phone_country_code ?? defaultPhoneCountryCode(),
        phone: guest.phone ?? '',
        emails: guest.emails?.length ? [...guest.emails] : [],
        gender: guest.gender ?? '',
        dob: guest.dob ?? '',
        nationality: guest.nationality ?? '',
        purpose_of_visit: guest.purpose_of_visit ?? '',
        guest_preferences: guest.guest_preferences ?? '',
        subscribe_newsletter: guest.subscribe_newsletter,
        anniversary: guest.anniversary ?? '',
        frequent_flyer: guest.frequent_flyer ?? '',
        vehicle_number: guest.vehicle_number ?? '',
        place_of_birth: guest.place_of_birth ?? '',
        present_business_address: guest.present_business_address ?? '',
        address: guest.address ?? emptyAddress(),
        company: guest.company ?? emptyCompany(),
        company_account: guest.city_ledger_account ?? null,
        create_city_ledger_account: false,
        international_details:
            guest.international_details ?? emptyInternationalDetails(),
    };
}

const form = reactive<FormState>(emptyForm());
const processing = ref(false);
const errors = ref<Record<string, string>>({});

/* Scan ID: a photo of the guest's Aadhaar, passport, voter ID or driving licence fills the form. */
type ScanResult = {
    scan: {
        document_type: string;
        document_label: string;
        /** How it was read: on the server (Aadhaar QR, passport MRZ) or by AI. */
        method: 'aadhaar_qr' | 'passport_mrz' | 'ai';
        method_label: string;
        document_number: string | null;
        confidence: 'low' | 'medium' | 'high';
        notes: string | null;
    };
    guest: Partial<
        Pick<
            GuestRecord,
            'first_name' | 'last_name' | 'gender' | 'dob' | 'nationality'
        >
    > & {
        address?: Partial<Pick<AddressValue, 'address' | 'postal_code'>>;
        international_details?: Partial<
            Pick<
                GuestInternationalDetails,
                | 'passport_number'
                | 'passport_issue_date'
                | 'passport_validity'
                | 'passport_issue_place'
            >
        >;
    };
};

const scanning = ref(false);
const scanResult = ref<ScanResult['scan'] | null>(null);
const scanError = ref('');
const scanInput = ref<HTMLInputElement | null>(null);

async function scanIdDocument(event: Event): Promise<void> {
    const file = (event.target as HTMLInputElement).files?.[0];

    if (!file || scanning.value) {
        return;
    }

    scanning.value = true;
    scanError.value = '';
    scanResult.value = null;

    const body = new FormData();
    body.append('document', file);

    try {
        const response = await fetch(
            admin.guests.scanId(props.property.id).url,
            {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'X-XSRF-TOKEN': getXsrfToken(),
                },
                credentials: 'same-origin',
                body,
            },
        );
        const data = (await response.json()) as ScanResult & {
            message?: string;
            errors?: Record<string, string[]>;
        };

        if (!response.ok) {
            scanError.value =
                data.errors?.document?.[0] ??
                data.message ??
                'The ID could not be read.';

            return;
        }

        applyScan(data);
    } catch {
        scanError.value = 'The ID could not be read.';
    } finally {
        scanning.value = false;

        if (scanInput.value) {
            scanInput.value.value = '';
        }
    }
}

/** Fills the fields the document carries (others stay as they are) for staff to review. */
function applyScan(data: ScanResult): void {
    const guest = data.guest;

    if (guest.first_name) {
        form.first_name = guest.first_name;
    }

    if (guest.last_name) {
        form.last_name = guest.last_name;
        nullToggles.last_name = false;
    }

    if (guest.gender) {
        form.gender = guest.gender;
        nullToggles.gender = false;
    }

    if (guest.dob) {
        form.dob = guest.dob;
        nullToggles.dob = false;
    }

    if (guest.nationality) {
        form.nationality = guest.nationality;
        nullToggles.nationality = false;
    }

    if (guest.address) {
        form.address = { ...form.address, ...guest.address };
        addressOpen.value = true;
    }

    if (guest.international_details) {
        form.international_details = {
            ...form.international_details,
            ...guest.international_details,
        };
        internationalOpen.value = true;
    }

    scanResult.value = data.scan;
}

type NullableField =
    | 'last_name'
    | 'email'
    | 'gender'
    | 'dob'
    | 'nationality'
    | 'purpose_of_visit';

const nullToggles = reactive<Record<NullableField, boolean>>({
    last_name: false,
    email: false,
    gender: false,
    dob: false,
    nationality: false,
    purpose_of_visit: false,
});

/** Shared by HandleInertiaRequests from Application Settings → Guest. */
const page = usePage();

/** "Property Country" resolves to this property's country; "Nil" leaves nationality empty. */
function defaultNationality(): string {
    const setting =
        (page.props.guestSettings as { default_nationality?: string } | null)
            ?.default_nationality ?? '';

    if (setting === 'property_country') {
        return (
            props.countries.find(
                (country) => country.id === props.property.country_id,
            )?.name ?? ''
        );
    }

    return setting;
}

function resetForNewGuest(): void {
    Object.assign(form, emptyForm());
    form.nationality = defaultNationality();
    (Object.keys(nullToggles) as NullableField[]).forEach((key) => {
        nullToggles[key] = false;
    });
    addressOpen.value = false;
    companyModalOpen.value = false;
    internationalOpen.value = false;
    guestHistoryOpen.value = false;
    otherDetailsOpen.value = true;
    errors.value = {};
}

watch(
    () => props.show,
    (show) => {
        if (!show) {
            return;
        }

        if (props.guest) {
            Object.assign(form, formFromGuest(props.guest));
            nullToggles.last_name = !form.last_name;
            nullToggles.email = form.emails.length === 0;
            nullToggles.gender = !form.gender;
            nullToggles.dob = !form.dob;
            nullToggles.nationality = !form.nationality;
            nullToggles.purpose_of_visit = !form.purpose_of_visit;
            addressOpen.value = !!props.guest.address?.country_id;
            internationalOpen.value =
                !!props.guest.international_details?.passport_number;
        } else {
            resetForNewGuest();
        }
    },
    { immediate: true },
);

const addressOpen = ref(false);
const companyModalOpen = ref(false);
const internationalOpen = ref(false);
const guestHistoryOpen = ref(false);
const otherDetailsOpen = ref(true);

const companyForm = reactive<GuestCompany>(emptyCompany());
/** The company's City Ledger account in the modal: who pays when the booking bills the company. */
const companyAccount = ref<CompanyAccount | null>(null);
const createCompanyAccount = ref(false);
const companySearch = ref('');
const companyResults = ref<CompanyAccount[]>([]);
const canManageCityLedger = useCan()('database.city_ledger.manage');
let companySearchTimer: ReturnType<typeof setTimeout> | undefined;

function openCompanyModal(): void {
    Object.assign(
        companyForm,
        form.company.name || form.company.gstin ? form.company : emptyCompany(),
    );
    companyAccount.value = form.company_account;
    createCompanyAccount.value = form.create_city_ledger_account;
    companySearch.value = '';
    companyResults.value = [];
    companyModalOpen.value = true;
}

watch(companySearch, (term) => {
    clearTimeout(companySearchTimer);

    if (term.trim() === '') {
        companyResults.value = [];

        return;
    }

    companySearchTimer = setTimeout(async () => {
        const response = await fetch(
            admin.guests.companies(props.property.id, {
                query: { q: term.trim() },
            }).url,
            {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
            },
        );

        if (response.ok) {
            companyResults.value = (
                (await response.json()) as { companies: CompanyAccount[] }
            ).companies;
        }
    }, 250);
});

/** Picking an account links it and fills the company details from it. */
function pickCompanyAccount(account: CompanyAccount): void {
    companyAccount.value = account;
    createCompanyAccount.value = false;
    companyForm.name = account.name;
    companyForm.gstin = account.tax_number ?? companyForm.gstin;
    companyForm.email = account.email ?? companyForm.email;
    companyForm.address = account.address ?? companyForm.address;
    companySearch.value = '';
    companyResults.value = [];
}

function addCompanyToGuest(): void {
    Object.assign(form.company, companyForm);
    form.company_account = companyAccount.value;
    form.create_city_ledger_account =
        companyAccount.value === null && createCompanyAccount.value;
    companyModalOpen.value = false;
}

function cancelCompanyModal(): void {
    companyModalOpen.value = false;
}

function addEmailField(): void {
    form.emails.push('');
}

function removeEmailField(index: number): void {
    form.emails.splice(index, 1);

    // The last row is cleared rather than removed, so an input stays on screen.
    if (form.emails.length === 0) {
        form.emails.push('');
    }
}

watch(
    () => nullToggles.email,
    (checked) => {
        if (checked) {
            form.emails = [];
        } else if (form.emails.length === 0) {
            form.emails = [''];
        }
    },
);

function getXsrfToken(): string {
    const match = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]*)/);

    return match ? decodeURIComponent(match[1]) : '';
}

function payload(): Record<string, unknown> {
    return {
        title: form.title || null,
        first_name: form.first_name,
        last_name: nullToggles.last_name ? null : form.last_name || null,
        phone_country_code: form.phone_country_code || null,
        phone: form.phone,
        emails: nullToggles.email
            ? []
            : form.emails.filter((email) => email.trim() !== ''),
        gender: nullToggles.gender ? null : form.gender || null,
        dob: nullToggles.dob ? null : form.dob || null,
        nationality: nullToggles.nationality ? null : form.nationality || null,
        purpose_of_visit: nullToggles.purpose_of_visit
            ? null
            : form.purpose_of_visit || null,
        guest_preferences: form.guest_preferences || null,
        subscribe_newsletter: form.subscribe_newsletter,
        anniversary: form.anniversary || null,
        frequent_flyer: form.frequent_flyer || null,
        vehicle_number: form.vehicle_number || null,
        place_of_birth: form.place_of_birth || null,
        present_business_address: form.present_business_address || null,
        address: addressOpen.value ? form.address : null,
        company: form.company.name || form.company.gstin ? form.company : null,
        city_ledger_account_id: form.company_account?.id ?? null,
        create_city_ledger_account: form.create_city_ledger_account,
        international_details: internationalOpen.value
            ? {
                  ...form.international_details,
                  reference_address:
                      form.international_details.reference_address,
              }
            : null,
    };
}

const canSave = computed(
    () => form.first_name.trim() !== '' && form.phone.trim() !== '',
);

async function save(): Promise<void> {
    if (!canSave.value || processing.value) {
        return;
    }

    processing.value = true;
    errors.value = {};

    const url = props.guest
        ? admin.guests.update({
              property: props.property.id,
              guest: props.guest.id,
          }).url
        : admin.guests.store(props.property).url;

    try {
        const response = await fetch(url, {
            method: props.guest ? 'PUT' : 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-XSRF-TOKEN': getXsrfToken(),
            },
            credentials: 'same-origin',
            body: JSON.stringify(payload()),
        });

        if (response.status === 422) {
            const data = (await response.json()) as {
                errors: Record<string, string[]>;
            };
            errors.value = Object.fromEntries(
                Object.entries(data.errors).map(([key, messages]) => [
                    key,
                    messages[0],
                ]),
            );

            return;
        }

        if (!response.ok) {
            return;
        }

        const data = (await response.json()) as { guest: GuestRecord };
        emit('saved', data.guest);
    } finally {
        processing.value = false;
    }
}

function close(): void {
    emit('close');
}
</script>

<template>
    <div
        v-if="show"
        class="fixed inset-0 z-50 flex items-center justify-center bg-black/30 p-4"
        @click.self="close"
    >
        <div
            class="flex max-h-[90vh] w-full max-w-3xl flex-col overflow-hidden rounded-lg border border-[#e3e3e0] bg-white dark:border-[#3E3E3A] dark:bg-[#161615]"
        >
            <div
                class="flex items-center justify-between border-b border-[#e3e3e0] px-6 py-4 dark:border-[#3E3E3A]"
            >
                <h2
                    class="font-display text-lg font-semibold text-[#1b1b18] dark:text-[#EDEDEC]"
                >
                    {{ guest ? 'Update Guest' : 'Add Guest' }}
                </h2>
                <button
                    type="button"
                    class="text-sm text-[#706f6c] hover:text-[#1b1b18] dark:text-[#A1A09A] dark:hover:text-[#EDEDEC]"
                    @click="close"
                >
                    ✕
                </button>
            </div>

            <div class="flex-1 overflow-y-auto px-6 py-4">
                <!-- Basic Details -->
                <section class="mb-4">
                    <div
                        class="mb-3 flex flex-wrap items-center justify-between gap-2"
                    >
                        <h3
                            class="text-sm font-semibold tracking-wide text-[#1b1b18] uppercase dark:text-[#EDEDEC]"
                        >
                            Basic Details
                        </h3>
                        <label
                            class="inline-flex cursor-pointer items-center gap-2 rounded-md border border-[#e3e3e0] px-3 py-1.5 text-xs font-medium text-[#1b1b18] hover:bg-[#f5f5f4] dark:border-[#3E3E3A] dark:text-[#EDEDEC] dark:hover:bg-[#1D1D1B]"
                            :class="{
                                'pointer-events-none opacity-60': scanning,
                            }"
                            title="Fill the form from a photo of the guest's Aadhaar, passport, voter ID or driving licence"
                        >
                            <svg
                                xmlns="http://www.w3.org/2000/svg"
                                class="h-4 w-4"
                                fill="none"
                                viewBox="0 0 24 24"
                                stroke="currentColor"
                            >
                                <path
                                    stroke-linecap="round"
                                    stroke-linejoin="round"
                                    stroke-width="2"
                                    d="M3 7V5a2 2 0 012-2h2M17 3h2a2 2 0 012 2v2M21 17v2a2 2 0 01-2 2h-2M7 21H5a2 2 0 01-2-2v-2M7 12h10"
                                />
                            </svg>
                            {{ scanning ? 'Reading ID…' : 'Scan ID document' }}
                            <input
                                ref="scanInput"
                                type="file"
                                accept="image/jpeg,image/png,image/webp"
                                capture="environment"
                                class="sr-only"
                                data-test="scan-id-input"
                                @change="scanIdDocument"
                            />
                        </label>
                    </div>
                    <p
                        v-if="scanError"
                        class="mb-3 text-xs text-[#f53003]"
                        data-test="scan-id-error"
                    >
                        {{ scanError }}
                    </p>
                    <p
                        v-else-if="scanResult"
                        class="mb-3 rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-900 dark:border-amber-900 dark:bg-amber-950 dark:text-amber-200"
                        data-test="scan-id-result"
                    >
                        Filled from {{ scanResult.document_label
                        }}<template v-if="scanResult.document_number">
                            {{ scanResult.document_number }}</template
                        >
                        (read from the {{ scanResult.method_label }},
                        {{ scanResult.confidence }} confidence). Check every
                        field against the document before saving; the photo was
                        not stored.
                        <template v-if="scanResult.notes">
                            {{ scanResult.notes }}</template
                        >
                    </p>

                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <label
                            class="flex flex-col gap-1 text-sm text-[#706f6c] dark:text-[#A1A09A]"
                        >
                            Title
                            <select
                                v-model="form.title"
                                class="block rounded-md border border-[#e3e3e0] bg-white px-3 py-2 text-sm text-[#1b1b18] focus:border-[#f53003] focus:ring-1 focus:ring-[#f53003] focus:outline-none dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]"
                            >
                                <option value="">--Select--</option>
                                <option
                                    v-for="option in titleOptions"
                                    :key="option"
                                    :value="option"
                                >
                                    {{ option }}
                                </option>
                            </select>
                        </label>

                        <label
                            class="flex flex-col gap-1 text-sm text-[#706f6c] dark:text-[#A1A09A]"
                        >
                            First Name*
                            <input
                                v-model="form.first_name"
                                type="text"
                                required
                                class="block rounded-md border border-[#e3e3e0] bg-white px-3 py-2 text-sm text-[#1b1b18] focus:border-[#f53003] focus:ring-1 focus:ring-[#f53003] focus:outline-none dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]"
                            />
                            <span
                                v-if="errors.first_name"
                                class="text-xs text-[#f53003]"
                                >{{ errors.first_name }}</span
                            >
                        </label>

                        <div
                            class="flex flex-col gap-1 text-sm text-[#706f6c] dark:text-[#A1A09A]"
                        >
                            <span class="flex items-center gap-2">
                                Last Name
                                <label class="flex items-center gap-1 text-xs">
                                    <input
                                        v-model="nullToggles.last_name"
                                        type="checkbox"
                                        class="h-3.5 w-3.5 rounded border-[#e3e3e0] text-[#f53003] focus:ring-[#f53003] dark:border-[#3E3E3A]"
                                    />
                                    None
                                </label>
                            </span>
                            <input
                                v-model="form.last_name"
                                type="text"
                                :disabled="nullToggles.last_name"
                                class="block rounded-md border border-[#e3e3e0] bg-white px-3 py-2 text-sm text-[#1b1b18] focus:border-[#f53003] focus:ring-1 focus:ring-[#f53003] focus:outline-none disabled:cursor-not-allowed disabled:bg-[#f5f5f4] dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC] dark:disabled:bg-[#1D1D1B]"
                            />
                        </div>

                        <label
                            class="flex flex-col gap-1 text-sm text-[#706f6c] dark:text-[#A1A09A]"
                        >
                            Mobile Number*
                            <div class="flex gap-2">
                                <PhoneCountryCodeSelect
                                    v-model="form.phone_country_code"
                                    :countries="countries"
                                />
                                <input
                                    v-model="form.phone"
                                    type="text"
                                    required
                                    class="block flex-1 rounded-md border border-[#e3e3e0] bg-white px-3 py-2 text-sm text-[#1b1b18] focus:border-[#f53003] focus:ring-1 focus:ring-[#f53003] focus:outline-none dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]"
                                />
                            </div>
                            <span
                                v-if="errors.phone"
                                class="text-xs text-[#f53003]"
                                >{{ errors.phone }}</span
                            >
                        </label>

                        <div
                            class="col-span-full flex flex-col gap-1 text-sm text-[#706f6c] dark:text-[#A1A09A]"
                        >
                            <span class="flex items-center gap-2">
                                E-mail
                                <label class="flex items-center gap-1 text-xs">
                                    <input
                                        v-model="nullToggles.email"
                                        type="checkbox"
                                        class="h-3.5 w-3.5 rounded border-[#e3e3e0] text-[#f53003] focus:ring-[#f53003] dark:border-[#3E3E3A]"
                                    />
                                    None
                                </label>
                            </span>
                            <div
                                v-if="!nullToggles.email"
                                class="flex flex-col gap-2"
                            >
                                <div
                                    v-for="(email, index) in form.emails"
                                    :key="index"
                                    class="flex items-center gap-2"
                                >
                                    <input
                                        v-model="form.emails[index]"
                                        type="email"
                                        class="block flex-1 rounded-md border border-[#e3e3e0] bg-white px-3 py-2 text-sm text-[#1b1b18] focus:border-[#f53003] focus:ring-1 focus:ring-[#f53003] focus:outline-none dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]"
                                    />
                                    <button
                                        type="button"
                                        class="text-[#706f6c] hover:text-[#f53003] dark:text-[#A1A09A]"
                                        @click="removeEmailField(index)"
                                    >
                                        🗑
                                    </button>
                                </div>
                                <button
                                    type="button"
                                    class="self-start text-sm font-medium text-[#f53003] dark:text-[#FF4433]"
                                    @click="addEmailField"
                                >
                                    + Add another e-mail
                                </button>
                            </div>
                        </div>

                        <div
                            class="flex flex-col gap-1 text-sm text-[#706f6c] dark:text-[#A1A09A]"
                        >
                            <span class="flex items-center gap-2">
                                Gender
                                <label class="flex items-center gap-1 text-xs">
                                    <input
                                        v-model="nullToggles.gender"
                                        type="checkbox"
                                        class="h-3.5 w-3.5 rounded border-[#e3e3e0] text-[#f53003] focus:ring-[#f53003] dark:border-[#3E3E3A]"
                                    />
                                    None
                                </label>
                            </span>
                            <select
                                v-model="form.gender"
                                :disabled="nullToggles.gender"
                                class="block rounded-md border border-[#e3e3e0] bg-white px-3 py-2 text-sm text-[#1b1b18] focus:border-[#f53003] focus:ring-1 focus:ring-[#f53003] focus:outline-none disabled:cursor-not-allowed disabled:bg-[#f5f5f4] dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC] dark:disabled:bg-[#1D1D1B]"
                            >
                                <option value="">--Select--</option>
                                <option
                                    v-for="option in genderOptions"
                                    :key="option"
                                    :value="option"
                                >
                                    {{ option }}
                                </option>
                            </select>
                        </div>

                        <div
                            class="flex flex-col gap-1 text-sm text-[#706f6c] dark:text-[#A1A09A]"
                        >
                            <span class="flex items-center gap-2">
                                Date of Birth
                                <label class="flex items-center gap-1 text-xs">
                                    <input
                                        v-model="nullToggles.dob"
                                        type="checkbox"
                                        class="h-3.5 w-3.5 rounded border-[#e3e3e0] text-[#f53003] focus:ring-[#f53003] dark:border-[#3E3E3A]"
                                    />
                                    None
                                </label>
                            </span>
                            <input
                                v-model="form.dob"
                                type="date"
                                :disabled="nullToggles.dob"
                                class="block rounded-md border border-[#e3e3e0] bg-white px-3 py-2 text-sm text-[#1b1b18] focus:border-[#f53003] focus:ring-1 focus:ring-[#f53003] focus:outline-none disabled:cursor-not-allowed disabled:bg-[#f5f5f4] dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC] dark:disabled:bg-[#1D1D1B]"
                            />
                        </div>

                        <div
                            class="flex flex-col gap-1 text-sm text-[#706f6c] dark:text-[#A1A09A]"
                        >
                            <span class="flex items-center gap-2">
                                Nationality
                                <label class="flex items-center gap-1 text-xs">
                                    <input
                                        v-model="nullToggles.nationality"
                                        type="checkbox"
                                        class="h-3.5 w-3.5 rounded border-[#e3e3e0] text-[#f53003] focus:ring-[#f53003] dark:border-[#3E3E3A]"
                                    />
                                    None
                                </label>
                            </span>
                            <select
                                v-model="form.nationality"
                                :disabled="nullToggles.nationality"
                                class="block rounded-md border border-[#e3e3e0] bg-white px-3 py-2 text-sm text-[#1b1b18] focus:border-[#f53003] focus:ring-1 focus:ring-[#f53003] focus:outline-none disabled:cursor-not-allowed disabled:bg-[#f5f5f4] dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC] dark:disabled:bg-[#1D1D1B]"
                            >
                                <option value="">--Select--</option>
                                <option
                                    v-for="country in countries"
                                    :key="country.id"
                                    :value="country.name"
                                >
                                    {{ country.name }}
                                </option>
                            </select>
                        </div>

                        <div
                            class="flex flex-col gap-1 text-sm text-[#706f6c] dark:text-[#A1A09A]"
                        >
                            <span class="flex items-center gap-2">
                                Purpose of Visit
                                <label class="flex items-center gap-1 text-xs">
                                    <input
                                        v-model="nullToggles.purpose_of_visit"
                                        type="checkbox"
                                        class="h-3.5 w-3.5 rounded border-[#e3e3e0] text-[#f53003] focus:ring-[#f53003] dark:border-[#3E3E3A]"
                                    />
                                    None
                                </label>
                            </span>
                            <select
                                v-model="form.purpose_of_visit"
                                :disabled="nullToggles.purpose_of_visit"
                                class="block rounded-md border border-[#e3e3e0] bg-white px-3 py-2 text-sm text-[#1b1b18] focus:border-[#f53003] focus:ring-1 focus:ring-[#f53003] focus:outline-none disabled:cursor-not-allowed disabled:bg-[#f5f5f4] dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC] dark:disabled:bg-[#1D1D1B]"
                            >
                                <option value="">--Select--</option>
                                <option
                                    v-for="option in purposeOptions"
                                    :key="option"
                                    :value="option"
                                >
                                    {{ option }}
                                </option>
                            </select>
                        </div>

                        <label
                            class="col-span-full flex flex-col gap-1 text-sm text-[#706f6c] dark:text-[#A1A09A]"
                        >
                            Guest Preferences
                            <textarea
                                v-model="form.guest_preferences"
                                rows="2"
                                class="block rounded-md border border-[#e3e3e0] bg-white px-3 py-2 text-sm text-[#1b1b18] focus:border-[#f53003] focus:ring-1 focus:ring-[#f53003] focus:outline-none dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]"
                            ></textarea>
                        </label>

                        <label
                            class="col-span-full flex items-center gap-2 text-sm font-medium text-[#1b1b18] dark:text-[#EDEDEC]"
                        >
                            <input
                                v-model="form.subscribe_newsletter"
                                type="checkbox"
                                class="h-4 w-4 rounded border-[#e3e3e0] text-[#f53003] focus:ring-[#f53003] dark:border-[#3E3E3A]"
                            />
                            Subscribe to Newsletter / Marketing E-mails
                        </label>
                    </div>
                </section>

                <!-- Guest History -->
                <section
                    class="mb-4 border-t border-[#e3e3e0] pt-4 dark:border-[#3E3E3A]"
                >
                    <button
                        type="button"
                        class="flex w-full items-center justify-between text-sm font-semibold tracking-wide text-[#1b1b18] uppercase dark:text-[#EDEDEC]"
                        @click="guestHistoryOpen = !guestHistoryOpen"
                    >
                        Guest History
                        <span>{{ guestHistoryOpen ? '−' : '+' }}</span>
                    </button>
                    <p
                        v-if="guestHistoryOpen"
                        class="mt-3 text-sm text-[#706f6c] dark:text-[#A1A09A]"
                    >
                        No stay history available yet.
                    </p>
                </section>

                <!-- Other Details -->
                <section
                    class="mb-4 border-t border-[#e3e3e0] pt-4 dark:border-[#3E3E3A]"
                >
                    <button
                        type="button"
                        class="flex w-full items-center justify-between text-sm font-semibold tracking-wide text-[#1b1b18] uppercase dark:text-[#EDEDEC]"
                        @click="otherDetailsOpen = !otherDetailsOpen"
                    >
                        Other Details
                        <span>{{ otherDetailsOpen ? '−' : '+' }}</span>
                    </button>

                    <div
                        v-if="otherDetailsOpen"
                        class="mt-3 grid grid-cols-1 gap-4 sm:grid-cols-2"
                    >
                        <label
                            class="flex flex-col gap-1 text-sm text-[#706f6c] dark:text-[#A1A09A]"
                        >
                            Anniversary
                            <input
                                v-model="form.anniversary"
                                type="date"
                                class="block rounded-md border border-[#e3e3e0] bg-white px-3 py-2 text-sm text-[#1b1b18] focus:border-[#f53003] focus:ring-1 focus:ring-[#f53003] focus:outline-none dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]"
                            />
                        </label>
                        <label
                            class="flex flex-col gap-1 text-sm text-[#706f6c] dark:text-[#A1A09A]"
                        >
                            Frequent Flyer
                            <input
                                v-model="form.frequent_flyer"
                                type="text"
                                class="block rounded-md border border-[#e3e3e0] bg-white px-3 py-2 text-sm text-[#1b1b18] focus:border-[#f53003] focus:ring-1 focus:ring-[#f53003] focus:outline-none dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]"
                            />
                        </label>
                        <label
                            class="flex flex-col gap-1 text-sm text-[#706f6c] dark:text-[#A1A09A]"
                        >
                            Vehicle Number
                            <input
                                v-model="form.vehicle_number"
                                type="text"
                                class="block rounded-md border border-[#e3e3e0] bg-white px-3 py-2 text-sm text-[#1b1b18] focus:border-[#f53003] focus:ring-1 focus:ring-[#f53003] focus:outline-none dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]"
                            />
                        </label>
                        <label
                            class="flex flex-col gap-1 text-sm text-[#706f6c] dark:text-[#A1A09A]"
                        >
                            Place of Birth
                            <input
                                v-model="form.place_of_birth"
                                type="text"
                                class="block rounded-md border border-[#e3e3e0] bg-white px-3 py-2 text-sm text-[#1b1b18] focus:border-[#f53003] focus:ring-1 focus:ring-[#f53003] focus:outline-none dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]"
                            />
                        </label>
                        <label
                            class="col-span-full flex flex-col gap-1 text-sm text-[#706f6c] dark:text-[#A1A09A]"
                        >
                            Present / Business Address
                            <input
                                v-model="form.present_business_address"
                                type="text"
                                class="block rounded-md border border-[#e3e3e0] bg-white px-3 py-2 text-sm text-[#1b1b18] focus:border-[#f53003] focus:ring-1 focus:ring-[#f53003] focus:outline-none dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]"
                            />
                        </label>

                        <div class="col-span-full flex flex-col gap-2">
                            <button
                                type="button"
                                class="self-start text-sm font-medium text-[#f53003] dark:text-[#FF4433]"
                                @click="addressOpen = !addressOpen"
                            >
                                {{ addressOpen ? '− Address' : '+ Address' }}
                            </button>
                            <AddressCascadeFields
                                v-if="addressOpen"
                                v-model="form.address"
                                :countries="countries"
                                id-prefix="guest-address"
                            />
                        </div>

                        <div class="col-span-full">
                            <button
                                type="button"
                                class="self-start text-sm font-medium text-[#f53003] dark:text-[#FF4433]"
                                @click="openCompanyModal"
                            >
                                + Add Official Information
                            </button>
                            <p
                                v-if="form.company.name"
                                class="mt-1 text-xs text-[#706f6c] dark:text-[#A1A09A]"
                            >
                                {{ form.company.name }}
                                <span
                                    v-if="form.company_account"
                                    class="ml-1 rounded bg-emerald-100 px-1.5 py-0.5 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-300"
                                    data-test="guest-company-account"
                                    >City Ledger:
                                    {{ form.company_account.name }}</span
                                >
                                <span
                                    v-else-if="form.create_city_ledger_account"
                                    class="ml-1 rounded bg-amber-100 px-1.5 py-0.5 text-amber-800 dark:bg-amber-900/40 dark:text-amber-300"
                                    >City Ledger account opens on save</span
                                >
                            </p>
                            <p
                                v-if="
                                    errors.create_city_ledger_account ||
                                    errors['company.name']
                                "
                                class="mt-1 text-xs text-[#f53003]"
                            >
                                {{
                                    errors.create_city_ledger_account ||
                                    errors['company.name']
                                }}
                            </p>
                        </div>

                        <div
                            class="col-span-full border-t border-[#e3e3e0] pt-4 dark:border-[#3E3E3A]"
                        >
                            <button
                                type="button"
                                class="flex w-full items-center justify-between text-sm font-semibold tracking-wide text-[#1b1b18] uppercase dark:text-[#EDEDEC]"
                                @click="internationalOpen = !internationalOpen"
                            >
                                International Guest Details
                                <span>{{ internationalOpen ? '−' : '+' }}</span>
                            </button>

                            <div
                                v-if="internationalOpen"
                                class="mt-3 grid grid-cols-1 gap-4 sm:grid-cols-2"
                            >
                                <label
                                    class="flex flex-col gap-1 text-sm text-[#706f6c] dark:text-[#A1A09A]"
                                >
                                    Passport Number
                                    <input
                                        v-model="
                                            form.international_details
                                                .passport_number
                                        "
                                        type="text"
                                        class="block rounded-md border border-[#e3e3e0] bg-white px-3 py-2 text-sm text-[#1b1b18] focus:border-[#f53003] focus:ring-1 focus:ring-[#f53003] focus:outline-none dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]"
                                    />
                                </label>
                                <label
                                    class="flex flex-col gap-1 text-sm text-[#706f6c] dark:text-[#A1A09A]"
                                >
                                    Date of Issue of Passport
                                    <input
                                        v-model="
                                            form.international_details
                                                .passport_issue_date
                                        "
                                        type="date"
                                        class="block rounded-md border border-[#e3e3e0] bg-white px-3 py-2 text-sm text-[#1b1b18] focus:border-[#f53003] focus:ring-1 focus:ring-[#f53003] focus:outline-none dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]"
                                    />
                                </label>
                                <label
                                    class="flex flex-col gap-1 text-sm text-[#706f6c] dark:text-[#A1A09A]"
                                >
                                    Place of Issue of Passport
                                    <input
                                        v-model="
                                            form.international_details
                                                .passport_issue_place
                                        "
                                        type="text"
                                        class="block rounded-md border border-[#e3e3e0] bg-white px-3 py-2 text-sm text-[#1b1b18] focus:border-[#f53003] focus:ring-1 focus:ring-[#f53003] focus:outline-none dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]"
                                    />
                                </label>
                                <label
                                    class="flex flex-col gap-1 text-sm text-[#706f6c] dark:text-[#A1A09A]"
                                >
                                    Passport Validity
                                    <input
                                        v-model="
                                            form.international_details
                                                .passport_validity
                                        "
                                        type="date"
                                        class="block rounded-md border border-[#e3e3e0] bg-white px-3 py-2 text-sm text-[#1b1b18] focus:border-[#f53003] focus:ring-1 focus:ring-[#f53003] focus:outline-none dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]"
                                    />
                                </label>

                                <label
                                    class="flex flex-col gap-1 text-sm text-[#706f6c] dark:text-[#A1A09A]"
                                >
                                    Place of Issue of Visa
                                    <input
                                        v-model="
                                            form.international_details
                                                .visa_issue_place
                                        "
                                        type="text"
                                        class="block rounded-md border border-[#e3e3e0] bg-white px-3 py-2 text-sm text-[#1b1b18] focus:border-[#f53003] focus:ring-1 focus:ring-[#f53003] focus:outline-none dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]"
                                    />
                                </label>
                                <label
                                    class="flex flex-col gap-1 text-sm text-[#706f6c] dark:text-[#A1A09A]"
                                >
                                    Visa Number
                                    <input
                                        v-model="
                                            form.international_details
                                                .visa_number
                                        "
                                        type="text"
                                        class="block rounded-md border border-[#e3e3e0] bg-white px-3 py-2 text-sm text-[#1b1b18] focus:border-[#f53003] focus:ring-1 focus:ring-[#f53003] focus:outline-none dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]"
                                    />
                                </label>
                                <label
                                    class="flex flex-col gap-1 text-sm text-[#706f6c] dark:text-[#A1A09A]"
                                >
                                    Type of Visa
                                    <select
                                        v-model="
                                            form.international_details.visa_type
                                        "
                                        class="block rounded-md border border-[#e3e3e0] bg-white px-3 py-2 text-sm text-[#1b1b18] focus:border-[#f53003] focus:ring-1 focus:ring-[#f53003] focus:outline-none dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]"
                                    >
                                        <option value="">--Select--</option>
                                        <option
                                            v-for="option in visaTypeOptions"
                                            :key="option"
                                            :value="option"
                                        >
                                            {{ option }}
                                        </option>
                                    </select>
                                </label>
                                <label
                                    class="flex flex-col gap-1 text-sm text-[#706f6c] dark:text-[#A1A09A]"
                                >
                                    Date of Issue of Visa
                                    <input
                                        v-model="
                                            form.international_details
                                                .visa_issue_date
                                        "
                                        type="date"
                                        class="block rounded-md border border-[#e3e3e0] bg-white px-3 py-2 text-sm text-[#1b1b18] focus:border-[#f53003] focus:ring-1 focus:ring-[#f53003] focus:outline-none dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]"
                                    />
                                </label>
                                <label
                                    class="flex flex-col gap-1 text-sm text-[#706f6c] dark:text-[#A1A09A]"
                                >
                                    Visa Valid Till
                                    <input
                                        v-model="
                                            form.international_details
                                                .visa_valid_till
                                        "
                                        type="date"
                                        class="block rounded-md border border-[#e3e3e0] bg-white px-3 py-2 text-sm text-[#1b1b18] focus:border-[#f53003] focus:ring-1 focus:ring-[#f53003] focus:outline-none dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]"
                                    />
                                </label>
                                <label
                                    class="flex flex-col gap-1 text-sm text-[#706f6c] dark:text-[#A1A09A]"
                                >
                                    Employed In India
                                    <select
                                        v-model="
                                            form.international_details
                                                .employed_in_india
                                        "
                                        class="block rounded-md border border-[#e3e3e0] bg-white px-3 py-2 text-sm text-[#1b1b18] focus:border-[#f53003] focus:ring-1 focus:ring-[#f53003] focus:outline-none dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]"
                                    >
                                        <option value="">--Select--</option>
                                        <option
                                            v-for="option in employedInIndiaOptions"
                                            :key="option"
                                            :value="option"
                                        >
                                            {{ option }}
                                        </option>
                                    </select>
                                </label>
                                <label
                                    class="flex flex-col gap-1 text-sm text-[#706f6c] dark:text-[#A1A09A]"
                                >
                                    Next Destination
                                    <input
                                        v-model="
                                            form.international_details
                                                .next_destination
                                        "
                                        type="text"
                                        class="block rounded-md border border-[#e3e3e0] bg-white px-3 py-2 text-sm text-[#1b1b18] focus:border-[#f53003] focus:ring-1 focus:ring-[#f53003] focus:outline-none dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]"
                                    />
                                </label>
                                <label
                                    class="flex flex-col gap-1 text-sm text-[#706f6c] dark:text-[#A1A09A]"
                                >
                                    Phone Number In India
                                    <div class="flex gap-2">
                                        <input
                                            v-model="
                                                form.international_details
                                                    .phone_in_india_country_code
                                            "
                                            type="text"
                                            class="block w-16 rounded-md border border-[#e3e3e0] bg-white px-2 py-2 text-sm text-[#1b1b18] focus:border-[#f53003] focus:ring-1 focus:ring-[#f53003] focus:outline-none dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]"
                                        />
                                        <input
                                            v-model="
                                                form.international_details
                                                    .phone_in_india
                                            "
                                            type="text"
                                            class="block flex-1 rounded-md border border-[#e3e3e0] bg-white px-3 py-2 text-sm text-[#1b1b18] focus:border-[#f53003] focus:ring-1 focus:ring-[#f53003] focus:outline-none dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]"
                                        />
                                    </div>
                                </label>
                                <label
                                    class="flex flex-col gap-1 text-sm text-[#706f6c] dark:text-[#A1A09A]"
                                >
                                    Arrival From
                                    <input
                                        v-model="
                                            form.international_details
                                                .arrival_from
                                        "
                                        type="text"
                                        class="block rounded-md border border-[#e3e3e0] bg-white px-3 py-2 text-sm text-[#1b1b18] focus:border-[#f53003] focus:ring-1 focus:ring-[#f53003] focus:outline-none dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]"
                                    />
                                </label>
                                <label
                                    class="flex flex-col gap-1 text-sm text-[#706f6c] dark:text-[#A1A09A]"
                                >
                                    Arrival Date
                                    <input
                                        v-model="
                                            form.international_details
                                                .arrival_date
                                        "
                                        type="date"
                                        class="block rounded-md border border-[#e3e3e0] bg-white px-3 py-2 text-sm text-[#1b1b18] focus:border-[#f53003] focus:ring-1 focus:ring-[#f53003] focus:outline-none dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]"
                                    />
                                </label>

                                <div class="col-span-full">
                                    <p
                                        class="mb-2 text-sm font-medium text-[#1b1b18] dark:text-[#EDEDEC]"
                                    >
                                        Reference Address In India
                                    </p>
                                    <AddressCascadeFields
                                        v-model="
                                            form.international_details
                                                .reference_address
                                        "
                                        :countries="countries"
                                        id-prefix="guest-reference-address"
                                    />
                                </div>
                            </div>
                        </div>
                    </div>
                </section>
            </div>

            <div
                class="flex items-center justify-end gap-3 border-t border-[#e3e3e0] px-6 py-4 dark:border-[#3E3E3A]"
            >
                <button
                    type="button"
                    class="rounded-md border border-[#e3e3e0] px-4 py-2 text-sm font-medium text-[#1b1b18] dark:border-[#3E3E3A] dark:text-[#EDEDEC]"
                    @click="close"
                >
                    Cancel
                </button>
                <button
                    type="button"
                    :disabled="!canSave || processing"
                    class="rounded-md border border-black bg-[#f53003] px-4 py-2 text-sm font-semibold text-white hover:bg-[#d92a02] disabled:opacity-50"
                    @click="save"
                >
                    {{
                        processing
                            ? 'Saving...'
                            : guest
                              ? 'Update Guest'
                              : 'Add Guest'
                    }}
                </button>
            </div>
        </div>

        <!-- Nested Add/Update Company modal -->
        <div
            v-if="companyModalOpen"
            class="fixed inset-0 z-[60] flex items-center justify-center bg-black/40 p-4"
            @click.self="cancelCompanyModal"
        >
            <div
                class="w-full max-w-md rounded-lg border border-[#e3e3e0] bg-white p-6 dark:border-[#3E3E3A] dark:bg-[#161615]"
            >
                <div class="mb-4 flex items-center justify-between">
                    <h3
                        class="font-display text-base font-semibold text-[#1b1b18] dark:text-[#EDEDEC]"
                    >
                        Add/Update Company
                    </h3>
                    <button
                        type="button"
                        class="text-sm text-[#706f6c] hover:text-[#1b1b18] dark:text-[#A1A09A]"
                        @click="cancelCompanyModal"
                    >
                        ✕
                    </button>
                </div>

                <div class="flex flex-col gap-3">
                    <!-- The company's City Ledger account: who pays when a booking is billed to the guest's company. -->
                    <div
                        class="rounded-md border border-[#e3e3e0] p-3 dark:border-[#3E3E3A]"
                    >
                        <p
                            class="mb-2 text-xs font-semibold tracking-wide text-[#706f6c] uppercase dark:text-[#A1A09A]"
                        >
                            City Ledger account
                        </p>
                        <div
                            v-if="companyAccount"
                            class="flex items-start justify-between gap-2 text-sm"
                            data-test="company-account-linked"
                        >
                            <div>
                                <p
                                    class="font-medium text-[#1b1b18] dark:text-[#EDEDEC]"
                                >
                                    {{ companyAccount.name
                                    }}<span
                                        v-if="companyAccount.code"
                                        class="text-[#706f6c] dark:text-[#A1A09A]"
                                    >
                                        · {{ companyAccount.code }}</span
                                    >
                                </p>
                                <p
                                    class="text-xs text-[#706f6c] dark:text-[#A1A09A]"
                                >
                                    Pays in
                                    {{ companyAccount.payment_terms_days }}
                                    days<template
                                        v-if="
                                            companyAccount.available_credit !=
                                            null
                                        "
                                    >
                                        · credit left
                                        {{
                                            Number(
                                                companyAccount.available_credit,
                                            ).toFixed(2)
                                        }}</template
                                    >
                                </p>
                            </div>
                            <button
                                type="button"
                                class="text-xs font-medium text-[#f53003] dark:text-[#FF4433]"
                                @click="companyAccount = null"
                            >
                                Unlink
                            </button>
                        </div>
                        <template v-else>
                            <input
                                v-model="companySearch"
                                type="search"
                                placeholder="Search accounts by name, GSTIN or code"
                                class="block w-full rounded-md border border-[#e3e3e0] bg-white px-3 py-2 text-sm text-[#1b1b18] focus:border-[#f53003] focus:ring-1 focus:ring-[#f53003] focus:outline-none dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]"
                                data-test="company-account-search"
                            />
                            <ul
                                v-if="companyResults.length"
                                class="mt-1 max-h-40 overflow-y-auto rounded-md border border-[#e3e3e0] dark:border-[#3E3E3A]"
                            >
                                <li
                                    v-for="account in companyResults"
                                    :key="account.id"
                                >
                                    <button
                                        type="button"
                                        class="block w-full px-3 py-1.5 text-left text-sm hover:bg-[#f5f5f4] dark:hover:bg-[#1D1D1B]"
                                        @click="pickCompanyAccount(account)"
                                    >
                                        <span
                                            class="text-[#1b1b18] dark:text-[#EDEDEC]"
                                            >{{ account.name }}</span
                                        >
                                        <span
                                            v-if="account.tax_number"
                                            class="ml-1 text-xs text-[#706f6c] dark:text-[#A1A09A]"
                                            >{{ account.tax_number }}</span
                                        >
                                    </button>
                                </li>
                            </ul>
                            <p
                                v-else-if="companySearch.trim() !== ''"
                                class="mt-1 text-xs text-[#706f6c] dark:text-[#A1A09A]"
                            >
                                No account found.
                            </p>
                            <label
                                v-if="canManageCityLedger"
                                class="mt-2 flex items-center gap-2 text-sm text-[#1b1b18] dark:text-[#EDEDEC]"
                            >
                                <input
                                    v-model="createCompanyAccount"
                                    type="checkbox"
                                    class="h-4 w-4 rounded border-[#e3e3e0] text-[#f53003] focus:ring-[#f53003] dark:border-[#3E3E3A]"
                                    data-test="create-company-account"
                                />
                                Open a City Ledger account for this company
                            </label>
                        </template>
                    </div>
                    <label
                        class="flex flex-col gap-1 text-sm text-[#706f6c] dark:text-[#A1A09A]"
                    >
                        Search by GSTIN
                        <input
                            v-model="companyForm.gstin"
                            type="text"
                            class="block rounded-md border border-[#e3e3e0] bg-white px-3 py-2 text-sm text-[#1b1b18] focus:border-[#f53003] focus:ring-1 focus:ring-[#f53003] focus:outline-none dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]"
                        />
                    </label>
                    <label
                        class="flex flex-col gap-1 text-sm text-[#706f6c] dark:text-[#A1A09A]"
                    >
                        Company Name
                        <input
                            v-model="companyForm.name"
                            type="text"
                            class="block rounded-md border border-[#e3e3e0] bg-white px-3 py-2 text-sm text-[#1b1b18] focus:border-[#f53003] focus:ring-1 focus:ring-[#f53003] focus:outline-none dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]"
                        />
                    </label>
                    <label
                        class="flex flex-col gap-1 text-sm text-[#706f6c] dark:text-[#A1A09A]"
                    >
                        Company E-mail
                        <input
                            v-model="companyForm.email"
                            type="email"
                            class="block rounded-md border border-[#e3e3e0] bg-white px-3 py-2 text-sm text-[#1b1b18] focus:border-[#f53003] focus:ring-1 focus:ring-[#f53003] focus:outline-none dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]"
                        />
                    </label>
                    <label
                        class="flex flex-col gap-1 text-sm text-[#706f6c] dark:text-[#A1A09A]"
                    >
                        Integration ID
                        <input
                            v-model="companyForm.integration_id"
                            type="text"
                            class="block rounded-md border border-[#e3e3e0] bg-white px-3 py-2 text-sm text-[#1b1b18] focus:border-[#f53003] focus:ring-1 focus:ring-[#f53003] focus:outline-none dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]"
                        />
                    </label>
                    <label
                        class="flex flex-col gap-1 text-sm text-[#706f6c] dark:text-[#A1A09A]"
                    >
                        Company Address
                        <textarea
                            v-model="companyForm.address"
                            rows="2"
                            class="block rounded-md border border-[#e3e3e0] bg-white px-3 py-2 text-sm text-[#1b1b18] focus:border-[#f53003] focus:ring-1 focus:ring-[#f53003] focus:outline-none dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]"
                        ></textarea>
                    </label>
                </div>

                <div class="mt-6 flex justify-end gap-3">
                    <button
                        type="button"
                        class="rounded-md border border-[#e3e3e0] px-4 py-2 text-sm font-medium text-[#1b1b18] dark:border-[#3E3E3A] dark:text-[#EDEDEC]"
                        @click="cancelCompanyModal"
                    >
                        Cancel
                    </button>
                    <button
                        type="button"
                        class="rounded-md border border-black bg-[#f53003] px-4 py-2 text-sm font-semibold text-white hover:bg-[#d92a02]"
                        @click="addCompanyToGuest"
                    >
                        ADD TO GUEST
                    </button>
                </div>
            </div>
        </div>
    </div>
</template>
