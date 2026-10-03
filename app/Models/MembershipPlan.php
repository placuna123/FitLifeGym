<?php

namespace App\Models;

use App\Models\Concerns\BelongsToGym;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MembershipPlan extends Model
{
    use BelongsToGym;

    protected $table = 'membership_plans';

    protected $fillable = [
        'gym_id',
        'name',
        'type',
        'price',
        'period',
        'description',
        'is_popular',
        'is_deactivated',
        'member_count',
        'monthly_revenue',
        'is_archived',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'monthly_revenue' => 'decimal:2',
        'is_popular' => 'boolean',
        'is_deactivated' => 'boolean',
        'is_archived' => 'boolean',
    ];

    public function members(): HasMany
    {
        return $this->hasMany(Member::class);
    }

    public function planFeatures(): HasMany
    {
        return $this->hasMany(PlanFeature::class);
    }
}
