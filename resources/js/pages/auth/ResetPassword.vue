<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import FitGymAuthLayout from '@/layouts/auth/FitGymAuthLayout.vue';
import { update } from '@/routes/password';

defineProps<{
    token: string;
    email: string;
}>();
</script>

<template>
    <FitGymAuthLayout title="Set a new password" description="Choose a new password for your FitGym account.">
        <Head title="Reset password" />

        <Form
            v-bind="update.form()"
            class="fg-form"
            :transform="(data) => ({ ...data, token, email })"
            :reset-on-success="['password', 'password_confirmation']"
            v-slot="{ errors, processing }"
        >
            <div class="fg-field">
                <label for="email">Email</label>
                <input id="email" type="email" name="email" autocomplete="email" :value="email" readonly />
                <p v-if="errors.email" class="fg-error" role="alert">{{ errors.email }}</p>
            </div>

            <div class="fg-field">
                <label for="password">New password</label>
                <input
                    id="password"
                    type="password"
                    name="password"
                    autocomplete="new-password"
                    required
                    autofocus
                    placeholder="At least 8 characters"
                />
                <p v-if="errors.password" class="fg-error" role="alert">{{ errors.password }}</p>
            </div>

            <div class="fg-field">
                <label for="password_confirmation">Confirm new password</label>
                <input
                    id="password_confirmation"
                    type="password"
                    name="password_confirmation"
                    autocomplete="new-password"
                    required
                    placeholder="Type it again"
                />
                <p v-if="errors.password_confirmation" class="fg-error" role="alert">{{ errors.password_confirmation }}</p>
            </div>

            <button type="submit" class="fg-primary" :disabled="processing" data-test="reset-password-button">
                {{ processing ? 'Saving…' : 'Save new password' }}
            </button>
        </Form>
    </FitGymAuthLayout>
</template>
