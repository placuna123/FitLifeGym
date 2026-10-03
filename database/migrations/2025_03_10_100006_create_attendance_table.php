<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('attendance')) {
            return;
        }
        Schema::create('attendance', function (Blueprint $table) {
            $table->id();
            $table->foreignId('member_id')->constrained('members')->onDelete('restrict');
            $table->dateTime('check_in_time');
            $table->dateTime('check_out_time')->nullable();
            $table->integer('duration_minutes')->nullable();
            $table->tinyInteger('is_archived')->default(0);
            $table->timestamps();
            $table->index('member_id');
            $table->index('check_in_time');
            $table->index('is_archived');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance');
    }
};
