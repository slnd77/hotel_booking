export type User = {
    id: number;
    name: string;
    email: string;
    avatar?: string;
    email_verified_at: string | null;
    created_at: string;
    updated_at: string;
    roles?: { name: string }[];
    mobile_country_code?: string | null;
    mobile?: string | null;
    /** Inactive users can't sign in. */
    status?: 'active' | 'inactive';
    /** Users list only: whether the current user may edit or delete this user. */
    can_manage?: boolean;
    [key: string]: unknown;
};

export type Auth = {
    user: User;
    /** Roles and Permissions: the super admin may do everything. */
    isSuperAdmin?: boolean;
    /** Permission names the signed-in user has. */
    permissions?: string[];
};
