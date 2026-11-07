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
import { update as passwordUpdate } from '@/routes/password';
import { Form, Head, Link } from '@inertiajs/vue3';

defineOptions({ layout: AuthSplitLayout });

const props = defineProps<{
    email: string;
    token: string;
}>();
</script>

<template>
    <Head title="Reset password" />

    <div class="flex flex-col gap-2.5">
        <h1
            class="font-display m-0 text-[30px] leading-tight font-bold tracking-[-0.025em]"
        >
            Choose a new password
        </h1>
        <p
            class="m-0 text-base leading-normal text-[#706f6c] dark:text-[#A1A09A]"
        >
            Use it the next time you log in.
        </p>
    </div>

    <Form
        :action="passwordUpdate()"
        #default="{ errors, processing }"
        class="flex flex-col gap-5"
    >
        <input type="hidden" name="token" :value="props.token" />

        <div class="flex flex-col gap-2">
            <label for="email" :class="authLabel">Email</label>
            <input
                id="email"
                type="email"
                name="email"
                required
                :value="props.email"
                autocomplete="username"
                :aria-invalid="!!errors.email"
                :class="authInput(!!errors.email)"
            />
            <p v-if="errors.email" :class="authError">{{ errors.email }}</p>
        </div>

        <div class="flex flex-col gap-2">
            <label for="password" :class="authLabel">New password</label>
            <input
                id="password"
                type="password"
                name="password"
                required
                autofocus
                autocomplete="new-password"
                :aria-invalid="!!errors.password"
                :class="authInput(!!errors.password)"
            />
            <p v-if="errors.password" :class="authError">
                {{ errors.password }}
            </p>
        </div>

        <div class="flex flex-col gap-2">
            <label for="password_confirmation" :class="authLabel"
                >Confirm new password</label
            >
            <input
                id="password_confirmation"
                type="password"
                name="password_confirmation"
                required
                autocomplete="new-password"
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
            <span>{{ processing ? 'Resetting...' : 'Reset password' }}</span>
        </button>
    </Form>

    <p class="m-0 text-center text-sm text-[#706f6c] dark:text-[#A1A09A]">
        <Link :href="login()" :class="authLink">Back to log in</Link>
    </p>
</template>
