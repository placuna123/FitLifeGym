<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Key/value settings for the whole platform (not per branch), edited by the Super Admin.
 */
class PlatformSetting extends Model
{
    protected $fillable = ['key', 'value'];

    /** Where branches pay FitGym; shown on every branch Admin's Subscription page. */
    public const PAYMENT_KEYS = ['payment_gcash_number', 'payment_gcash_name', 'payment_bank_details', 'payment_instructions'];

    public static function getValue(string $key, ?string $default = null): ?string
    {
        return static::where('key', $key)->value('value') ?? $default;
    }

    public static function setValue(string $key, ?string $value): void
    {
        static::updateOrCreate(['key' => $key], ['value' => $value]);
    }

    /** @return array<string, string> */
    public static function paymentDetails(): array
    {
        $saved = static::whereIn('key', self::PAYMENT_KEYS)->pluck('value', 'key');

        return [
            'gcash_number' => (string) ($saved['payment_gcash_number'] ?? ''),
            'gcash_name' => (string) ($saved['payment_gcash_name'] ?? ''),
            'bank_details' => (string) ($saved['payment_bank_details'] ?? ''),
            'instructions' => (string) ($saved['payment_instructions'] ?? ''),
        ];
    }
}
