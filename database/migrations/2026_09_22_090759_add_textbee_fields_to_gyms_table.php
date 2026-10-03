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
        Schema::table('gyms', function (Blueprint $table) {
            $table->string('textbee_device_id')->nullable()->after('gcash_qr_path');
            $table->unsignedInteger('textbee_sim_subscription_id')->nullable()->after('textbee_device_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('gyms', function (Blueprint $table) {
            $table->dropColumn(['textbee_device_id', 'textbee_sim_subscription_id']);
        });
    }
};
