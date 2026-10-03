<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gym_classes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('gym_id')->constrained('gyms')->onDelete('cascade');
            $table->string('name');
            $table->foreignId('trainer_id')->nullable()->constrained('trainers')->onDelete('set null');
            $table->string('day_of_week', 20);
            $table->time('start_time');
            $table->time('end_time');
            $table->unsignedInteger('capacity')->default(10);
            $table->tinyInteger('is_archived')->default(0);
            $table->timestamps();
            $table->index('day_of_week');
            $table->index('is_archived');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gym_classes');
    }
};
