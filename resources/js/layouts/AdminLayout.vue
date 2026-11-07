<script setup lang="ts">
import admin from '@/routes/admin';
import { dashboard } from '@/routes';
import AuthenticatedSessionController from '@/actions/Laravel/Fortify/Http/Controllers/AuthenticatedSessionController';
import SearchableSelect from '@/components/SearchableSelect.vue';
import { calendarDate } from '@/lib/usePropertyTime';
import { useSelectedProperty } from '@/lib/useSelectedProperty';
import { useSidebarCollapsed } from '@/lib/useSidebarCollapsed';
import { useCan } from '@/lib/useCan';
import { useFeature } from '@/lib/useFeature';
import profile from '@/routes/profile';
import { Link, router, usePage } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';

const page = usePage();

// ── Username menu (Profile settings / Log out) ──
const userMenuOpen = ref(false);
const userMenuRef = ref<HTMLElement | null>(null);

function closeUserMenuOnOutsideClick(event: MouseEvent): void {
    if (
        userMenuOpen.value &&
        userMenuRef.value &&
        !userMenuRef.value.contains(event.target as Node)
    ) {
        userMenuOpen.value = false;
    }
}

function closeUserMenuOnEscape(event: KeyboardEvent): void {
    if (event.key === 'Escape') {
        userMenuOpen.value = false;
    }
}

onMounted(() => {
    document.addEventListener('click', closeUserMenuOnOutsideClick);
    document.addEventListener('keydown', closeUserMenuOnEscape);
});
onBeforeUnmount(() => {
    document.removeEventListener('click', closeUserMenuOnOutsideClick);
    document.removeEventListener('keydown', closeUserMenuOnEscape);
});
const sidebarOpen = ref(false);
const { selectedPropertyId } = useSelectedProperty();
const { collapsed, forceCollapsed } = useSidebarCollapsed();
const effectiveCollapsed = computed(
    () => collapsed.value || forceCollapsed.value,
);

function toggleSidebar(): void {
    if (effectiveCollapsed.value) {
        collapsed.value = false;
        forceCollapsed.value = false;
    } else {
        collapsed.value = true;
    }
}
const properties = computed(() => page.props.adminProperties);
const propertyOptions = computed(() =>
    properties.value.map((property) => ({
        id: property.id,
        label: `${property.name} (${property.abbreviation})`,
    })),
);

const propertyScopedEditRoutes: Record<
    string,
    (property: { id: number }) => { url: string }
> = {
    'admin/booking-settings/Edit': admin.bookingSettings.edit,
    'admin/rate-settings/Edit': admin.rateSettings.edit,
    'admin/rate-restrictions/Edit': admin.rateRestrictions.edit,
    'admin/channel-manager/Edit': admin.channelManager.edit,
    'admin/tax-settings/Edit': admin.taxSettings.edit,
    'admin/invoice-settings/Edit': admin.invoiceSettings.edit,
    'admin/exchange-rates/Edit': admin.exchangeRates.edit,
    'admin/legal-settings/Edit': admin.legalSettings.edit,
    'admin/general-ledger/Show': admin.generalLedger.show,
    'admin/booking-engine-settings/Edit': admin.bookingEngineSettings.edit,
    'admin/night-audit-settings/Edit': admin.nightAuditSettings.edit,
    'admin/night-audits/Edit': admin.nightAudits.edit,
    'admin/outlets/Edit': admin.outlets.edit,
    'admin/pos/Show': admin.pos.show,
    'admin/front-desk/Show': admin.frontDesk.show,
    'admin/cashier-shifts/Show': admin.cashierShifts.show,
    'admin/pos/Report': admin.pos.report,
};

const queryParamPropertyRoutes: Record<string, { url: string }> = {
    'admin/Dashboard': admin.dashboard(),
    'admin/databases/Guests': admin.databases.guests.index(),
    'admin/databases/Bookings': admin.databases.bookings.index(),
    'admin/databases/Payments': admin.databases.payments.index(),
    'admin/databases/Invoices': admin.databases.invoices.index(),
    'admin/databases/Folios': admin.databases.folios.index(),
    'admin/databases/Enquiries': admin.databases.enquiries.index(),
    'admin/databases/Waitlist': admin.databases.waitlist.index(),
    'admin/databases/CityLedger': admin.databases.cityLedger.index(),
};

/** Query for property-scoped Database pages, so they open on the property picked in the header. */
function propertyQuery(): { query?: { property: number } } {
    return selectedPropertyId.value === ''
        ? {}
        : { query: { property: Number(selectedPropertyId.value) } };
}

/**
 * Picking another property in the header opens the current page for it. A page opened without
 * ?property= already shows the picked property: the server resolves it (see useSelectedProperty).
 */
watch(selectedPropertyId, (value) => {
    if (value === '') {
        return;
    }

    const [path, query = ''] = page.url.split('?');
    const queryParamRoute = queryParamPropertyRoutes[page.component];

    if (queryParamRoute) {
        if (new URLSearchParams(query).get('property') !== String(value)) {
            router.get(
                queryParamRoute.url,
                { property: value },
                { preserveState: true, preserveScroll: true },
            );
        }

        return;
    }

    const edit = propertyScopedEditRoutes[page.component];

    if (edit) {
        const target = edit({ id: value }).url;

        if (target.split('?')[0] !== path) {
            router.get(target, {}, { preserveScroll: true });
        }
    }
});

/** Pre-rollover banners: the selected hotel's (or every hotel's when none is picked), with minutes left. */
const noticeClock = ref(Date.now());
let noticeTimer: ReturnType<typeof setInterval> | undefined;
onMounted(
    () =>
        (noticeTimer = setInterval(
            () => (noticeClock.value = Date.now()),
            30000,
        )),
);
onBeforeUnmount(() => clearInterval(noticeTimer));

const nightAuditNotices = computed(() =>
    (page.props.nightAuditNotices ?? [])
        .filter(
            (notice) =>
                selectedPropertyId.value === '' ||
                notice.property_id === Number(selectedPropertyId.value),
        )
        .map((notice) => ({
            ...notice,
            minutesLeft: Math.max(
                0,
                Math.ceil(
                    (Date.parse(notice.rollover_at) - noticeClock.value) /
                        60000,
                ),
            ),
        })),
);

const settingsGearIcon =
    'M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065zM15 12a3 3 0 11-6 0 3 3 0 016 0z';

/** Roles and Permissions: menu entries the user may not open are left out; so are those the plan doesn't include. */
const can = useCan();
const hasFeature = useFeature();

function propertyScopedHref<T>(
    indexHref: T,
    edit: (property: { id: number }) => T,
): T {
    return selectedPropertyId.value === ''
        ? indexHref
        : edit({ id: selectedPropertyId.value });
}

const settingsMenu = computed(() =>
    [
        {
            name: 'Booking',
            href: propertyScopedHref(
                admin.bookingSettings.index(),
                admin.bookingSettings.edit,
            ),
            icon: 'M8 7V3m8 4V3M4 11h16M5 5h14a1 1 0 011 1v14a1 1 0 01-1 1H5a1 1 0 01-1-1V6a1 1 0 011-1z',
            permission: 'manage_property.booking_settings',
        },
        {
            name: 'Rate',
            href: propertyScopedHref(
                admin.rateSettings.index(),
                admin.rateSettings.edit,
            ),
            icon: 'M7 7h.01M7 3h5l9 9-9 9-9-9V3z',
            permission: 'manage_property.rate_setup',
        },
        {
            name: 'Channel Manager',
            href: propertyScopedHref(
                admin.channelManager.index(),
                admin.channelManager.edit,
            ),
            icon: 'M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4',
            permission: 'manage_property.channel_manager',
            feature: 'channel_manager',
        },
        {
            name: 'Tax settings',
            href: propertyScopedHref(
                admin.taxSettings.index(),
                admin.taxSettings.edit,
            ),
            icon: 'M9 7h6m-6 4h6m-6 4h4M5 3h14a1 1 0 011 1v16l-4-3-3 3-3-3-3 3-3-3V4a1 1 0 011-1z',
            permission: 'manage_property.tax_setup',
        },
        {
            name: 'Invoice settings',
            href: propertyScopedHref(
                admin.invoiceSettings.index(),
                admin.invoiceSettings.edit,
            ),
            icon: 'M12 8c-1.66 0-3 .9-3 2s1.34 2 3 2 3 .9 3 2-1.34 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V6m0 10v2m0-12a9 9 0 100 18 9 9 0 000-18z',
            permission: 'manage_property.accounts_finance_settings',
        },
        {
            name: 'Exchange rates',
            href: propertyScopedHref(
                admin.exchangeRates.index(),
                admin.exchangeRates.edit,
            ),
            icon: 'M7 16V4m0 0L3 8m4-4l4 4m6 0v12m0 0l4-4m-4 4l-4-4',
            permission: 'manage_property.accounts_finance_settings',
            feature: 'multi_currency',
        },
        {
            name: 'Booking Engine',
            href: propertyScopedHref(
                admin.bookingEngineSettings.index(),
                admin.bookingEngineSettings.edit,
            ),
            icon: 'M3 12h18M12 3a15 15 0 010 18M12 3a15 15 0 000 18m-9-9a9 9 0 0118 0',
            permission: 'manage_property.booking_engine_settings',
            feature: 'booking_engine',
        },
        {
            name: 'Outlets',
            href: propertyScopedHref(admin.outlets.index(), admin.outlets.edit),
            icon: 'M3 3h18v4H3zM5 7v14h14V7M9 11h6',
            permission: 'manage_property.outlet_settings',
            feature: 'pos',
        },
        {
            name: 'Agent settings',
            href: propertyScopedHref(
                admin.agentSettings.index(),
                admin.agentSettings.edit,
            ),
            icon: 'M17 20h5v-2a4 4 0 00-3-3.87M9 20H4v-2a4 4 0 013-3.87m5-3.13a4 4 0 100-8 4 4 0 000 8zm6 0a3 3 0 100-6 3 3 0 000 6z',
            permission: 'manage_property.rate_setup',
        },
        {
            name: 'Night audit settings',
            href: propertyScopedHref(
                admin.nightAuditSettings.index(),
                admin.nightAuditSettings.edit,
            ),
            icon: 'M20.354 15.354A9 9 0 018.646 3.646 9.003 9.003 0 0012 21a9.003 9.003 0 008.354-5.646z',
            permission: 'manage_property.rollover_settings',
            feature: 'night_audit',
        },
        {
            name: 'Privacy & Terms',
            href: propertyScopedHref(
                admin.legalSettings.index(),
                admin.legalSettings.edit,
            ),
            icon: 'M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z',
            permission: 'manage_property.legal_settings',
        },
    ].filter(
        (item) =>
            can(item.permission) &&
            hasFeature((item as { feature?: string }).feature),
    ),
);

const settingsMenuOpen = ref(
    settingsMenu.value.some((item) => page.url.startsWith(item.href.url)),
);

const reportsIcon =
    'M9 17v-2m3 2v-4m3 4v-6M5 3h10l4 4v13a1 1 0 01-1 1H5a1 1 0 01-1-1V4a1 1 0 011-1z';

const reportsHref = computed(() =>
    propertyScopedHref(admin.reports.index(), admin.reports.edit),
);

const posIcon =
    'M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z';

const posHref = computed(() =>
    propertyScopedHref(admin.pos.index(), admin.pos.show),
);

const frontDeskIcon =
    'M17 20h5v-2a3 3 0 00-5.36-1.86M17 20H7m10 0v-2c0-.65-.13-1.28-.36-1.86M7 20H2v-2a3 3 0 015.36-1.86M7 20v-2c0-.65.13-1.28.36-1.86m0 0a5 5 0 019.28 0M15 7a3 3 0 11-6 0 3 3 0 016 0z';

const frontDeskHref = computed(() =>
    propertyScopedHref(admin.frontDesk.index(), admin.frontDesk.show),
);

const cashierShiftIcon =
    'M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z';

const cashierShiftHref = computed(() =>
    propertyScopedHref(admin.cashierShifts.index(), admin.cashierShifts.show),
);

const databasesIcon =
    'M4 7c0-1.66 3.58-3 8-3s8 1.34 8 3-3.58 3-8 3-8-1.34-8-3zm0 0v10c0 1.66 3.58 3 8 3s8-1.34 8-3V7M4 12c0 1.66 3.58 3 8 3s8-1.34 8-3';

const databasesMenu = computed(() =>
    [
        {
            name: 'Bookings',
            permission: 'database.bookings.view',
            href: admin.databases.bookings.index(propertyQuery()),
            icon: 'M8 7V3m8 4V3M4 11h16M5 5h14a1 1 0 011 1v14a1 1 0 01-1 1H5a1 1 0 01-1-1V6a1 1 0 011-1z',
        },
        {
            name: 'Payments',
            href: admin.databases.payments.index(propertyQuery()),
            icon: 'M12 8c-1.66 0-3 .9-3 2s1.34 2 3 2 3 .9 3 2-1.34 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V6m0 10v2m0-12a9 9 0 100 18 9 9 0 000-18z',
            permission: 'database.payments.view_download',
        },
        {
            name: 'Night audits',
            href: propertyScopedHref(
                admin.nightAudits.index(),
                admin.nightAudits.edit,
            ),
            icon: 'M20.354 15.354A9 9 0 018.646 3.646 9.003 9.003 0 0012 21a9.003 9.003 0 008.354-5.646z',
            permission: 'front_office.rollover',
            feature: 'night_audit',
        },
        {
            name: 'Folios',
            href: admin.databases.folios.index(propertyQuery()),
            icon: 'M3 7a2 2 0 012-2h4l2 2h8a2 2 0 012 2v8a2 2 0 01-2 2H5a2 2 0 01-2-2V7zm5 6h8m-8 3h5',
            permission: 'database.invoices.view_download',
        },
        {
            name: 'Invoices',
            href: admin.databases.invoices.index(propertyQuery()),
            icon: 'M9 7h6m-6 4h6m-6 4h4M5 3h14a1 1 0 011 1v16l-4-3-3 3-3-3-3 3-3-3V4a1 1 0 011-1z',
            permission: 'database.invoices.view_download',
        },
        {
            name: 'City Ledger',
            href: admin.databases.cityLedger.index(propertyQuery()),
            icon: 'M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z',
            permission: 'database.city_ledger.view',
            feature: 'city_ledger',
        },
        {
            name: 'General Ledger',
            href: propertyScopedHref(
                admin.generalLedger.index(),
                admin.generalLedger.show,
            ),
            icon: 'M9 17v-6m4 6V7m4 10v-3M5 21h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v14a2 2 0 002 2z',
            permission: [
                'database.general_ledger.view',
                'manage_property.accounts_finance_settings',
            ],
            feature: 'general_ledger',
        },
        {
            name: 'Enquiries',
            href: admin.databases.enquiries.index(propertyQuery()),
            icon: 'M8 10h.01M12 10h.01M16 10h.01M9 16H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-4l-4 4v-4z',
            permission: 'database.bookings.view',
        },
        {
            name: 'Waitlist',
            href: admin.databases.waitlist.index(propertyQuery()),
            icon: 'M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z',
            permission: 'database.waitlist.view',
        },
        {
            name: 'Agents',
            href: admin.databases.agents.index(propertyQuery()),
            icon: 'M17 20h5v-2a4 4 0 00-3-3.87M9 20H4v-2a4 4 0 013-3.87m5-3.13a4 4 0 100-8 4 4 0 000 8zm6 0a3 3 0 100-6 3 3 0 000 6z',
            permission: 'database.agents.view',
        },
        {
            name: 'Guests',
            permission: 'database.guests.view',
            href: admin.databases.guests.index(propertyQuery()),
            icon: 'M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14c-4.42 0-8 1.79-8 4v2h16v-2c0-2.21-3.58-4-8-4z',
        },
    ].filter(
        (item) =>
            can(item.permission) &&
            hasFeature((item as { feature?: string }).feature),
    ),
);

const databasesMenuOpen = ref(
    databasesMenu.value.some((item) => page.url.startsWith(item.href.url)),
);

const manageAccountIcon =
    'M17 20h5v-2a4 4 0 00-3-3.87M9 20H4v-2a4 4 0 013-3.87m5-3.13a4 4 0 100-8 4 4 0 000 8zm6 0a3 3 0 100-6 3 3 0 000 6z';

const manageAccountMenu = [
    {
        name: 'Users',
        href: admin.users.index(),
        icon: 'M17 20h5v-2a4 4 0 00-3-3.87M9 20H4v-2a4 4 0 013-3.87m5-3.13a4 4 0 100-8 4 4 0 000 8zm6 0a3 3 0 100-6 3 3 0 000 6z',
        permission: 'manage_account.users',
    },
    {
        name: 'Roles and Permissions',
        href: admin.roles.index(),
        icon: 'M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z',
        permission: 'manage_account.roles_permissions',
    },
    {
        name: 'Property groups',
        href: admin.propertyGroups.index(),
        icon: 'M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2M19 21H5m0 0H3M9 7h1m4 0h1M9 11h1m4 0h1M9 15h1m4 0h1',
        permission: 'manage_property.property_settings',
    },
    {
        name: 'Properties',
        href: admin.properties.index(),
        icon: 'M3 21h18M5 21V7l7-4 7 4v14M9 9h1m4 0h1m-6 4h1m4 0h1m-6 4h1m4 0h1',
        permission: 'manage_property.property_settings',
    },
    {
        name: 'Application settings',
        permission: [
            'manage_account.smart_chart_settings',
            'manage_property.notification_settings',
        ],
        href: admin.applicationSettings.edit(),
        icon: 'M10.3 4.3c.4-1.7 2.9-1.7 3.4 0a1.7 1.7 0 002.6 1.1c1.5-.9 3.3.8 2.4 2.4a1.7 1.7 0 001 2.5c1.8.4 1.8 2.9 0 3.4a1.7 1.7 0 00-1 2.6c.9 1.5-.9 3.3-2.4 2.4a1.7 1.7 0 00-2.6 1c-.4 1.8-2.9 1.8-3.4 0a1.7 1.7 0 00-2.5-1c-1.6.9-3.3-.9-2.4-2.4a1.7 1.7 0 00-1.1-2.6c-1.7-.4-1.7-2.9 0-3.4a1.7 1.7 0 001.1-2.5c-.9-1.6.8-3.3 2.4-2.4 1 .6 2.3.1 2.5-1.1zM15 12a3 3 0 11-6 0 3 3 0 016 0z',
    },
    {
        name: 'Data & privacy',
        permission: 'manage_account.data_privacy',
        href: admin.dataPrivacy.edit(),
        icon: 'M12 11c0-1.1.9-2 2-2s2 .9 2 2v1h-4v-1zm-2 1h8v5a1 1 0 01-1 1h-6a1 1 0 01-1-1v-5zM9 21H6a2 2 0 01-2-2V5a2 2 0 012-2h7l5 5v1',
    },
    // Installed packages list their pages here; the server only sends those the user may open.
    ...Object.values(page.props.packageMenus ?? {})
        .flatMap((menu) => menu?.manage_account ?? [])
        .map((item) => ({
            name: item.name,
            href: { url: item.href, method: 'get' as const },
            icon: item.icon,
            permission: null,
        })),
].filter((item) => can(item.permission));

/** Installed packages' top-level pages; the server only sends those the user may open. */
const packageMainMenu = computed(() =>
    Object.values(page.props.packageMenus ?? {}).flatMap(
        (menu) => menu?.main ?? [],
    ),
);

/** The entry whose path is the longest prefix of the page's (so /housekeeping/my-tasks isn't also the board). */
const activePackageHref = computed(() => {
    const path = page.url.split('?')[0];

    return (
        packageMainMenu.value
            .map((item) => ({
                href: item.href,
                path: item.href.replace(/^https?:\/\/[^/]+/, '').split('?')[0],
            }))
            .filter((item) => path.startsWith(item.path))
            .sort((a, b) => b.path.length - a.path.length)[0]?.href ?? null
    );
});

const manageAccountMenuOpen = ref(
    manageAccountMenu.some((item) => page.url.startsWith(item.href.url)),
);

// ── Flash messages as floating toasts ──
type FlashKind = 'success' | 'warning' | 'error';
type FlashToast = { id: number; message: string; kind: FlashKind };

const flashToasts = ref<FlashToast[]>([]);
let nextFlashToastId = 1;

const flashToastClasses: Record<FlashKind, string> = {
    success: 'bg-[#16a34a]',
    warning: 'bg-amber-600',
    error: 'bg-red-600',
};

function dismissFlashToast(id: number): void {
    flashToasts.value = flashToasts.value.filter((toast) => toast.id !== id);
}

function showFlashToast(message: string, kind: FlashKind): void {
    const id = nextFlashToastId++;
    flashToasts.value = [...flashToasts.value, { id, message, kind }];
    setTimeout(() => dismissFlashToast(id), kind === 'success' ? 5000 : 8000);
}

// Each response brings a new flash object, so the same message twice still shows twice.
watch(
    () => page.props.flash,
    (flash) => {
        (['success', 'warning', 'error'] as const).forEach((kind) => {
            const message = flash?.[kind];

            if (message) {
                showFlashToast(message, kind);
            }
        });
    },
    { immediate: true },
);
</script>

<template>
    <div class="min-h-screen bg-[#FDFDFC] dark:bg-[#0a0a0a]">
        <div
            v-if="sidebarOpen"
            class="fixed inset-0 z-30 bg-black/30 lg:hidden"
            @click="sidebarOpen = false"
        />

        <aside
            class="fixed inset-y-0 left-0 z-40 flex w-64 -translate-x-full flex-col border-r border-[#e3e3e0] bg-white transition-[transform,width] lg:translate-x-0 dark:border-[#3E3E3A] dark:bg-[#161615]"
            :class="[
                { 'translate-x-0': sidebarOpen },
                effectiveCollapsed ? 'lg:w-16' : 'lg:w-64',
            ]"
        >
            <div
                class="flex h-16 shrink-0 items-center gap-2 border-b border-[#e3e3e0] px-3 dark:border-[#3E3E3A]"
            >
                <button
                    type="button"
                    title="Toggle menu"
                    class="hidden shrink-0 rounded-md p-2 text-[#1b1b18] hover:bg-[#f5f5f4] lg:flex dark:text-[#EDEDEC] dark:hover:bg-[#1D1D1B]"
                    @click="toggleSidebar"
                >
                    <svg
                        xmlns="http://www.w3.org/2000/svg"
                        class="h-5 w-5"
                        fill="none"
                        viewBox="0 0 24 24"
                        stroke="currentColor"
                    >
                        <path
                            stroke-linecap="round"
                            stroke-linejoin="round"
                            stroke-width="2"
                            d="M4 6h16M4 12h16M4 18h16"
                        />
                    </svg>
                </button>
                <Link
                    :href="admin.dashboard()"
                    class="font-display flex min-w-0 items-center gap-2 truncate text-lg font-bold text-[#1b1b18] dark:text-[#EDEDEC]"
                    :class="{ 'lg:hidden': effectiveCollapsed }"
                >
                    <img
                        src="/brand/hospitality-mark-64.webp"
                        srcset="
                            /brand/hospitality-mark-64.webp  1x,
                            /brand/hospitality-mark-128.webp 2x
                        "
                        width="30"
                        height="30"
                        alt=""
                        decoding="async"
                        class="block h-[30px] w-[30px] shrink-0"
                    />
                    <span class="truncate">Vasuttam PMS</span>
                </Link>
            </div>
            <nav
                class="flex min-h-0 flex-1 flex-col gap-1 overflow-y-auto overscroll-contain p-4"
                :class="{ 'lg:items-center lg:px-2': effectiveCollapsed }"
            >
                <Link
                    :href="admin.dashboard()"
                    title="Dashboard"
                    class="flex items-center gap-2 rounded-md px-3 py-2 text-sm font-medium text-[#1b1b18] hover:bg-[#f5f5f4] dark:text-[#EDEDEC] dark:hover:bg-[#1D1D1B]"
                    :class="[
                        {
                            'bg-[#f5f5f4] dark:bg-[#1D1D1B]':
                                page.url.startsWith(admin.dashboard().url),
                        },
                        { 'lg:justify-center lg:px-2': effectiveCollapsed },
                    ]"
                >
                    <svg
                        xmlns="http://www.w3.org/2000/svg"
                        class="h-5 w-5 shrink-0"
                        fill="none"
                        viewBox="0 0 24 24"
                        stroke="currentColor"
                    >
                        <path
                            stroke-linecap="round"
                            stroke-linejoin="round"
                            stroke-width="2"
                            d="M3 12l9-9 9 9M5 10v10a1 1 0 001 1h4v-6h4v6h4a1 1 0 001-1V10"
                        />
                    </svg>
                    <span :class="{ 'lg:hidden': effectiveCollapsed }"
                        >Dashboard</span
                    >
                </Link>
                <Link
                    v-if="can('front_office.access')"
                    :href="frontDeskHref"
                    title="Front Desk"
                    class="flex items-center gap-2 rounded-md px-3 py-2 text-sm font-medium text-[#1b1b18] hover:bg-[#f5f5f4] dark:text-[#EDEDEC] dark:hover:bg-[#1D1D1B]"
                    :class="[
                        {
                            'bg-[#f5f5f4] dark:bg-[#1D1D1B]':
                                page.url.startsWith(
                                    admin.frontDesk.index().url,
                                ),
                        },
                        { 'lg:justify-center lg:px-2': effectiveCollapsed },
                    ]"
                >
                    <svg
                        xmlns="http://www.w3.org/2000/svg"
                        class="h-5 w-5 shrink-0"
                        fill="none"
                        viewBox="0 0 24 24"
                        stroke="currentColor"
                    >
                        <path
                            stroke-linecap="round"
                            stroke-linejoin="round"
                            stroke-width="2"
                            :d="frontDeskIcon"
                        />
                    </svg>
                    <span :class="{ 'lg:hidden': effectiveCollapsed }"
                        >Front Desk</span
                    >
                </Link>
                <Link
                    v-if="
                        can([
                            'front_office.cashier_shift',
                            'front_office.cashier_shifts_view_all',
                        ]) && hasFeature('cashier_shifts')
                    "
                    :href="cashierShiftHref"
                    title="Cashier Shift"
                    class="flex items-center gap-2 rounded-md px-3 py-2 text-sm font-medium text-[#1b1b18] hover:bg-[#f5f5f4] dark:text-[#EDEDEC] dark:hover:bg-[#1D1D1B]"
                    :class="[
                        {
                            'bg-[#f5f5f4] dark:bg-[#1D1D1B]':
                                page.url.startsWith(
                                    admin.cashierShifts.index().url,
                                ),
                        },
                        { 'lg:justify-center lg:px-2': effectiveCollapsed },
                    ]"
                >
                    <svg
                        xmlns="http://www.w3.org/2000/svg"
                        class="h-5 w-5 shrink-0"
                        fill="none"
                        viewBox="0 0 24 24"
                        stroke="currentColor"
                    >
                        <path
                            stroke-linecap="round"
                            stroke-linejoin="round"
                            stroke-width="2"
                            :d="cashierShiftIcon"
                        />
                    </svg>
                    <span :class="{ 'lg:hidden': effectiveCollapsed }"
                        >Cashier Shift</span
                    >
                </Link>
                <Link
                    v-for="item in packageMainMenu"
                    :key="item.href"
                    :href="item.href"
                    :title="item.name"
                    class="flex items-center gap-2 rounded-md px-3 py-2 text-sm font-medium text-[#1b1b18] hover:bg-[#f5f5f4] dark:text-[#EDEDEC] dark:hover:bg-[#1D1D1B]"
                    :class="[
                        {
                            'bg-[#f5f5f4] dark:bg-[#1D1D1B]':
                                activePackageHref === item.href,
                        },
                        { 'lg:justify-center lg:px-2': effectiveCollapsed },
                    ]"
                >
                    <svg
                        xmlns="http://www.w3.org/2000/svg"
                        class="h-5 w-5 shrink-0"
                        fill="none"
                        viewBox="0 0 24 24"
                        stroke="currentColor"
                    >
                        <path
                            stroke-linecap="round"
                            stroke-linejoin="round"
                            stroke-width="2"
                            :d="item.icon"
                        />
                    </svg>
                    <span :class="{ 'lg:hidden': effectiveCollapsed }">{{
                        item.name
                    }}</span>
                </Link>
                <template v-if="!effectiveCollapsed && databasesMenu.length">
                    <button
                        type="button"
                        class="flex items-center justify-between rounded-md px-3 py-2 text-sm font-medium text-[#1b1b18] hover:bg-[#f5f5f4] dark:text-[#EDEDEC] dark:hover:bg-[#1D1D1B]"
                        @click="databasesMenuOpen = !databasesMenuOpen"
                    >
                        <span class="flex items-center gap-2">
                            <svg
                                xmlns="http://www.w3.org/2000/svg"
                                class="h-5 w-5 shrink-0"
                                fill="none"
                                viewBox="0 0 24 24"
                                stroke="currentColor"
                            >
                                <path
                                    stroke-linecap="round"
                                    stroke-linejoin="round"
                                    stroke-width="2"
                                    :d="databasesIcon"
                                />
                            </svg>
                            <span>Databases</span>
                        </span>
                        <svg
                            xmlns="http://www.w3.org/2000/svg"
                            class="h-4 w-4 transition-transform"
                            :class="{ 'rotate-90': databasesMenuOpen }"
                            fill="none"
                            viewBox="0 0 24 24"
                            stroke="currentColor"
                        >
                            <path
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                stroke-width="2"
                                d="M9 5l7 7-7 7"
                            />
                        </svg>
                    </button>
                    <div
                        v-if="databasesMenuOpen"
                        class="ml-3 flex flex-col gap-1 border-l border-[#e3e3e0] pl-3 dark:border-[#3E3E3A]"
                    >
                        <Link
                            v-for="item in databasesMenu"
                            :key="item.name"
                            :href="item.href"
                            class="flex items-center gap-2 rounded-md px-3 py-2 text-sm font-medium text-[#1b1b18] hover:bg-[#f5f5f4] dark:text-[#EDEDEC] dark:hover:bg-[#1D1D1B]"
                            :class="{
                                'bg-[#f5f5f4] dark:bg-[#1D1D1B]':
                                    page.url.startsWith(item.href.url),
                            }"
                        >
                            <svg
                                xmlns="http://www.w3.org/2000/svg"
                                class="h-4 w-4 shrink-0"
                                fill="none"
                                viewBox="0 0 24 24"
                                stroke="currentColor"
                            >
                                <path
                                    stroke-linecap="round"
                                    stroke-linejoin="round"
                                    stroke-width="2"
                                    :d="item.icon"
                                />
                            </svg>
                            {{ item.name }}
                        </Link>
                    </div>
                </template>
                <Link
                    v-else-if="databasesMenu.length"
                    :href="databasesMenu[0].href"
                    title="Databases"
                    class="flex items-center justify-center rounded-md px-2 py-2 text-sm font-medium text-[#1b1b18] hover:bg-[#f5f5f4] dark:text-[#EDEDEC] dark:hover:bg-[#1D1D1B]"
                >
                    <svg
                        xmlns="http://www.w3.org/2000/svg"
                        class="h-5 w-5 shrink-0"
                        fill="none"
                        viewBox="0 0 24 24"
                        stroke="currentColor"
                    >
                        <path
                            stroke-linecap="round"
                            stroke-linejoin="round"
                            stroke-width="2"
                            :d="databasesIcon"
                        />
                    </svg>
                </Link>
                <Link
                    v-if="can('pos.post_transaction') && hasFeature('pos')"
                    :href="posHref"
                    title="POS"
                    class="flex items-center gap-2 rounded-md px-3 py-2 text-sm font-medium text-[#1b1b18] hover:bg-[#f5f5f4] dark:text-[#EDEDEC] dark:hover:bg-[#1D1D1B]"
                    :class="[
                        {
                            'bg-[#f5f5f4] dark:bg-[#1D1D1B]':
                                page.url.startsWith(admin.pos.index().url),
                        },
                        { 'lg:justify-center lg:px-2': effectiveCollapsed },
                    ]"
                >
                    <svg
                        xmlns="http://www.w3.org/2000/svg"
                        class="h-5 w-5 shrink-0"
                        fill="none"
                        viewBox="0 0 24 24"
                        stroke="currentColor"
                    >
                        <path
                            stroke-linecap="round"
                            stroke-linejoin="round"
                            stroke-width="2"
                            :d="posIcon"
                        />
                    </svg>
                    <span :class="{ 'lg:hidden': effectiveCollapsed }"
                        >POS</span
                    >
                </Link>
                <Link
                    v-if="
                        can([
                            'manage_property.report_settings',
                            'manage_account.report_settings',
                            'manage_property.report_download_excel',
                            'manage_property.report_download_pdf',
                        ]) && hasFeature('reports')
                    "
                    :href="reportsHref"
                    title="Reports"
                    class="flex items-center gap-2 rounded-md px-3 py-2 text-sm font-medium text-[#1b1b18] hover:bg-[#f5f5f4] dark:text-[#EDEDEC] dark:hover:bg-[#1D1D1B]"
                    :class="[
                        {
                            'bg-[#f5f5f4] dark:bg-[#1D1D1B]':
                                page.url.startsWith(admin.reports.index().url),
                        },
                        { 'lg:justify-center lg:px-2': effectiveCollapsed },
                    ]"
                >
                    <svg
                        xmlns="http://www.w3.org/2000/svg"
                        class="h-5 w-5 shrink-0"
                        fill="none"
                        viewBox="0 0 24 24"
                        stroke="currentColor"
                    >
                        <path
                            stroke-linecap="round"
                            stroke-linejoin="round"
                            stroke-width="2"
                            :d="reportsIcon"
                        />
                    </svg>
                    <span :class="{ 'lg:hidden': effectiveCollapsed }"
                        >Reports</span
                    >
                </Link>

                <template v-if="!effectiveCollapsed && settingsMenu.length">
                    <button
                        type="button"
                        class="flex items-center justify-between rounded-md px-3 py-2 text-sm font-medium text-[#1b1b18] hover:bg-[#f5f5f4] dark:text-[#EDEDEC] dark:hover:bg-[#1D1D1B]"
                        @click="settingsMenuOpen = !settingsMenuOpen"
                    >
                        <span class="flex items-center gap-2">
                            <svg
                                xmlns="http://www.w3.org/2000/svg"
                                class="h-5 w-5 shrink-0"
                                fill="none"
                                viewBox="0 0 24 24"
                                stroke="currentColor"
                            >
                                <path
                                    stroke-linecap="round"
                                    stroke-linejoin="round"
                                    stroke-width="2"
                                    :d="settingsGearIcon"
                                />
                            </svg>
                            <span>Settings</span>
                        </span>
                        <svg
                            xmlns="http://www.w3.org/2000/svg"
                            class="h-4 w-4 transition-transform"
                            :class="{ 'rotate-90': settingsMenuOpen }"
                            fill="none"
                            viewBox="0 0 24 24"
                            stroke="currentColor"
                        >
                            <path
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                stroke-width="2"
                                d="M9 5l7 7-7 7"
                            />
                        </svg>
                    </button>
                    <div
                        v-if="settingsMenuOpen"
                        class="ml-3 flex flex-col gap-1 border-l border-[#e3e3e0] pl-3 dark:border-[#3E3E3A]"
                    >
                        <Link
                            v-for="item in settingsMenu"
                            :key="item.name"
                            :href="item.href"
                            class="flex items-center gap-2 rounded-md px-3 py-2 text-sm font-medium text-[#1b1b18] hover:bg-[#f5f5f4] dark:text-[#EDEDEC] dark:hover:bg-[#1D1D1B]"
                            :class="{
                                'bg-[#f5f5f4] dark:bg-[#1D1D1B]':
                                    page.url.startsWith(item.href.url),
                            }"
                        >
                            <svg
                                xmlns="http://www.w3.org/2000/svg"
                                class="h-4 w-4 shrink-0"
                                fill="none"
                                viewBox="0 0 24 24"
                                stroke="currentColor"
                            >
                                <path
                                    stroke-linecap="round"
                                    stroke-linejoin="round"
                                    stroke-width="2"
                                    :d="item.icon"
                                />
                            </svg>
                            {{ item.name }}
                        </Link>
                    </div>
                </template>
                <Link
                    v-else-if="settingsMenu.length"
                    :href="settingsMenu[0].href"
                    title="Settings"
                    class="flex items-center justify-center rounded-md px-2 py-2 text-sm font-medium text-[#1b1b18] hover:bg-[#f5f5f4] dark:text-[#EDEDEC] dark:hover:bg-[#1D1D1B]"
                >
                    <svg
                        xmlns="http://www.w3.org/2000/svg"
                        class="h-5 w-5 shrink-0"
                        fill="none"
                        viewBox="0 0 24 24"
                        stroke="currentColor"
                    >
                        <path
                            stroke-linecap="round"
                            stroke-linejoin="round"
                            stroke-width="2"
                            :d="settingsGearIcon"
                        />
                    </svg>
                </Link>

                <template
                    v-if="!effectiveCollapsed && manageAccountMenu.length"
                >
                    <button
                        type="button"
                        class="flex items-center justify-between rounded-md px-3 py-2 text-sm font-medium text-[#1b1b18] hover:bg-[#f5f5f4] dark:text-[#EDEDEC] dark:hover:bg-[#1D1D1B]"
                        @click="manageAccountMenuOpen = !manageAccountMenuOpen"
                    >
                        <span class="flex items-center gap-2">
                            <svg
                                xmlns="http://www.w3.org/2000/svg"
                                class="h-5 w-5 shrink-0"
                                fill="none"
                                viewBox="0 0 24 24"
                                stroke="currentColor"
                            >
                                <path
                                    stroke-linecap="round"
                                    stroke-linejoin="round"
                                    stroke-width="2"
                                    :d="manageAccountIcon"
                                />
                            </svg>
                            <span>Manage Account</span>
                        </span>
                        <svg
                            xmlns="http://www.w3.org/2000/svg"
                            class="h-4 w-4 transition-transform"
                            :class="{ 'rotate-90': manageAccountMenuOpen }"
                            fill="none"
                            viewBox="0 0 24 24"
                            stroke="currentColor"
                        >
                            <path
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                stroke-width="2"
                                d="M9 5l7 7-7 7"
                            />
                        </svg>
                    </button>
                    <div
                        v-if="manageAccountMenuOpen"
                        class="ml-3 flex flex-col gap-1 border-l border-[#e3e3e0] pl-3 dark:border-[#3E3E3A]"
                    >
                        <Link
                            v-for="item in manageAccountMenu"
                            :key="item.name"
                            :href="item.href"
                            class="flex items-center gap-2 rounded-md px-3 py-2 text-sm font-medium text-[#1b1b18] hover:bg-[#f5f5f4] dark:text-[#EDEDEC] dark:hover:bg-[#1D1D1B]"
                            :class="{
                                'bg-[#f5f5f4] dark:bg-[#1D1D1B]':
                                    page.url.startsWith(item.href.url),
                            }"
                        >
                            <svg
                                xmlns="http://www.w3.org/2000/svg"
                                class="h-4 w-4 shrink-0"
                                fill="none"
                                viewBox="0 0 24 24"
                                stroke="currentColor"
                            >
                                <path
                                    stroke-linecap="round"
                                    stroke-linejoin="round"
                                    stroke-width="2"
                                    :d="item.icon"
                                />
                            </svg>
                            {{ item.name }}
                        </Link>
                    </div>
                </template>
                <Link
                    v-else-if="manageAccountMenu.length"
                    :href="manageAccountMenu[0].href"
                    title="Manage Account"
                    class="flex items-center justify-center rounded-md px-2 py-2 text-sm font-medium text-[#1b1b18] hover:bg-[#f5f5f4] dark:text-[#EDEDEC] dark:hover:bg-[#1D1D1B]"
                >
                    <svg
                        xmlns="http://www.w3.org/2000/svg"
                        class="h-5 w-5 shrink-0"
                        fill="none"
                        viewBox="0 0 24 24"
                        stroke="currentColor"
                    >
                        <path
                            stroke-linecap="round"
                            stroke-linejoin="round"
                            stroke-width="2"
                            :d="manageAccountIcon"
                        />
                    </svg>
                </Link>

                <Link
                    :href="dashboard()"
                    title="Back to dashboard"
                    class="mt-4 flex items-center gap-2 rounded-md px-3 py-2 text-sm font-medium text-[#706f6c] hover:bg-[#f5f5f4] dark:text-[#A1A09A] dark:hover:bg-[#1D1D1B]"
                    :class="{ 'lg:justify-center lg:px-2': effectiveCollapsed }"
                >
                    <svg
                        xmlns="http://www.w3.org/2000/svg"
                        class="h-5 w-5 shrink-0"
                        fill="none"
                        viewBox="0 0 24 24"
                        stroke="currentColor"
                    >
                        <path
                            stroke-linecap="round"
                            stroke-linejoin="round"
                            stroke-width="2"
                            d="M10 19l-7-7m0 0l7-7m-7 7h18"
                        />
                    </svg>
                    <span :class="{ 'lg:hidden': effectiveCollapsed }"
                        >Back to dashboard</span
                    >
                </Link>
            </nav>
        </aside>

        <div
            class="transition-[padding] lg:pl-64"
            :class="{ 'lg:pl-16': effectiveCollapsed }"
        >
            <header
                class="flex h-16 items-center justify-between border-b border-[#e3e3e0] px-4 sm:px-6 dark:border-[#3E3E3A]"
            >
                <button
                    type="button"
                    class="text-[#1b1b18] lg:hidden dark:text-[#EDEDEC]"
                    @click="sidebarOpen = !sidebarOpen"
                >
                    <span class="sr-only">Toggle sidebar</span>
                    <svg
                        xmlns="http://www.w3.org/2000/svg"
                        class="h-6 w-6"
                        fill="none"
                        viewBox="0 0 24 24"
                        stroke="currentColor"
                    >
                        <path
                            stroke-linecap="round"
                            stroke-linejoin="round"
                            stroke-width="2"
                            d="M4 6h16M4 12h16M4 18h16"
                        />
                    </svg>
                </button>

                <label
                    class="flex items-center gap-2 rounded-lg border-2 border-[#1b1b18] bg-[#f5f5f4] px-3 py-1.5 dark:border-[#EDEDEC] dark:bg-[#1D1D1B]"
                >
                    <svg
                        xmlns="http://www.w3.org/2000/svg"
                        class="h-5 w-5 shrink-0 text-[#f53003] dark:text-[#FF4433]"
                        fill="none"
                        viewBox="0 0 24 24"
                        stroke="currentColor"
                    >
                        <path
                            stroke-linecap="round"
                            stroke-linejoin="round"
                            stroke-width="2"
                            d="M3 21h18M5 21V7l7-4 7 4v14M9 9h1m4 0h1m-6 4h1m4 0h1m-6 4h1m4 0h1"
                        />
                    </svg>
                    <span
                        class="hidden text-sm font-semibold tracking-wide text-[#1b1b18] uppercase sm:inline dark:text-[#EDEDEC]"
                        >Property:</span
                    >
                    <SearchableSelect
                        v-model="selectedPropertyId"
                        class="w-56"
                        :options="propertyOptions"
                        placeholder="Select a property…"
                    />
                </label>

                <div ref="userMenuRef" class="relative ml-auto">
                    <button
                        type="button"
                        class="flex items-center gap-1.5 rounded-md px-2 py-1.5 text-sm font-medium text-[#1b1b18] hover:bg-[#f5f5f4] dark:text-[#EDEDEC] dark:hover:bg-[#1D1D1B]"
                        :aria-expanded="userMenuOpen"
                        aria-haspopup="menu"
                        @click="userMenuOpen = !userMenuOpen"
                    >
                        {{ page.props.auth.user?.name }}
                        <svg
                            class="h-4 w-4 transition-transform"
                            :class="{ 'rotate-180': userMenuOpen }"
                            fill="none"
                            viewBox="0 0 24 24"
                            stroke="currentColor"
                            aria-hidden="true"
                        >
                            <path
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                stroke-width="2"
                                d="M19 9l-7 7-7-7"
                            />
                        </svg>
                    </button>
                    <div
                        v-if="userMenuOpen"
                        role="menu"
                        class="absolute right-0 z-50 mt-1 w-52 overflow-hidden rounded-md border border-[#e3e3e0] bg-white py-1 shadow-lg dark:border-[#3E3E3A] dark:bg-[#161615]"
                    >
                        <p
                            class="truncate border-b border-[#e3e3e0] px-4 py-2 text-xs text-[#706f6c] dark:border-[#3E3E3A] dark:text-[#A1A09A]"
                        >
                            {{ page.props.auth.user?.email }}
                        </p>
                        <Link
                            :href="profile.edit()"
                            role="menuitem"
                            class="block px-4 py-2 text-sm text-[#1b1b18] hover:bg-[#f5f5f4] dark:text-[#EDEDEC] dark:hover:bg-[#1D1D1B]"
                            @click="userMenuOpen = false"
                        >
                            Profile settings
                        </Link>
                        <Link
                            :href="AuthenticatedSessionController.destroy()"
                            method="post"
                            as="button"
                            role="menuitem"
                            class="block w-full px-4 py-2 text-left text-sm font-medium text-[#f53003] hover:bg-[#f5f5f4] dark:text-[#FF4433] dark:hover:bg-[#1D1D1B]"
                        >
                            Log out
                        </Link>
                    </div>
                </div>
            </header>

            <main class="p-4 sm:p-6">
                <div
                    v-for="notice in nightAuditNotices"
                    :key="notice.property_id"
                    class="mb-4 flex flex-wrap items-center justify-between gap-2 rounded-md border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-800 dark:border-amber-800 dark:bg-amber-950 dark:text-amber-300"
                    role="status"
                >
                    <span>
                        <strong
                            >Night audit in {{ notice.minutesLeft }} min</strong
                        >
                        — {{ notice.property_name }} closes
                        {{ calendarDate(notice.business_date) }}.
                        <template v-if="notice.pending_arrivals > 0">
                            {{ notice.pending_arrivals }} arrival(s) not checked
                            in will become no-shows.</template
                        >
                        <template v-if="notice.unreconciled_payments">
                            {{ notice.unreconciled_payments }} payment(s) still
                            to reconcile.</template
                        >
                        <template v-if="notice.open_pos_bills">
                            {{ notice.open_pos_bills }} POS bill(s) still
                            open.</template
                        >
                    </span>
                    <Link
                        :href="admin.nightAudits.edit(notice.property_id).url"
                        class="font-medium underline underline-offset-4"
                        >Open night audit</Link
                    >
                </div>

                <!-- A notice about the account from an installed package (e.g. an action it needs). -->
                <div
                    v-if="page.props.accountNotice"
                    class="mb-4 flex flex-wrap items-center justify-between gap-2 rounded-md border px-4 py-3 text-sm"
                    :class="
                        page.props.accountNotice.tone === 'danger'
                            ? 'border-red-300 bg-red-50 text-red-800 dark:border-red-800 dark:bg-red-950 dark:text-red-300'
                            : 'border-amber-300 bg-amber-50 text-amber-800 dark:border-amber-800 dark:bg-amber-950 dark:text-amber-300'
                    "
                    role="status"
                    data-test="account-notice"
                >
                    <span>{{ page.props.accountNotice.message }}</span>
                    <a
                        v-if="page.props.accountNotice.link"
                        :href="page.props.accountNotice.link.href"
                        class="font-medium underline underline-offset-4"
                        >{{ page.props.accountNotice.link.label }}</a
                    >
                </div>

                <!-- Flash messages render once, as floating toasts, so actions from a page's bottom bar or a lower section show their outcome too. -->
                <div
                    class="pointer-events-none fixed top-4 right-4 z-[130] flex max-w-md flex-col gap-2"
                >
                    <div
                        v-for="toast in flashToasts"
                        :key="toast.id"
                        class="pointer-events-auto flex items-start gap-3 rounded-md px-4 py-3 text-sm font-medium text-white shadow-lg"
                        :class="flashToastClasses[toast.kind]"
                        :role="toast.kind === 'success' ? 'status' : 'alert'"
                    >
                        <span class="flex-1">{{ toast.message }}</span>
                        <button
                            type="button"
                            class="leading-none text-white/80 hover:text-white"
                            aria-label="Dismiss"
                            @click="dismissFlashToast(toast.id)"
                        >
                            &times;
                        </button>
                    </div>
                </div>

                <slot />
            </main>
        </div>
    </div>
</template>
