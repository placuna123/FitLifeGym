<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import FgThemeToggle from '@/components/FgThemeToggle.vue';
import { home } from '@/routes';

defineProps<{
    title: string;
    description?: string;
}>();
</script>

<template>
    <div class="fg-auth fg-marble-bg">
        <FgThemeToggle floating />

        <div class="fg-auth-shell">
            <Link :href="home()" class="fg-auth-brand">
                <span class="fg-auth-logo" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M6.5 6.5 17.5 17.5" />
                        <path d="M4 8v8" />
                        <path d="M20 8v8" />
                        <path d="M7 5v14" />
                        <path d="M17 5v14" />
                    </svg>
                </span>
                <span>
                    <span class="fg-auth-eyebrow">FitGym Platform</span>
                    <span class="fg-auth-wordmark">FitGym</span>
                </span>
            </Link>

            <section class="fg-auth-card">
                <h1 class="fg-auth-title">{{ title }}</h1>
                <p v-if="description" class="fg-auth-description">{{ description }}</p>
                <slot />
            </section>

            <p class="fg-auth-footer">
                <Link :href="home()">← Back to sign in</Link>
            </p>
        </div>
    </div>
</template>

<style scoped>
.fg-auth {
    position: relative;
    display: flex;
    min-height: 100vh;
    min-height: 100dvh;
    align-items: center;
    justify-content: center;
    overflow: hidden;
    padding: 2rem 1rem;
    color: var(--fg-card-ink);
    font-family: 'Instrument Sans', ui-sans-serif, system-ui, -apple-system, 'Segoe UI', sans-serif;
}

.fg-auth-shell {
    position: relative;
    display: flex;
    width: 100%;
    max-width: 28rem;
    flex-direction: column;
    gap: 1.5rem;
}

.fg-auth-brand {
    display: flex;
    align-items: center;
    gap: 0.875rem;
    /* the brand and footer sit straight on the marble background, so they use dark ink */
    color: var(--fg-ink);
    text-decoration: none;
}

.fg-auth-logo {
    color: #ffffff;
    display: grid;
    flex-shrink: 0;
    width: 3rem;
    height: 3rem;
    place-items: center;
    border-radius: 0.9rem;
    background: #8a2be2;
}

.fg-auth-logo svg {
    width: 1.6rem;
    height: 1.6rem;
}

.fg-auth-eyebrow {
    display: block;
    color: var(--fg-gold);
    font-size: 0.75rem;
    font-weight: 600;
    letter-spacing: 0.16em;
    text-transform: uppercase;
}

.fg-auth-wordmark {
    display: block;
    margin-top: 0.15rem;
    font-size: 1.6rem;
    font-weight: 700;
    line-height: 1.2;
}

.fg-auth-card {
    border: 1px solid var(--fg-card-border);
    border-radius: 1.25rem;
    background: var(--fg-card);
    box-shadow: 0 18px 44px rgba(92, 70, 30, 0.1);
    padding: 1.75rem;
}

.fg-auth-title {
    margin: 0;
    font-size: 1.5rem;
    font-weight: 700;
}

.fg-auth-description {
    margin: 0.35rem 0 1.4rem;
    color: var(--fg-card-ink-soft);
    font-size: 0.875rem;
}

.fg-auth-footer {
    margin: 0;
    text-align: center;
    font-size: 0.875rem;
}

.fg-auth-footer a {
    color: var(--fg-accent-soft);
    font-weight: 600;
    text-decoration: none;
}

.fg-auth-footer a:hover {
    text-decoration: underline;
}

@media (max-width: 480px) {
    .fg-auth-card {
        padding: 1.35rem;
    }

    .fg-auth-wordmark {
        font-size: 1.35rem;
    }
}
</style>

<style>
/* Form pieces used by the pages rendered inside this layout */
.fg-auth .fg-form {
    display: flex;
    flex-direction: column;
    gap: 1.1rem;
}

.fg-auth .fg-field {
    display: flex;
    flex-direction: column;
    gap: 0.4rem;
}

.fg-auth .fg-field label {
    color: var(--fg-card-ink-soft);
    font-size: 0.82rem;
    font-weight: 500;
}

.fg-auth .fg-field input {
    width: 100%;
    border: 1px solid var(--fg-card-border);
    border-radius: 0.65rem;
    background: var(--fg-card-2);
    padding: 0.75rem;
    color: var(--fg-card-ink);
    font-size: 1rem;
    outline: none;
}

.fg-auth .fg-field input::placeholder {
    color: #77716a;
}

.fg-auth .fg-field input:focus {
    border-color: #8a2be2;
}

.fg-auth .fg-field input[readonly] {
    color: var(--fg-card-ink-soft);
}

.fg-auth .fg-error {
    margin: 0;
    color: #f08a8e;
    font-size: 0.82rem;
}

.fg-auth .fg-status {
    margin: 0 0 1.1rem;
    border: 1px solid rgba(141, 255, 0, 0.35);
    border-radius: 0.7rem;
    background: rgba(141, 255, 0, 0.1);
    padding: 0.75rem 0.85rem;
    color: #8fd16a;
    font-size: 0.875rem;
}

.fg-auth .fg-primary {
    width: 100%;
    border: none;
    border-radius: 0.7rem;
    background: linear-gradient(135deg, var(--fg-accent-light) 0%, var(--fg-accent) 55%, var(--fg-accent-dark) 100%);
    padding: 0.85rem 1rem;
    color: var(--fg-accent-ink);
    font-size: 1rem;
    font-weight: 600;
    cursor: pointer;
    transition: opacity 0.18s ease;
}

.fg-auth .fg-primary:hover:not(:disabled) {
    opacity: 0.9;
}

.fg-auth .fg-primary:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}

.fg-auth .fg-hint {
    margin: 0;
    color: var(--fg-card-ink-soft);
    font-size: 0.78rem;
    line-height: 1.5;
}

.fg-auth .fg-inline-link {
    color: var(--fg-accent-soft);
    font-weight: 600;
    text-decoration: underline;
}

</style>
