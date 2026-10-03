import { onBeforeUnmount, onMounted } from 'vue';
import { goToEntry, type PortalRole } from '@/lib/portal';

type UserResponse = { data: { success?: boolean; user?: { email?: string } | null } };

interface SessionGuardOptions {
    role: PortalRole;
    fetchUser: () => Promise<UserResponse>;
    currentEmail: () => string | null | undefined;
    isActive: () => boolean;
}

const MIN_INTERVAL_MS = 2000;

/**
 * One browser shares one login session across all its tabs, so signing in as
 * someone else in another tab silently swaps the account behind this page.
 * Re-check whenever the tab regains focus and send the user back to sign-in
 * if the session no longer belongs to the account this page was opened for.
 */
export function useSessionGuard({ role, fetchUser, currentEmail, isActive }: SessionGuardOptions) {
    let checking = false;
    let lastCheck = 0;

    async function verify() {
        if (!isActive() || checking || Date.now() - lastCheck < MIN_INTERVAL_MS) return;
        checking = true;
        lastCheck = Date.now();
        try {
            const { data } = await fetchUser();
            const sameAccount = data.success && data.user?.email && data.user.email === currentEmail();
            if (!sameAccount) goToEntry(role, { reason: 'session' });
        } catch (err: unknown) {
            const status = (err as { response?: { status?: number } }).response?.status;
            // 401/403: the session is gone or now belongs to another role. Network blips are ignored.
            if (status === 401 || status === 403) goToEntry(role, { reason: 'session' });
        } finally {
            checking = false;
        }
    }

    const onVisibilityChange = () => {
        if (document.visibilityState === 'visible') void verify();
    };

    onMounted(() => {
        document.addEventListener('visibilitychange', onVisibilityChange);
        window.addEventListener('focus', verify);
    });

    onBeforeUnmount(() => {
        document.removeEventListener('visibilitychange', onVisibilityChange);
        window.removeEventListener('focus', verify);
    });
}
