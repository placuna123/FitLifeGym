<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('member_weight_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('gym_id')->constrained('gyms')->onDelete('cascade');
            $table->foreignId('member_id')->constrained('members')->onDelete('cascade');
            $table->decimal('weight_kg', 5, 1);
            $table->date('logged_at');
            $table->timestamps();
            // One entry per member per day — logging again the same day updates it.
            $table->unique(['member_id', 'logged_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('member_weight_logs');
    }
};
