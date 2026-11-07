<script setup lang="ts">
import { authSecondary } from '@/lib/authStyles';
import google from '@/routes/auth/google';
import { usePage } from '@inertiajs/vue3';

/**
 * "or continue with": Google (when configured) and the account's single sign-on (when set up).
 * Plain links, not Inertia visits: both leave the site for the identity provider.
 */
const props = defineProps<{
    googleSignIn: boolean;
    intent: 'login' | 'register';
    error?: string;
}>();

const page = usePage();
const googleHref = google.redirect({ intent: props.intent }).url;
/** Single sign-on signs in existing staff only, so it isn't offered on the create-account page. */
const showSso = props.intent === 'login' && !!page.props.sso;
</script>

<template>
    <div v-if="googleSignIn || showSso" class="flex flex-col gap-5">
        <div
            class="flex items-center gap-3.5 text-[13px] text-[#706f6c] dark:text-[#A1A09A]"
        >
            <span class="h-px flex-1 bg-[#e3e3e0] dark:bg-[#3E3E3A]"></span>
            <span>or continue with</span>
            <span class="h-px flex-1 bg-[#e3e3e0] dark:bg-[#3E3E3A]"></span>
        </div>

        <div class="flex flex-col gap-3">
            <a
                v-if="googleSignIn"
                :href="googleHref"
                :class="authSecondary"
                data-test="google-sign-in"
            >
                <svg
                    aria-hidden="true"
                    class="h-[18px] w-[18px]"
                    viewBox="0 0 48 48"
                >
                    <path
                        fill="#FFC107"
                        d="M43.6 20.5H42V20H24v8h11.3C33.7 32.7 29.2 36 24 36c-6.6 0-12-5.4-12-12s5.4-12 12-12c3.1 0 5.8 1.2 7.9 3.1l5.7-5.7C34.1 6.1 29.3 4 24 4 12.9 4 4 12.9 4 24s8.9 20 20 20 20-8.9 20-20c0-1.3-.1-2.4-.4-3.5z"
                    />
                    <path
                        fill="#FF3D00"
                        d="M6.3 14.7l6.6 4.8C14.7 15.1 19 12 24 12c3.1 0 5.8 1.2 7.9 3.1l5.7-5.7C34.1 6.1 29.3 4 24 4 16.3 4 9.7 8.3 6.3 14.7z"
                    />
                    <path
                        fill="#4CAF50"
                        d="M24 44c5.2 0 9.9-2 13.4-5.2l-6.2-5.2C29.2 35.1 26.7 36 24 36c-5.2 0-9.6-3.3-11.3-8l-6.5 5C9.5 39.6 16.2 44 24 44z"
                    />
                    <path
                        fill="#1976D2"
                        d="M43.6 20.5H42V20H24v8h11.3c-.8 2.2-2.2 4.2-4.1 5.6l6.2 5.2C37 39.2 44 34 44 24c0-1.3-.1-2.4-.4-3.5z"
                    />
                </svg>
                {{
                    intent === 'register'
                        ? 'Sign up with Google'
                        : 'Continue with Google'
                }}
            </a>

            <a
                v-if="showSso && page.props.sso"
                :href="page.props.sso.login_url"
                :class="authSecondary"
                data-test="sso-login-link"
            >
                <span
                    aria-hidden="true"
                    class="grid h-3.5 w-3.5 place-items-center rounded border-[1.5px] border-current"
                    ><span class="h-1 w-1 rounded-full bg-current"></span
                ></span>
                Sign in with SSO
            </a>
        </div>

        <p
            v-if="error"
            class="m-0 text-[13px] text-red-600 dark:text-red-500"
            role="alert"
            data-test="google-error"
        >
            {{ error }}
        </p>
    </div>
    <p
        v-else-if="error"
        class="m-0 text-[13px] text-red-600 dark:text-red-500"
        role="alert"
    >
        {{ error }}
    </p>
</template>
