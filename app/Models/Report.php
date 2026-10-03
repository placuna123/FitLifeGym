<?php

namespace App\Models;

use App\Models\Concerns\BelongsToGym;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Report extends Model
{
    use BelongsToGym;

    protected $fillable = [
        'gym_id',
        'report_type',
        'report_name',
        'generated_by',
        'date_from',
        'date_to',
        'file_path',
        'data',
        'is_archived',
    ];

    protected $casts = [
        'date_from' => 'date',
        'date_to' => 'date',
        'data' => 'array',
        'is_archived' => 'boolean',
    ];

    public function generatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generated_by');
    }
}
