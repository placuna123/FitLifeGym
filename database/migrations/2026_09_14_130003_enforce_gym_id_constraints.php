<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Tables that must have a gym_id on every row (users excluded — SuperAdmin rows stay null). */
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
        // 1) Now that every row has a gym_id, make it required (except on users).
        foreach (self::TENANT_TABLES as $table) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'gym_id')) {
                Schema::table($table, function (Blueprint $blueprint) {
                    $blueprint->unsignedBigInteger('gym_id')->nullable(false)->change();
                    $blueprint->foreign('gym_id')->references('id')->on('gyms')->onDelete('cascade');
                });
            }
        }

        // 2) Replace globally-unique business keys with gym-scoped composite uniques.
        Schema::table('members', function (Blueprint $table) {
            $table->dropUnique('members_member_id_unique');
            $table->dropUnique('members_email_unique');
            $table->unique(['gym_id', 'member_id']);
            $table->unique(['gym_id', 'email']);
        });

        Schema::table('trainers', function (Blueprint $table) {
            $table->dropUnique('staff_staff_id_unique');
            $table->dropUnique('staff_email_unique');
            $table->unique(['gym_id', 'staff_id']);
            $table->unique(['gym_id', 'email']);
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->dropUnique('payments_invoice_number_unique');
            $table->unique(['gym_id', 'invoice_number']);
        });

        Schema::table('membership_plans', function (Blueprint $table) {
            $table->unique(['gym_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::table('members', function (Blueprint $table) {
            $table->dropUnique(['gym_id', 'member_id']);
            $table->dropUnique(['gym_id', 'email']);
            $table->unique('member_id');
            $table->unique('email');
        });

        Schema::table('trainers', function (Blueprint $table) {
            $table->dropUnique(['gym_id', 'staff_id']);
            $table->dropUnique(['gym_id', 'email']);
            $table->unique('staff_id');
            $table->unique('email');
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->dropUnique(['gym_id', 'invoice_number']);
            $table->unique('invoice_number');
        });

        Schema::table('membership_plans', function (Blueprint $table) {
            $table->dropUnique(['gym_id', 'name']);
        });

        foreach (self::TENANT_TABLES as $table) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'gym_id')) {
                Schema::table($table, function (Blueprint $blueprint) {
                    $blueprint->dropForeign(['gym_id']);
                    $blueprint->unsignedBigInteger('gym_id')->nullable()->change();
                });
            }
        }
    }
};
