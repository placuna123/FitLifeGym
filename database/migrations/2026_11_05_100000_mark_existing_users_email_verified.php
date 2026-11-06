<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Gym owners who sign up on the website now have to confirm their email before
 * they can sign in. Accounts made before that rule are trusted as they are, so
 * nobody already using FitGym gets locked out.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('users')->whereNull('email_verified_at')->update(['email_verified_at' => now()]);
    }

    public function down(): void
    {
        // Nothing to undo: which accounts were unverified before is not kept.
    }
};
