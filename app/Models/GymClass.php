<?php

namespace App\Models;

use App\Models\Concerns\BelongsToGym;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GymClass extends Model
{
    use BelongsToGym;

    protected $fillable = [
        'gym_id',
        'name',
        'trainer_id',
        'day_of_week',
        'start_time',
        'end_time',
        'capacity',
        'is_archived',
    ];

    protected $casts = [
        'capacity' => 'integer',
        'is_archived' => 'boolean',
    ];

    public function trainer(): BelongsTo
    {
        return $this->belongsTo(Trainer::class);
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(ClassBooking::class);
    }
}
