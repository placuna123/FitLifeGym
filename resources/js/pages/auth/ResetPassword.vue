<script setup lang="ts">
import { Form, Head, Link } from '@inertiajs/vue3';
import PasswordToggle from '@/components/PasswordToggle.vue';
import { usePasswordVisibility } from '@/composables/usePasswordVisibility';
import FitGymAuthLayout from '@/layouts/auth/FitGymAuthLayout.vue';
import { request, update } from '@/routes/password';

defineProps<{
    token: string;
    email: string;
}>();

const pw = usePasswordVisibility();
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
                <template v-if="errors.email">
                    <p class="fg-error" role="alert">{{ errors.email }}</p>
                    <p class="fg-hint">
                        Each new request replaces older links, and links expire after 60 minutes. Use the link in your
                        newest email, or <Link :href="request()" class="fg-inline-link">request a new link</Link>.
                    </p>
                </template>
            </div>

            <div class="fg-field">
                <label for="password">New password</label>
                <div class="fg-password-field">
                    <input
                        id="password"
                        :type="pw.type('password')"
                        name="password"
                        autocomplete="new-password"
                        required
                        autofocus
                        placeholder="At least 8 characters"
                    />
                    <PasswordToggle :shown="pw.isShown('password')" @toggle="pw.toggle('password')" />
                </div>
                <p v-if="errors.password" class="fg-error" role="alert">{{ errors.password }}</p>
            </div>

            <div class="fg-field">
                <label for="password_confirmation">Confirm new password</label>
                <div class="fg-password-field">
                    <input
                        id="password_confirmation"
                        :type="pw.type('confirm')"
                        name="password_confirmation"
                        autocomplete="new-password"
                        required
                        placeholder="Type it again"
                    />
                    <PasswordToggle :shown="pw.isShown('confirm')" @toggle="pw.toggle('confirm')" />
                </div>
                <p v-if="errors.password_confirmation" class="fg-error" role="alert">{{ errors.password_confirmation }}</p>
            </div>

            <button type="submit" class="fg-primary" :disabled="processing" data-test="reset-password-button">
                {{ processing ? 'Saving…' : 'Save new password' }}
            </button>
        </Form>
    </FitGymAuthLayout>
</template>
