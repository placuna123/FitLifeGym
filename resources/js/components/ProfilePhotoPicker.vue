<script setup lang="ts">
import { Camera, Trash2 } from 'lucide-vue-next';
import { ref } from 'vue';
import { profilePhoto } from '@/api/fitgym';

/**
 * Large round profile photo with "Change photo" / "Remove" actions. Shows the
 * initials when there is no photo. v-model:photoUrl updates after an upload so
 * the page can refresh its other avatars (sidebar card, header).
 */
const props = defineProps<{
    initials: string;
}>();

const photoUrl = defineModel<string | null>('photoUrl', { default: null });

const input = ref<HTMLInputElement | null>(null);
const busy = ref(false);
const error = ref('');
const notice = ref('');

function choose() {
    if (!busy.value) input.value?.click();
}

async function onSelected(event: Event) {
    const target = event.target as HTMLInputElement;
    const file = target.files?.[0];
    target.value = '';
    if (!file) return;

    error.value = '';
    notice.value = '';
    if (!file.type.startsWith('image/')) {
        error.value = 'Choose a photo (JPG, PNG or WebP).';
        return;
    }
    if (file.size > 4 * 1024 * 1024) {
        error.value = 'The photo must be 4 MB or smaller.';
        return;
    }

    busy.value = true;
    try {
        const { data } = await profilePhoto.upload(file);
        photoUrl.value = (data as { photo_url?: string | null }).photo_url ?? null;
        notice.value = 'Profile photo updated.';
    } catch (e) {
        const res = (e as { response?: { data?: { message?: string } } }).response;
        error.value = res?.data?.message || 'Could not upload the photo. Try again.';
    } finally {
        busy.value = false;
    }
}

async function remove() {
    if (busy.value) return;
    busy.value = true;
    error.value = '';
    notice.value = '';
    try {
        await profilePhoto.remove();
        photoUrl.value = null;
        notice.value = 'Profile photo removed.';
    } catch {
        error.value = 'Could not remove the photo. Try again.';
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <div class="fg-photo-picker">
        <button type="button" class="fg-photo-picker__avatar" :disabled="busy" :aria-label="photoUrl ? 'Change profile photo' : 'Upload profile photo'" @click="choose">
            <img v-if="photoUrl" :src="photoUrl" alt="Profile photo" />
            <span v-else>{{ props.initials }}</span>
            <span class="fg-photo-picker__badge" aria-hidden="true"><Camera /></span>
        </button>

        <div class="fg-photo-picker__actions">
            <button type="button" class="fg-photo-picker__btn" :disabled="busy" @click="choose">
                {{ busy ? 'Uploading…' : photoUrl ? 'Change photo' : 'Upload photo' }}
            </button>
            <button v-if="photoUrl" type="button" class="fg-photo-picker__btn fg-photo-picker__btn--ghost" :disabled="busy" @click="remove">
                <Trash2 class="size-3.5" /> Remove
            </button>
        </div>
        <p v-if="error" class="fg-photo-picker__error" role="alert">{{ error }}</p>
        <p v-else-if="notice" class="fg-photo-picker__notice" role="status">{{ notice }}</p>

        <input ref="input" type="file" accept="image/jpeg,image/png,image/webp" class="sr-only" @change="onSelected" />
    </div>
</template>

<style scoped>
.fg-photo-picker {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 0.75rem;
}

.fg-photo-picker__avatar {
    position: relative;
    display: grid;
    width: 8rem;
    height: 8rem;
    place-items: center;
    overflow: visible;
    border: 2px solid var(--fg-card-gold, var(--fg-accent));
    border-radius: 9999px;
    background: #8a2be2;
    color: #fff;
    font-size: 2.25rem;
    font-weight: 700;
    cursor: pointer;
}

/* Pinned to the round frame: a percentage height on a grid item resolves to auto,
   which let tall (portrait) photos grow past the circle and over the buttons. */
.fg-photo-picker__avatar img {
    position: absolute;
    inset: 0;
    width: 100%;
    height: 100%;
    border-radius: 9999px;
    object-fit: cover;
}

.fg-photo-picker__badge {
    z-index: 1;
}

.fg-photo-picker__badge {
    position: absolute;
    right: 0.25rem;
    bottom: 0.25rem;
    display: grid;
    width: 2.1rem;
    height: 2.1rem;
    place-items: center;
    border: 2px solid var(--fg-card, #151515);
    border-radius: 9999px;
    background: var(--fg-card-gold, var(--fg-accent));
    color: var(--fg-accent-ink);
}

.fg-photo-picker__badge :deep(svg) {
    width: 1rem;
    height: 1rem;
}

.fg-photo-picker__actions {
    display: flex;
    flex-wrap: wrap;
    justify-content: center;
    gap: 0.5rem;
}

.fg-photo-picker__btn {
    display: inline-flex;
    align-items: center;
    gap: 0.35rem;
    border: 1px solid var(--fg-card-gold, var(--fg-accent));
    border-radius: 0.5rem;
    background: var(--fg-card-gold, var(--fg-accent));
    padding: 0.4rem 0.85rem;
    color: var(--fg-accent-ink);
    font-size: 0.82rem;
    font-weight: 600;
    cursor: pointer;
}

.fg-photo-picker__btn--ghost {
    background: transparent;
    color: var(--fg-card-ink, #f5f1e8);
    border-color: var(--fg-card-border, #2f2c27);
}

.fg-photo-picker__btn:disabled,
.fg-photo-picker__avatar:disabled {
    opacity: 0.6;
    cursor: wait;
}

.fg-photo-picker__error,
.fg-photo-picker__notice {
    margin: 0;
    font-size: 0.8rem;
    text-align: center;
}

.fg-photo-picker__error {
    color: #f08a8e;
}

.fg-photo-picker__notice {
    color: #8fd16a;
}
</style>
