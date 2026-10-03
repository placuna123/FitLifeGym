<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('members', function (Blueprint $table) {
            $table->decimal('height_cm', 5, 1)->nullable()->after('address');
            $table->decimal('goal_weight_kg', 5, 1)->nullable()->after('height_cm');
        });
    }

    public function down(): void
    {
        Schema::table('members', function (Blueprint $table) {
            $table->dropColumn(['height_cm', 'goal_weight_kg']);
        });
    }
};
