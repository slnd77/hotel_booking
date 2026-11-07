import { ref, watch } from 'vue';

const STORAGE_KEY = 'admin-sidebar-collapsed';

function loadCollapsedPreference(): boolean {
    try {
        return localStorage.getItem(STORAGE_KEY) === '1';
    } catch {
        return false;
    }
}

const collapsed = ref(loadCollapsedPreference());

watch(collapsed, (value) => {
    try {
        localStorage.setItem(STORAGE_KEY, value ? '1' : '0');
    } catch {
        // Storage unavailable (private browsing, blocked, etc.) — nothing further to do.
    }
});

// Non-persisted override a page can use to force the icon-only rail (e.g. Quick Book)
// without touching the user's saved toggle preference for every other page.
const forceCollapsed = ref(false);

export function useSidebarCollapsed() {
    return { collapsed, forceCollapsed };
}
