<?php

namespace App\Models;

use App\Models\Concerns\BelongsToGym;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Member extends Model
{
    use BelongsToGym;

    protected $fillable = [
        'gym_id',
        'member_id',
        'full_name',
        'email',
        'phone',
        'address',
        'membership_plan_id',
        'status',
        'expiry_date',
        'payment_status',
        'height_cm',
        'goal_weight_kg',
        'fitness_goal',
        'is_archived',
    ];

    protected $casts = [
        'expiry_date' => 'date:Y-m-d',
        'height_cm' => 'decimal:1',
        'goal_weight_kg' => 'decimal:1',
        'is_archived' => 'boolean',
    ];

    /**
     * Why this member may not check in at the gym right now, or null when they may.
     * Entry is by QR, so this is what keeps someone who hasn't paid (or whose paid
     * time ran out) from getting in. Checking out is never blocked.
     */
    public function checkInBlockReason(): ?string
    {
        if ($this->status === 'Pending') {
            return 'This account is still awaiting approval from the gym staff.';
        }

        if ($this->payment_status !== 'Paid' || ! $this->expiry_date) {
            return 'No paid membership yet. Pay for a plan first (GCash, or cash at the front desk), then check in.';
        }

        if ($this->expiry_date->copy()->endOfDay()->isPast()) {
            return 'Membership expired on '.$this->expiry_date->format('M d, Y').'. Renew the membership first, then check in.';
        }

        return null;
    }

    public function membershipPlan(): BelongsTo
    {
        return $this->belongsTo(MembershipPlan::class);
    }

    public function weightLogs(): HasMany
    {
        return $this->hasMany(MemberWeightLog::class)->orderBy('logged_at');
    }
}
