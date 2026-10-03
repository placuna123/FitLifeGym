<?php

namespace App\Models;

use App\Models\Concerns\BelongsToGym;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClassBooking extends Model
{
    use BelongsToGym;

    protected $fillable = [
        'gym_id',
        'gym_class_id',
        'member_id',
        'class_date',
        'status',
    ];

    protected $casts = [
        'class_date' => 'date:Y-m-d',
    ];

    public function gymClass(): BelongsTo
    {
        return $this->belongsTo(GymClass::class);
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }
}
