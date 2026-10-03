<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('staff_attendance')) {
            return;
        }
        Schema::create('staff_attendance', function (Blueprint $table) {
            $table->id();
            $table->foreignId('staff_id')->constrained('staff')->onDelete('restrict');
            $table->date('attendance_date');
            $table->dateTime('check_in_time')->nullable();
            $table->dateTime('check_out_time')->nullable();
            $table->string('status', 20)->default('Present');
            $table->tinyInteger('is_archived')->default(0);
            $table->timestamps();
            $table->index('staff_id');
            $table->index('attendance_date');
            $table->index('is_archived');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_attendance');
    }
};
