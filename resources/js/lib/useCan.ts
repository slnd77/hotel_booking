import { usePage } from '@inertiajs/vue3';

/**
 * Roles and Permissions on the page: whether the signed-in user has a permission (any one of a
 * list). The super admin has all of them. The server checks again on every action.
 */
export function useCan() {
    const page = usePage();

    return (permission: string | string[] | null | undefined): boolean => {
        const auth = page.props.auth;

        if (!permission || auth?.isSuperAdmin) {
            return true;
        }

        const granted = auth?.permissions ?? [];

        return (Array.isArray(permission) ? permission : [permission]).some(
            (name) => granted.includes(name),
        );
    };
}
