<script setup lang="ts">
import AuthAlternatives from '@/components/AuthAlternatives.vue';
import AuthSplitLayout from '@/layouts/AuthSplitLayout.vue';
import {
    authError,
    authInput,
    authLabel,
    authLink,
    authSubmit,
} from '@/lib/authStyles';
import { register } from '@/routes';
import loginRoutes from '@/routes/login';
import { request as passwordRequest } from '@/routes/password';
import { Form, Head, Link, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

defineOptions({ layout: AuthSplitLayout });

defineProps<{
    canResetPassword: boolean;
    canRegister?: boolean;
    googleSignIn?: boolean;
    status?: string | null;
}>();

const page = usePage();
/** A failed "Continue with Google" comes back as a session error. */
const googleError = computed(
    () => (page.props.errors as Record<string, string> | undefined)?.google,
);
const showPassword = ref(false);
const emailInput = ref<HTMLInputElement | null>(null);
const passwordInput = ref<HTMLInputElement | null>(null);

/** A panel an installed package shows above the form (e.g. a demo account to try). */
const loginPanel = computed(() => page.props.loginPanel ?? null);

/** Puts the panel's credentials in the form. */
function fillCredentials(): void {
    const credentials = loginPanel.value?.credentials;

    if (!credentials || !emailInput.value || !passwordInput.value) {
        return;
    }

    emailInput.value.value = credentials.email;
    passwordInput.value.value = credentials.password;
}
</script>

<template>
    <Head title="Log in" />

    <div class="flex flex-col gap-2.5">
        <h1
            class="font-display m-0 text-[30px] leading-tight font-bold tracking-[-0.025em]"
        >
            Log in to your account
        </h1>
        <p
            class="m-0 text-base leading-normal text-[#706f6c] dark:text-[#A1A09A]"
        >
            Enter your credentials to access your property dashboard.
        </p>
    </div>

    <div
        v-if="status"
        role="status"
        class="flex items-start gap-2.5 rounded-[10px] border border-green-200 bg-green-50 px-3.5 py-3 text-sm leading-snug font-medium text-green-600 dark:border-green-500/30 dark:bg-green-500/10 dark:text-green-500"
    >
        <span
            aria-hidden="true"
            class="mt-1.5 h-[7px] w-[7px] flex-none rounded-full bg-current"
        ></span>
        <span>{{ status }}</span>
    </div>

    <section
        v-if="loginPanel"
        aria-labelledby="login-panel-title"
        class="flex flex-col gap-3 rounded-[10px] border border-[#e3e3e0] bg-[#fafaf9] px-4 py-3.5 text-sm dark:border-[#3E3E3A] dark:bg-[#161615]"
    >
        <div class="flex items-center justify-between gap-3">
            <h2 id="login-panel-title" class="m-0 text-sm font-semibold">
                {{ loginPanel.title }}
            </h2>
            <span
                v-if="loginPanel.note"
                class="text-xs text-[#706f6c] dark:text-[#A1A09A]"
                >{{ loginPanel.note }}</span
            >
        </div>
        <dl
            v-if="loginPanel.credentials"
            class="m-0 grid grid-cols-[auto_1fr] gap-x-3 gap-y-1"
        >
            <dt class="text-[#706f6c] dark:text-[#A1A09A]">Email</dt>
            <dd class="m-0 font-medium break-all">
                {{ loginPanel.credentials.email }}
            </dd>
            <dt class="text-[#706f6c] dark:text-[#A1A09A]">Password</dt>
            <dd class="m-0 font-medium break-all">
                {{ loginPanel.credentials.password }}
            </dd>
        </dl>
        <div class="flex flex-wrap items-center gap-x-4 gap-y-2">
            <button
                v-if="loginPanel.credentials"
                type="button"
                class="rounded-md border border-[#1b1b18] px-3 py-1.5 text-[13px] font-semibold hover:bg-[#1b1b18] hover:text-white dark:border-[#EDEDEC] dark:hover:bg-[#EDEDEC] dark:hover:text-[#1b1b18]"
                @click="fillCredentials"
            >
                Use these details
            </button>
            <a
                v-for="link in loginPanel.links ?? []"
                :key="link.href"
                :href="link.href"
                :class="authLink"
                >{{ link.label }}</a
            >
        </div>
    </section>

    <Form
        :action="loginRoutes.store()"
        :reset-on-success="['password']"
        #default="{ errors, processing }"
        class="flex flex-col gap-5"
    >
        <div class="flex flex-col gap-2">
            <label for="email" :class="authLabel">Email</label>
            <input
                id="email"
                ref="emailInput"
                type="email"
                name="email"
                required
                autofocus
                autocomplete="username"
                placeholder="you@yourhotel.com"
                :aria-invalid="!!errors.email"
                aria-describedby="email-error"
                :class="authInput(!!errors.email)"
            />
            <p v-if="errors.email" id="email-error" :class="authError">
                {{ errors.email }}
            </p>
        </div>

        <div class="flex flex-col gap-2">
            <div class="flex items-baseline justify-between gap-3">
                <label for="password" :class="authLabel">Password</label>
                <Link
                    v-if="canResetPassword"
                    :href="passwordRequest()"
                    tabindex="5"
                    class="text-[13px] font-medium text-[#f53003] hover:underline hover:underline-offset-[3px] dark:text-[#FF4433]"
                >
                    Forgot your password?
                </Link>
            </div>
            <div class="relative">
                <input
                    id="password"
                    ref="passwordInput"
                    :type="showPassword ? 'text' : 'password'"
                    name="password"
                    required
                    autocomplete="current-password"
                    placeholder="Password"
                    :aria-invalid="!!errors.password"
                    aria-describedby="password-error"
                    :class="[authInput(!!errors.password), 'pr-[72px]']"
                />
                <button
                    type="button"
                    :aria-label="
                        showPassword ? 'Hide password' : 'Show password'
                    "
                    :aria-pressed="showPassword"
                    aria-controls="password"
                    class="absolute top-[7px] right-[7px] h-8 rounded-md px-2.5 text-[13px] font-semibold text-[#706f6c] hover:bg-[#f5f5f4] hover:text-[#1b1b18] dark:text-[#A1A09A] dark:hover:bg-[#1C1C1A] dark:hover:text-[#EDEDEC]"
                    @click="showPassword = !showPassword"
                >
                    {{ showPassword ? 'Hide' : 'Show' }}
                </button>
            </div>
            <p v-if="errors.password" id="password-error" :class="authError">
                {{ errors.password }}
            </p>
        </div>

        <label
            for="remember"
            class="flex w-max cursor-pointer items-center gap-2.5 text-sm"
        >
            <input
                id="remember"
                type="checkbox"
                name="remember"
                class="m-0 h-4 w-4 cursor-pointer accent-[#1b1b18] dark:accent-[#eeeeec]"
            />
            Remember me
        </label>

        <button type="submit" :disabled="processing" :class="authSubmit">
            <span
                v-if="processing"
                aria-hidden="true"
                class="inline-block h-[15px] w-[15px] animate-spin rounded-full border-2 border-current border-r-transparent"
            ></span>
            <span>{{ processing ? 'Logging in...' : 'Log in' }}</span>
        </button>
    </Form>

    <AuthAlternatives
        :google-sign-in="!!googleSignIn"
        intent="login"
        :error="googleError"
    />

    <p
        v-if="canRegister !== false"
        class="m-0 text-center text-sm text-[#706f6c] dark:text-[#A1A09A]"
    >
        Don't have an account?
        <Link :href="register()" :class="authLink">Create an account</Link>
    </p>
</template>
