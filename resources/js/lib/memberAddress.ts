// Members who self-register store their address as JSON that also carries the
// emergency contact and date of birth: {"address": "...", "emergency_contact": {...}, ...}.
// Older or staff-added members store a plain string. These helpers handle both.

export function parseMemberAddressMeta(address: unknown): Record<string, unknown> | null {
    if (typeof address !== 'string') return null;
    const trimmed = address.trim();
    if (!trimmed.startsWith('{')) return null;

    try {
        const parsed = JSON.parse(trimmed);
        return parsed && typeof parsed === 'object' ? (parsed as Record<string, unknown>) : null;
    } catch {
        return null;
    }
}

/** The street address alone, for showing or editing ('' when there is none). */
export function memberStreetAddress(address: unknown): string {
    if (typeof address !== 'string') return '';
    const meta = parseMemberAddressMeta(address);
    if (!meta) return address.trim();
    return String(meta.address || '').trim();
}

/** Puts an edited street address back, keeping the emergency contact and birthday when present. */
export function mergeMemberStreetAddress(original: unknown, street: string): string {
    const meta = parseMemberAddressMeta(original);
    if (!meta) return street;
    return JSON.stringify({ ...meta, address: street });
}
