<?php

namespace App\Models;

use App\Models\Concerns\BelongsToGym;
use Illuminate\Database\Eloquent\Model;

class Trainer extends Model
{
    use BelongsToGym;

    protected $table = 'trainers';

    protected $fillable = [
        'gym_id',
        'staff_id',
        'full_name',
        'email',
        'phone',
        'position',
        'department',
        'hire_date',
        'status',
        'is_archived',
    ];

    protected $casts = [
        'hire_date' => 'date',
        'is_archived' => 'integer',
    ];

    public function getInitialsAttribute(): string
    {
        $names = explode(' ', $this->full_name);
        if (count($names) >= 2) {
            return strtoupper(substr($names[0], 0, 1) . substr($names[count($names) - 1], 0, 1));
        }
        return strtoupper(substr($this->full_name, 0, 2));
    }
}
