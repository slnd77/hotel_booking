import { usePage } from '@inertiajs/vue3';
import { reactive, watch } from 'vue';
import admin from '@/routes/admin';

function load(key: string): Record<string, boolean> {
    try {
        const raw = localStorage.getItem(key);

        return raw ? JSON.parse(raw) : {};
    } catch {
        return {};
    }
}

function remember(key: string, value: Record<string, boolean>): void {
    try {
        localStorage.setItem(key, JSON.stringify(value));
    } catch {
        // Storage unavailable (private browsing, blocked, etc.) — the account copy still holds it.
    }
}

/** Saves the choice to the user's account, so it follows them to any browser. */
function saveToAccount(key: string, value: Record<string, boolean>): void {
    const token = decodeURIComponent(
        document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]*)/)?.[1] ?? '',
    );

    void fetch(admin.listViews.update(key).url, {
        method: 'PUT',
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-XSRF-TOKEN': token,
        },
        body: JSON.stringify({ columns: value }),
    }).catch(() => {
        // Offline: this browser's copy is used until the next save.
    });
}

/**
 * The signed-in user's column choices for a data table ("Set your default list view"),
 * saved to their account under `storageKey` and cached in this browser. Columns not chosen
 * yet follow `defaults`, else they're shown.
 */
export function useColumnVisibility(
    storageKey: string,
    columnKeys: string[],
    defaults: Record<string, boolean> = {},
) {
    const accountViews = usePage().props.listViews ?? {};
    const fromAccount = accountViews[storageKey];
    const stored = fromAccount ?? load(storageKey);
    const visibility = reactive<Record<string, boolean>>(
        Object.fromEntries(
            columnKeys.map((key) => [
                key,
                stored[key] ?? defaults[key] ?? true,
            ]),
        ),
    );

    watch(
        visibility,
        (value) => {
            remember(storageKey, value);
            saveToAccount(storageKey, { ...value });
        },
        { deep: true },
    );

    // Choices made in this browser before they were saved to the account move there.
    if (
        fromAccount === undefined &&
        Object.keys(stored).length > 0 &&
        typeof window !== 'undefined'
    ) {
        saveToAccount(storageKey, { ...visibility });
    }

    return visibility;
}
