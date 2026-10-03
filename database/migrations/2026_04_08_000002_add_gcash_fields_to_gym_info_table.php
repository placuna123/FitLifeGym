<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('gym_info')) {
            return;
        }

        Schema::table('gym_info', function (Blueprint $table) {
            if (!Schema::hasColumn('gym_info', 'gcash_number')) {
                $table->string('gcash_number', 20)->nullable()->after('phone');
            }
            if (!Schema::hasColumn('gym_info', 'gcash_account_name')) {
                $table->string('gcash_account_name')->nullable()->after('gcash_number');
            }
            if (!Schema::hasColumn('gym_info', 'gcash_qr_path')) {
                $table->string('gcash_qr_path')->nullable()->after('gcash_account_name');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('gym_info')) {
            return;
        }

        Schema::table('gym_info', function (Blueprint $table) {
            if (Schema::hasColumn('gym_info', 'gcash_qr_path')) {
                $table->dropColumn('gcash_qr_path');
            }
            if (Schema::hasColumn('gym_info', 'gcash_account_name')) {
                $table->dropColumn('gcash_account_name');
            }
            if (Schema::hasColumn('gym_info', 'gcash_number')) {
                $table->dropColumn('gcash_number');
            }
        });
    }
};

