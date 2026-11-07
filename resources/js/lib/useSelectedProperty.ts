import { usePage } from '@inertiajs/vue3';
import { getCurrentInstance, onMounted, ref, watch } from 'vue';

const STORAGE_KEY = 'admin-selected-property';
const isServer = typeof window === 'undefined';

type CurrentProperty =
    | { id: number | null; scoped: boolean }
    | null
    | undefined;

/**
 * The property picked in the admin header, shared by the layout and the pages.
 *
 * The server decides it for every admin page (HandleInertiaRequests shares `currentProperty`:
 * the page's own property, else the one last used in the session, else the first), and both
 * the SSR render and the hydrating browser start from that value, so the markup matches. From
 * then on the pick lives here across visits, follows a page that is about another property,
 * and is remembered in localStorage once mounted.
 */
const selectedPropertyId = ref<number | ''>('');
let hydrated = false;

function adoptPageProperty(
    current: CurrentProperty,
    firstRender: boolean,
): void {
    if (!current) {
        return;
    }

    if (firstRender || current.scoped) {
        selectedPropertyId.value = current.id ?? '';
    }
}

function storedPropertyId(): number | '' {
    try {
        const raw = localStorage.getItem(STORAGE_KEY);

        return raw ? Number(raw) : '';
    } catch {
        return '';
    }
}

if (!isServer) {
    watch(selectedPropertyId, (value) => {
        if (!hydrated) {
            return;
        }

        try {
            if (value === '') {
                localStorage.removeItem(STORAGE_KEY);
            } else {
                localStorage.setItem(STORAGE_KEY, String(value));
            }
        } catch {
            // Storage unavailable (private browsing, blocked, etc.) — nothing further to do.
        }
    });
}

export function useSelectedProperty() {
    const page = usePage();

    // On the server the ref is shared by every render, so each one starts from its own page.
    adoptPageProperty(page.props.currentProperty, isServer || !hydrated);

    if (getCurrentInstance()) {
        watch(
            () => page.props.currentProperty,
            (current) => adoptPageProperty(current, false),
        );

        onMounted(() => {
            if (hydrated) {
                return;
            }

            hydrated = true;
            const current = page.props.currentProperty;
            const stored = storedPropertyId();

            // A page about no property in particular: the pick remembered in this browser wins
            // over the server's fallback — applied after hydration, so the markup still matched.
            if (
                current &&
                !current.scoped &&
                stored !== '' &&
                stored !== selectedPropertyId.value &&
                page.props.adminProperties.some(
                    (property) => property.id === stored,
                )
            ) {
                selectedPropertyId.value = stored;
            }
        });
    }

    return { selectedPropertyId };
}
