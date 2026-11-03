<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Sign-up used to give a member a month of membership before they paid, so a
     * payment approved later was added on top of that free month. Members who have
     * never had a payment approved lose that unpaid expiry; their membership now
     * starts on the day their first payment is approved.
     */
    public function up(): void
    {
        DB::table('members')
            ->where('payment_status', '!=', 'Paid')
            ->whereNotNull('expiry_date')
            ->whereNotExists(function ($query) {
                $query->select(DB::raw(1))
                    ->from('payments')
                    ->whereColumn('payments.member_id', 'members.id')
                    ->where('payments.status', 'Paid');
            })
            ->update(['expiry_date' => null]);
    }

    public function down(): void
    {
        // The cleared dates were never paid for, so there is nothing to restore.
    }
};
