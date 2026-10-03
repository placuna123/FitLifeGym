import { onBeforeUnmount, onMounted, ref, watch } from 'vue';

// Must match the `lg` breakpoint where the portal sidebar stops being a drawer (see app.css).
const DESKTOP_QUERY = '(min-width: 1024px)';

export function useMobileNav() {
    const mobileNavOpen = ref(false);
    let desktopQuery: MediaQueryList | null = null;

    const closeMobileNav = () => {
        mobileNavOpen.value = false;
    };

    const onKeydown = (event: KeyboardEvent) => {
        if (event.key === 'Escape') closeMobileNav();
    };

    const onDesktopChange = (event: MediaQueryListEvent) => {
        if (event.matches) closeMobileNav();
    };

    watch(mobileNavOpen, (open) => {
        document.documentElement.style.overflow = open ? 'hidden' : '';
    });

    onMounted(() => {
        desktopQuery = window.matchMedia(DESKTOP_QUERY);
        desktopQuery.addEventListener('change', onDesktopChange);
        window.addEventListener('keydown', onKeydown);
    });

    onBeforeUnmount(() => {
        desktopQuery?.removeEventListener('change', onDesktopChange);
        window.removeEventListener('keydown', onKeydown);
        document.documentElement.style.overflow = '';
    });

    return { mobileNavOpen, closeMobileNav };
}
