<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A branch Admin's "I paid FitGym" proof, waiting for the Super Admin. Not gym-scoped
 * (no BelongsToGym trait): the controllers filter by gym themselves.
 */
class SubscriptionPaymentRequest extends Model
{
    public const METHODS = ['GCash', 'Bank Transfer'];

    protected $fillable = [
        'gym_id',
        'plan',
        'months',
        'amount',
        'payment_method',
        'reference',
        'receipt_path',
        'notes',
        'status',
        'review_note',
        'submitted_by',
        'reviewed_by',
        'reviewed_at',
        'subscription_payment_id',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'reviewed_at' => 'datetime',
    ];

    public function receiptUrl(): ?string
    {
        return $this->receipt_path ? '/storage/'.ltrim($this->receipt_path, '/') : null;
    }
}
