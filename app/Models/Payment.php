<?php

namespace App\Models;

use App\Models\Concerns\BelongsToGym;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payment extends Model
{
    use BelongsToGym;

    protected $fillable = [
        'gym_id',
        'invoice_number',
        'member_id',
        'membership_plan_id',
        'amount',
        'payment_method',
        'payment_date',
        'status',
        'notes',
        'receipt_proof_path',
        'is_archived',
    ];

    protected $casts = [
        'payment_date' => 'date',
        'amount' => 'decimal:2',
        'is_archived' => 'boolean',
    ];

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    public function membershipPlan(): BelongsTo
    {
        return $this->belongsTo(MembershipPlan::class);
    }
}
