<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('staff_schedules')) {
            return;
        }
        Schema::create('staff_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('staff_id')->constrained('staff')->onDelete('cascade');
            $table->string('day_of_week', 20);
            $table->time('start_time');
            $table->time('end_time');
            $table->tinyInteger('is_archived')->default(0);
            $table->timestamps();
            $table->index('staff_id');
            $table->index('is_archived');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_schedules');
    }
};
