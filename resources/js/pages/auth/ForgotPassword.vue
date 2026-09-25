<script setup lang="ts">
import AuthSplitLayout from '@/layouts/AuthSplitLayout.vue';
import {
    authError,
    authInput,
    authLabel,
    authLink,
    authSubmit,
} from '@/lib/authStyles';
import { login } from '@/routes';
import { email as passwordEmail } from '@/routes/password';
import { Form, Head, Link } from '@inertiajs/vue3';

defineOptions({ layout: AuthSplitLayout });

defineProps<{
    status?: string | null;
}>();
</script>

<template>
    <Head title="Forgot password" />

    <div class="flex flex-col gap-2.5">
        <h1
            class="font-display m-0 text-[30px] leading-tight font-bold tracking-[-0.025em]"
        >
            Forgot your password?
        </h1>
        <p
            class="m-0 text-base leading-normal text-[#706f6c] dark:text-[#A1A09A]"
        >
            Enter the email you log in with and we'll send you a link to choose
            a new password.
        </p>
    </div>

    <Form
        :action="passwordEmail()"
        #default="{ errors, processing, wasSuccessful }"
        class="flex flex-col gap-5"
    >
        <div
            v-if="status || wasSuccessful"
            role="status"
            class="flex items-start gap-2.5 rounded-[10px] border border-green-200 bg-green-50 px-3.5 py-3 text-sm leading-snug font-medium text-green-600 dark:border-green-500/30 dark:bg-green-500/10 dark:text-green-500"
        >
            <span
                aria-hidden="true"
                class="mt-1.5 h-[7px] w-[7px] flex-none rounded-full bg-current"
            ></span>
            <span>{{
                status ||
                'If that email is registered, a reset link is on its way. Check your inbox.'
            }}</span>
        </div>

        <div class="flex flex-col gap-2">
            <label for="email" :class="authLabel">Email</label>
            <input
                id="email"
                type="email"
                name="email"
                required
                autofocus
                autocomplete="username"
                placeholder="you@yourhotel.com"
                :aria-invalid="!!errors.email"
                :class="authInput(!!errors.email)"
            />
            <p v-if="errors.email" :class="authError">{{ errors.email }}</p>
        </div>

        <button type="submit" :disabled="processing" :class="authSubmit">
            <span
                v-if="processing"
                aria-hidden="true"
                class="inline-block h-[15px] w-[15px] animate-spin rounded-full border-2 border-current border-r-transparent"
            ></span>
            <span>{{
                processing ? 'Sending...' : 'Email password reset link'
            }}</span>
        </button>
    </Form>

    <p class="m-0 text-center text-sm text-[#706f6c] dark:text-[#A1A09A]">
        Remembered it?
        <Link :href="login()" :class="authLink">Back to log in</Link>
    </p>
</template>
