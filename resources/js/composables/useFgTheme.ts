import { computed, ref } from 'vue';

// FitGym color theme: black-and-gold (default) or white-and-gold.
// The choice is kept in this browser, so each person picks their own.
export type FgTheme = 'dark' | 'light';

const STORAGE_KEY = 'fg-theme';

const readStoredTheme = (): FgTheme => {
    try {
        return localStorage.getItem(STORAGE_KEY) === 'light' ? 'light' : 'dark';
    } catch {
        return 'dark';
    }
};

const theme = ref<FgTheme>(typeof window === 'undefined' ? 'dark' : readStoredTheme());

const applyTheme = (value: FgTheme) => {
    if (typeof document === 'undefined') {
        return;
    }

    document.documentElement.classList.toggle('fg-day', value === 'light');
};

export function initializeFgTheme(): void {
    applyTheme(theme.value);
}

export function useFgTheme() {
    const isLight = computed(() => theme.value === 'light');

    function setTheme(value: FgTheme) {
        theme.value = value;
        applyTheme(value);

        try {
            localStorage.setItem(STORAGE_KEY, value);
        } catch {
            // private window or blocked storage: the theme still applies for this visit
        }
    }

    function toggleTheme() {
        setTheme(isLight.value ? 'dark' : 'light');
    }

    return { theme, isLight, setTheme, toggleTheme };
}
