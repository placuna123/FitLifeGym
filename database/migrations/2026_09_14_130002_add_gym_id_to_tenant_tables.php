<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Tables that belong to exactly one gym. `users` is handled separately (see up()). */
    private const TENANT_TABLES = [
        'members',
        'trainers',
        'membership_plans',
        'payments',
        'plan_features',
        'trainer_schedules',
        'attendance',
        'trainer_attendance',
        'notifications',
        'reports',
    ];

    public function up(): void
    {
        foreach (self::TENANT_TABLES as $table) {
            if (Schema::hasTable($table) && ! Schema::hasColumn($table, 'gym_id')) {
                Schema::table($table, function (Blueprint $blueprint) {
                    $blueprint->unsignedBigInteger('gym_id')->nullable()->after('id');
                    $blueprint->index('gym_id');
                });
            }
        }

        if (Schema::hasTable('users') && ! Schema::hasColumn('users', 'gym_id')) {
            Schema::table('users', function (Blueprint $blueprint) {
                $blueprint->unsignedBigInteger('gym_id')->nullable()->after('id');
                $blueprint->index('gym_id');
            });
        }

        // Backfill: every row that exists today belongs to the one gym created
        // in the previous migration (the demo data predates multi-tenancy).
        $defaultGymId = DB::table('gyms')->orderBy('id')->value('id');

        if ($defaultGymId) {
            foreach (self::TENANT_TABLES as $table) {
                if (Schema::hasTable($table)) {
                    DB::table($table)->whereNull('gym_id')->update(['gym_id' => $defaultGymId]);
                }
            }

            // Every existing user (Admin/Trainer/Member alike) becomes part of the
            // default gym too; a SuperAdmin seeded later will have gym_id left null.
            DB::table('users')->whereNull('gym_id')->update(['gym_id' => $defaultGymId]);

            DB::table('gyms')->where('id', $defaultGymId)->update([
                'owner_user_id' => DB::table('users')->where('role', 'Admin')->orderBy('id')->value('id'),
            ]);
        }
    }

    public function down(): void
    {
        foreach ([...self::TENANT_TABLES, 'users'] as $table) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'gym_id')) {
                Schema::table($table, function (Blueprint $blueprint) {
                    $blueprint->dropColumn('gym_id');
                });
            }
        }
    }
};
