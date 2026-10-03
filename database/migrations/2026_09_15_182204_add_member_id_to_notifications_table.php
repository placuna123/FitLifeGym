<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            // Null = gym-wide/admin notification (existing behavior).
            // Set = a notification meant for that one member only.
            $table->foreignId('member_id')->nullable()->after('id')->constrained('members')->onDelete('cascade');
            $table->index('member_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            $table->dropForeign(['member_id']);
            $table->dropColumn('member_id');
        });
    }
};
