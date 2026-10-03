<?php

namespace App\Models;

use App\Models\Concerns\BelongsToGym;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MemberWeightLog extends Model
{
    use BelongsToGym;

    protected $fillable = [
        'gym_id',
        'member_id',
        'weight_kg',
        'logged_at',
    ];

    protected $casts = [
        'weight_kg' => 'decimal:1',
        'logged_at' => 'date:Y-m-d',
    ];

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }
}
