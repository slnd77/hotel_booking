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
import { login } from '@/routes';
import registerRoutes from '@/routes/register';
import { Form, Head, Link, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

defineOptions({ layout: AuthSplitLayout });

defineProps<{
    googleSignIn?: boolean;
}>();

const page = usePage();
const googleError = computed(
    () => (page.props.errors as Record<string, string> | undefined)?.google,
);
const showPassword = ref(false);
</script>

<template>
    <Head title="Create an account" />

    <div class="flex flex-col gap-2.5">
        <h1
            class="font-display m-0 text-[30px] leading-tight font-bold tracking-[-0.025em]"
        >
            Create your account
        </h1>
        <p
            class="m-0 text-base leading-normal text-[#706f6c] dark:text-[#A1A09A]"
        >
            Set up access to your property dashboard in a minute.
        </p>
    </div>

    <AuthAlternatives
        :google-sign-in="!!googleSignIn"
        intent="register"
        :error="googleError"
    />

    <Form
        :action="registerRoutes.store()"
        :reset-on-success="['password', 'password_confirmation']"
        #default="{ errors, processing }"
        class="flex flex-col gap-5"
    >
        <div class="flex flex-col gap-2">
            <label for="name" :class="authLabel">Full name</label>
            <input
                id="name"
                type="text"
                name="name"
                required
                autofocus
                autocomplete="name"
                placeholder="Your name"
                :aria-invalid="!!errors.name"
                :class="authInput(!!errors.name)"
            />
            <p v-if="errors.name" :class="authError">{{ errors.name }}</p>
        </div>

        <div class="flex flex-col gap-2">
            <label for="email" :class="authLabel">Work email</label>
            <input
                id="email"
                type="email"
                name="email"
                required
                autocomplete="username"
                placeholder="you@yourhotel.com"
                :aria-invalid="!!errors.email"
                :class="authInput(!!errors.email)"
            />
            <p v-if="errors.email" :class="authError">{{ errors.email }}</p>
        </div>

        <div class="flex flex-col gap-2">
            <label for="password" :class="authLabel">Password</label>
            <div class="relative">
                <input
                    id="password"
                    :type="showPassword ? 'text' : 'password'"
                    name="password"
                    required
                    autocomplete="new-password"
                    placeholder="At least 8 characters"
                    :aria-invalid="!!errors.password"
                    :class="[authInput(!!errors.password), 'pr-[72px]']"
                />
                <button
                    type="button"
                    :aria-label="
                        showPassword ? 'Hide passwords' : 'Show passwords'
                    "
                    :aria-pressed="showPassword"
                    class="absolute top-[7px] right-[7px] h-8 rounded-md px-2.5 text-[13px] font-semibold text-[#706f6c] hover:bg-[#f5f5f4] hover:text-[#1b1b18] dark:text-[#A1A09A] dark:hover:bg-[#1C1C1A] dark:hover:text-[#EDEDEC]"
                    @click="showPassword = !showPassword"
                >
                    {{ showPassword ? 'Hide' : 'Show' }}
                </button>
            </div>
            <p v-if="errors.password" :class="authError">
                {{ errors.password }}
            </p>
        </div>

        <div class="flex flex-col gap-2">
            <label for="password_confirmation" :class="authLabel"
                >Confirm password</label
            >
            <input
                id="password_confirmation"
                :type="showPassword ? 'text' : 'password'"
                name="password_confirmation"
                required
                autocomplete="new-password"
                placeholder="Repeat the password"
                :aria-invalid="!!errors.password_confirmation"
                :class="authInput(!!errors.password_confirmation)"
            />
            <p v-if="errors.password_confirmation" :class="authError">
                {{ errors.password_confirmation }}
            </p>
        </div>

        <button type="submit" :disabled="processing" :class="authSubmit">
            <span
                v-if="processing"
                aria-hidden="true"
                class="inline-block h-[15px] w-[15px] animate-spin rounded-full border-2 border-current border-r-transparent"
            ></span>
            <span>{{
                processing ? 'Creating account...' : 'Create account'
            }}</span>
        </button>
    </Form>

    <p class="m-0 text-center text-sm text-[#706f6c] dark:text-[#A1A09A]">
        Already have an account?
        <Link :href="login()" :class="authLink">Log in</Link>
    </p>
</template>
