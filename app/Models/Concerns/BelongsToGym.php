<?php

namespace App\Models\Concerns;

use App\Models\Gym;
use App\Models\Scopes\GymScope;
use App\Support\Tenancy;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Marks a model as tenant-scoped data belonging to one Gym.
 *
 * Applying this trait is almost the entire multi-tenancy story for a model:
 * every query (where/get/count/sum/whereHas/...) is automatically filtered to
 * the current gym via GymScope, and a new record's gym_id is filled in
 * automatically on create if the caller didn't set one explicitly.
 */
trait BelongsToGym
{
    protected static function bootBelongsToGym(): void
    {
        static::addGlobalScope(new GymScope());

        static::creating(function ($model) {
            if (empty($model->gym_id)) {
                $model->gym_id = Tenancy::gymId();
            }
        });
    }

    public function gym(): BelongsTo
    {
        return $this->belongsTo(Gym::class);
    }
}
