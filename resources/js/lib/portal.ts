/**
 * Shared bits for the unified FitGym entry page (pages/fitgym/Portal.vue)
 * and the three role portals that hand control back to it.
 */

export type PortalRole = 'admin' | 'trainer' | 'member';

export const PORTAL_ROLES: readonly PortalRole[] = ['admin', 'trainer', 'member'] as const;

/** Remembers the last role used on this browser so the entry page pre-selects it. */
export const LAST_ROLE_STORAGE_KEY = 'fitgym:last-role';

export function isPortalRole(value: unknown): value is PortalRole {
    return typeof value === 'string' && (PORTAL_ROLES as readonly string[]).includes(value.toLowerCase());
}

/** Normalizes anything role-ish ("Trainer", "trainer", "TRAINER") to a PortalRole. */
export function toPortalRole(value: unknown): PortalRole | null {
    if (typeof value !== 'string') return null;
    const normalized = value.trim().toLowerCase();
    return isPortalRole(normalized) ? (normalized as PortalRole) : null;
}

/** App root, honouring an install that is not served from the domain root. */
export function appBaseUrl(): string {
    const meta = document.head.querySelector<HTMLMetaElement>('meta[name="app-url"]');
    return (meta?.content || window.location.origin).replace(/\/+$/, '');
}

// The trainer portal is served at /fitgym/staff (see routes/web.php).
const PORTAL_PATHS: Record<PortalRole, string> = {
    admin: 'admin',
    trainer: 'staff',
    member: 'member',
};

export function portalUrl(role: PortalRole): string {
    return `${appBaseUrl()}/fitgym/${PORTAL_PATHS[role]}`;
}

export function entryUrl(params: Record<string, string> = {}): string {
    const query = new URLSearchParams(params).toString();
    return `${appBaseUrl()}/${query ? `?${query}` : ''}`;
}

export function rememberRole(role: PortalRole): void {
    try {
        localStorage.setItem(LAST_ROLE_STORAGE_KEY, role);
    } catch {
        // Private mode / blocked storage: pre-selection is a convenience, not a requirement.
    }
}

export function recallRole(): PortalRole | null {
    try {
        return toPortalRole(localStorage.getItem(LAST_ROLE_STORAGE_KEY));
    } catch {
        return null;
    }
}

/**
 * Send an unauthenticated visitor back to the single sign-in page, with their
 * role pre-selected. `replace` keeps the portal URL out of the back-button
 * history so Back doesn't bounce them straight out again.
 */
export function goToEntry(role: PortalRole, params: Record<string, string> = {}): void {
    window.location.replace(entryUrl({ role, ...params }));
}
