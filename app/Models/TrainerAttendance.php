<?php

namespace App\Models;

use App\Models\Concerns\BelongsToGym;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TrainerAttendance extends Model
{
    use BelongsToGym;

    protected $table = 'trainer_attendance';

    protected $fillable = [
        'gym_id',
        'staff_id',
        'attendance_date',
        'check_in_time',
        'check_out_time',
        'status',
        'is_archived',
    ];

    protected $casts = [
        'attendance_date' => 'date',
        'check_in_time' => 'datetime',
        'check_out_time' => 'datetime',
        'is_archived' => 'boolean',
    ];

    public function trainer(): BelongsTo
    {
        return $this->belongsTo(Trainer::class, 'staff_id');
    }
}
