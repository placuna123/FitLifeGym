<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Admin profiles already offered phone/address and member profiles a fitness
     * goal, but there were no columns to store them, so edits were silently lost.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('phone', 50)->nullable()->after('email');
            $table->string('address', 500)->nullable()->after('phone');
        });

        Schema::table('members', function (Blueprint $table) {
            $table->string('fitness_goal', 50)->nullable()->after('goal_weight_kg');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['phone', 'address']);
        });

        Schema::table('members', function (Blueprint $table) {
            $table->dropColumn('fitness_goal');
        });
    }
};
