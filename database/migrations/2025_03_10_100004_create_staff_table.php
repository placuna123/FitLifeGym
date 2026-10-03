<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('staff')) {
            return;
        }
        Schema::create('staff', function (Blueprint $table) {
            $table->id();
            $table->string('staff_id', 50)->unique();
            $table->string('full_name');
            $table->string('email', 191)->unique();
            $table->string('phone', 50)->nullable();
            $table->string('position', 100)->nullable();
            $table->string('department', 100)->nullable();
            $table->date('hire_date')->nullable();
            $table->string('status', 20)->default('Active');
            $table->tinyInteger('is_archived')->default(0);
            $table->timestamps();
            $table->index('staff_id');
            $table->index('email');
            $table->index('status');
            $table->index('is_archived');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff');
    }
};
