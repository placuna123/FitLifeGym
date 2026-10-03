<?php

namespace App\Models;

use App\Models\Concerns\BelongsToGym;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TrainerSchedule extends Model
{
    use BelongsToGym;

    protected $table = 'trainer_schedules';

    protected $fillable = [
        'gym_id',
        'staff_id',
        'day_of_week',
        'start_time',
        'end_time',
        'is_archived',
    ];

    protected $casts = [
        'is_archived' => 'boolean',
    ];

    public function trainer(): BelongsTo
    {
        return $this->belongsTo(Trainer::class, 'staff_id');
    }
}
