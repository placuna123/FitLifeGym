<script setup lang="ts">
import { Eye, EyeOff } from 'lucide-vue-next';

defineProps<{ shown: boolean }>();
defineEmits<{ toggle: [] }>();
</script>

<template>
    <!-- Sits inside a .fg-password-field wrapper next to the input (styles in app.css). -->
    <button
        type="button"
        class="fg-password-toggle"
        :aria-label="shown ? 'Hide password' : 'Show password'"
        :aria-pressed="shown"
        :title="shown ? 'Hide password' : 'Show password'"
        @click="$emit('toggle')"
    >
        <EyeOff v-if="shown" class="h-5 w-5" aria-hidden="true" />
        <Eye v-else class="h-5 w-5" aria-hidden="true" />
    </button>
</template>
