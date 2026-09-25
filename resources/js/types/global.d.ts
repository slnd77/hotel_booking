import type { Auth } from '@/types/auth';

// Extend ImportMeta interface for Vite...
declare module 'vite/client' {
    interface ImportMetaEnv {
        readonly VITE_APP_NAME: string;
        [key: string]: string | boolean | undefined;
    }

    interface ImportMeta {
        readonly env: ImportMetaEnv;
        readonly glob: <T>(pattern: string) => Record<string, () => Promise<T>>;
    }
}

declare module '@inertiajs/core' {
    export interface InertiaConfig {
        sharedPageProps: {
            name: string;
            auth: Auth;
            sidebarOpen: boolean;
            flash: {
                success?: string | null;
                error?: string | null;
                warning?: string | null;
            };
            adminProperties: {
                id: number;
                name: string;
                abbreviation: string;
            }[];
            /** The header picker's property (null outside admin); `scoped`: the page itself is about it. */
            currentProperty: { id: number | null; scoped: boolean } | null;
            /** "Set your default list view": the user's saved columns, per table key. */
            listViews?: Record<string, Record<string, boolean>>;
            /** The configured currency of the property the admin page is about (null outside admin). */
            currency: { code: string; symbol: string } | null;
            /** IANA timezone of that property; times arrive in UTC and are shown in it (null outside admin). */
            timezone: string | null;
            /** Night audit → Pre-rollover Notification: automatic rollovers due within their notice window. */
            nightAuditNotices?: {
                property_id: number;
                property_name: string;
                business_date: string;
                rollover_at: string;
                pending_arrivals: number;
                unreconciled_payments: number | null;
                open_pos_bills: number | null;
            }[];
            /** Single sign-on, when a package provides it: the login page links to it. */
            sso?: { login_url: string };
            /** Menu entries of installed packages (package => sidebar section => entries). */
            packageMenus?: Record<
                string,
                {
                    manage_account?: {
                        name: string;
                        href: string;
                        icon: string;
                    }[];
                    main?: { name: string; href: string; icon: string }[];
                }
            >;
            /** Features an installed package turns off (key => on); missing means everything is on. */
            features?: Record<string, boolean> | null;
            /** A notice about the account from an installed package, shown above every admin page. */
            accountNotice?: {
                tone: 'warning' | 'danger';
                message: string;
                link?: { href: string; label: string } | null;
            } | null;
            /** A panel an installed package shows on the login page (e.g. a demo account). */
            loginPanel?: {
                title: string;
                note?: string | null;
                credentials?: { email: string; password: string } | null;
                links?: { href: string; label: string }[];
            } | null;
            /** The business account the user works in, when a package provides accounts. */
            businessAccount?: { name: string } | null;
            [key: string]: unknown;
        };
    }
}

declare module 'vue' {
    interface ComponentCustomProperties {
        $inertia: typeof Router;
        $page: Page;
        $headManager: ReturnType<typeof createHeadManager>;
    }
}
