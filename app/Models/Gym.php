<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Gym extends Model
{
    protected $fillable = [
        'name',
        'slug',
        'email',
        'phone',
        'address',
        'operating_hours',
        'gcash_number',
        'gcash_account_name',
        'gcash_qr_path',
        'textbee_device_id',
        'textbee_sim_subscription_id',
        'owner_user_id',
        'subscription_plan',
        'subscription_status',
        'subscription_expires_at',
        'is_archived',
    ];

    protected $casts = [
        'operating_hours' => 'array',
        'subscription_expires_at' => 'date',
        'is_archived' => 'boolean',
    ];
}
