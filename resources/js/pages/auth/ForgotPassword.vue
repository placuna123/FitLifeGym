<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import FitGymAuthLayout from '@/layouts/auth/FitGymAuthLayout.vue';
import { email } from '@/routes/password';

defineProps<{
    status?: string;
}>();
</script>

<template>
    <FitGymAuthLayout
        title="Forgot password"
        description="Enter the email you use to sign in to FitGym. We'll email you a link to set a new password."
    >
        <Head title="Forgot password" />

        <p v-if="status" class="fg-status" role="status">
            {{ status }} Check your inbox (and Spam folder).
        </p>

        <Form v-bind="email.form()" class="fg-form" v-slot="{ errors, processing }">
            <div class="fg-field">
                <label for="email">Email address</label>
                <input
                    id="email"
                    type="email"
                    name="email"
                    autocomplete="email"
                    required
                    autofocus
                    placeholder="your.email@example.com"
                />
                <p v-if="errors.email" class="fg-error" role="alert">{{ errors.email }}</p>
            </div>

            <button
                type="submit"
                class="fg-primary"
                :disabled="processing"
                data-test="email-password-reset-link-button"
            >
                {{ processing ? 'Sending link…' : 'Email password reset link' }}
            </button>

            <p class="fg-hint">Works for Admin, Staff, and Member accounts. The link expires in 60 minutes.</p>
        </Form>
    </FitGymAuthLayout>
</template>
