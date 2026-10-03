<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Trials now end. Branches already on a trial without an end date get a
     * fresh trial from today, so none of them is locked out the moment this ships.
     */
    public function up(): void
    {
        DB::table('gyms')
            ->where('subscription_status', 'trial')
            ->whereNull('subscription_expires_at')
            ->update(['subscription_expires_at' => now()->addDays((int) config('fitgym.trial_days', 14))->toDateString()]);
    }

    public function down(): void
    {
        // Leave end dates in place: removing them would silently extend trials forever.
    }
};
