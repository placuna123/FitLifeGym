<?php

namespace App\Support;

/**
 * Members who self-register store their address as JSON that also carries the
 * emergency contact and date of birth: {"address": "...", "emergency_contact": {...}}.
 * Older and staff-added members store a plain string. Mirrors resources/js/lib/memberAddress.ts.
 */
class MemberAddress
{
    /** The street address alone ('' when there is none). */
    public static function street(?string $address): string
    {
        $address = trim((string) $address);
        if (! str_starts_with($address, '{')) {
            return $address;
        }

        $meta = json_decode($address, true);

        return is_array($meta) ? trim((string) ($meta['address'] ?? '')) : $address;
    }
}
