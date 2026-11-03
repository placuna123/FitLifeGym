<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Deliberately NOT gym-scoped (no BelongsToGym trait): a Super Admin needs
 * to see every gym's activity, and a log entry must remain readable even
 * after the gym it happened in is gone.
 */
class AuditLog extends Model
{
    protected $fillable = [
        'gym_id',
        'user_id',
        'user_name',
        'role',
        'action',
        'subject_type',
        'subject_id',
        'description',
        'ip_address',
    ];

    public static function record(string $action, ?string $description = null, array $subject = []): void
    {
        $user = auth()->user();

        // Logging is a side effect: a broken audit table must never stop someone
        // from signing in or saving their work, so the failure is only reported.
        try {
            static::create([
                'gym_id' => $user->gym_id ?? null,
                'user_id' => $user->id ?? null,
                'user_name' => $user->name ?? null,
                'role' => $user->role ?? null,
                'action' => $action,
                'subject_type' => $subject['type'] ?? null,
                'subject_id' => $subject['id'] ?? null,
                'description' => $description,
                'ip_address' => request()?->ip(),
            ]);
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
