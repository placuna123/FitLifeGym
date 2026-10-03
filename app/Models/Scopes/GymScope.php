<?php

namespace App\Models\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Support\Facades\Auth;

/**
 * Restricts every tenant-scoped model to the current user's gym.
 *
 * SuperAdmin sessions (gym_id === null, role === 'SuperAdmin') see everything —
 * the platform layer is intentionally unscoped. Anyone else with no resolvable
 * gym_id fails CLOSED (an impossible id), never fails OPEN: a bug that leaves
 * gym_id unset must never leak one gym's data into another gym's session.
 */
class GymScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $user = Auth::user();

        if ($user && ($user->role ?? null) === 'SuperAdmin') {
            return;
        }

        $gymId = $user->gym_id ?? null;

        $builder->where($model->getTable() . '.gym_id', $gymId ?? 0);
    }
}
