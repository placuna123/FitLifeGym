<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A notification for the Super Admin. Deliberately NOT gym-scoped (no
 * BelongsToGym trait): it is about the platform, and only role:SuperAdmin
 * routes read it.
 */
class PlatformNotification extends Model
{
    protected $fillable = [
        'type',
        'title',
        'message',
        'gym_id',
        'tab',
        'dedupe_key',
        'read_at',
    ];

    protected $casts = [
        'read_at' => 'datetime',
    ];

    public function gym(): BelongsTo
    {
        return $this->belongsTo(Gym::class);
    }

    /**
     * Add a notification for the Super Admin. With a $dedupeKey, a second call
     * with the same key does nothing. Never throws: a failed notification must
     * not break the signup or payment that triggered it.
     */
    public static function notify(string $type, string $title, string $message, ?int $gymId = null, ?string $tab = null, ?string $dedupeKey = null): void
    {
        try {
            $attributes = [
                'type' => $type,
                'title' => $title,
                'message' => $message,
                'gym_id' => $gymId,
                'tab' => $tab,
            ];

            if ($dedupeKey !== null) {
                static::firstOrCreate(['dedupe_key' => $dedupeKey], $attributes);
            } else {
                static::create($attributes);
            }
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
