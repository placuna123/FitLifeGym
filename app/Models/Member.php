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

    public function membershipPlan(): BelongsTo
    {
        return $this->belongsTo(MembershipPlan::class);
    }

    public function weightLogs(): HasMany
    {
        return $this->hasMany(MemberWeightLog::class)->orderBy('logged_at');
    }
}
