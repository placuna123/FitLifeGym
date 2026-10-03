<?php

namespace App\Models;

use App\Models\Concerns\BelongsToGym;
use Illuminate\Database\Eloquent\Model;

class Notification extends Model
{
    use BelongsToGym;

    protected $fillable = [
        'gym_id',
        'member_id',
        'title',
        'message',
        'type',
        'is_read',
        'read_at',
        'is_archived',
    ];

    public function member(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    protected $casts = [
        'is_read' => 'boolean',
        'read_at' => 'datetime',
        'is_archived' => 'boolean',
    ];
}
