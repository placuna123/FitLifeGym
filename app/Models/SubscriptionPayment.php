<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A branch's payment to FitGym for its subscription. Deliberately NOT gym-scoped
 * (no BelongsToGym trait): only the Super Admin reads or writes these.
 */
class SubscriptionPayment extends Model
{
    protected $fillable = [
        'gym_id',
        'plan',
        'months',
        'amount',
        'payment_method',
        'reference',
        'paid_on',
        'period_start',
        'period_end',
        'notes',
        'recorded_by',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'paid_on' => 'date',
        'period_start' => 'date',
        'period_end' => 'date',
    ];

    public function gym(): BelongsTo
    {
        return $this->belongsTo(Gym::class);
    }
}
