<script setup lang="ts">
import { Moon, Sun } from 'lucide-vue-next';
import { useFgTheme } from '@/composables/useFgTheme';

// floating: a round button in the top-right corner (sign-in pages).
// Otherwise it sits in a portal header and takes that header's button classes.
defineProps<{ floating?: boolean }>();

const { isLight, toggleTheme } = useFgTheme();
</script>

<template>
  <button
    type="button"
    class="fg-theme-toggle"
    :class="{ 'fg-theme-toggle--floating': floating }"
    :title="isLight ? 'Switch to dark mode (black and gold)' : 'Switch to light mode (white and gold)'"
    :aria-label="isLight ? 'Switch to dark mode' : 'Switch to light mode'"
    @click="toggleTheme"
  >
    <Moon v-if="isLight" aria-hidden="true" />
    <Sun v-else aria-hidden="true" />
    <span v-if="!floating">{{ isLight ? 'Dark mode' : 'Light mode' }}</span>
  </button>
</template>
