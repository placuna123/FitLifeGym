<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Gym extends Model
{
    protected $fillable = [
        'name',
        'slug',
        'email',
        'phone',
        'address',
        'operating_hours',
        'gcash_number',
        'gcash_account_name',
        'gcash_qr_path',
        'textbee_device_id',
        'textbee_sim_subscription_id',
        'owner_user_id',
        'subscription_plan',
        'subscription_status',
        'subscription_expires_at',
        'is_archived',
    ];

    protected $casts = [
        'operating_hours' => 'array',
        'subscription_expires_at' => 'date',
        'is_archived' => 'boolean',
    ];

    /** Statuses whose branch may keep using FitGym. */
    private const ACCESS_STATUSES = ['trial', 'active', 'pending'];

    /**
     * The status that actually applies today: a trial or subscription whose end
     * date has passed counts as expired even if nobody has flipped the status yet.
     */
    public function effectiveStatus(): string
    {
        $status = (string) ($this->subscription_status ?: 'active');

        if (in_array($status, self::ACCESS_STATUSES, true)
            && $this->subscription_expires_at
            && $this->subscription_expires_at->copy()->endOfDay()->isPast()) {
            return 'expired';
        }

        return $status;
    }

    public function allowsAccess(): bool
    {
        return ! $this->is_archived && in_array($this->effectiveStatus(), self::ACCESS_STATUSES, true);
    }

    public function accessDeniedMessage(): string
    {
        return match ($this->effectiveStatus()) {
            'suspended' => "{$this->name} is currently suspended on FitGym. Please contact the platform administrator.",
            'cancelled' => "{$this->name}'s FitGym subscription has been cancelled. Please contact the platform administrator.",
            'expired' => "{$this->name}'s FitGym trial or subscription has expired. Please contact the platform administrator to renew.",
            default => "{$this->name} is not available right now. Please contact the platform administrator.",
        };
    }

    /**
     * Member/staff caps for the branch's FitGym plan (null = unlimited), from config/fitgym.php.
     */
    public function planLimit(string $resource): ?int
    {
        $plans = config('fitgym.plans', []);
        $plan = $plans[$this->subscription_plan ?: 'trial'] ?? $plans['trial'] ?? [];
        $limit = $plan[$resource] ?? null;

        return $limit === null ? null : (int) $limit;
    }

    /** Active (non-archived) members or staff in this branch. */
    public function activeCount(string $resource): int
    {
        $model = $resource === 'staff' ? Trainer::class : Member::class;

        return $model::withoutGlobalScopes()->where('gym_id', $this->id)->where('is_archived', 0)->count();
    }

    public function hasRoomFor(string $resource): bool
    {
        $limit = $this->planLimit($resource);

        return $limit === null || $this->activeCount($resource) < $limit;
    }

    public function limitReachedMessage(string $resource): string
    {
        $plan = config("fitgym.plans.{$this->subscription_plan}.label", ucfirst((string) $this->subscription_plan));
        $noun = $resource === 'staff' ? 'staff accounts' : 'members';

        return "This branch has reached its limit of {$this->planLimit($resource)} {$noun} on the {$plan} plan. Ask the platform administrator to upgrade the plan.";
    }

    public static function forCurrentUser(): ?self
    {
        $gymId = \App\Support\Tenancy::gymId();

        return $gymId ? self::find($gymId) : null;
    }
}
