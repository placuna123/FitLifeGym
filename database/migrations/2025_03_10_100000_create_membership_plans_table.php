<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('membership_plans')) {
            return;
        }
        Schema::create('membership_plans', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('type', 20)->default('Monthly');
            $table->decimal('price', 10, 2)->default(0);
            $table->string('period', 100)->nullable();
            $table->text('description')->nullable();
            $table->tinyInteger('is_popular')->default(0);
            $table->tinyInteger('is_deactivated')->default(0);
            $table->integer('member_count')->default(0);
            $table->decimal('monthly_revenue', 10, 2)->default(0);
            $table->tinyInteger('is_archived')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('membership_plans');
    }
};
