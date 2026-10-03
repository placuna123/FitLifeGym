<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('plan_features')) {
            return;
        }
        Schema::create('plan_features', function (Blueprint $table) {
            $table->id();
            $table->foreignId('membership_plan_id')->constrained('membership_plans')->onDelete('cascade');
            $table->string('feature_name');
            $table->tinyInteger('is_archived')->default(0);
            $table->timestamps();
            $table->index('membership_plan_id');
            $table->index('is_archived');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plan_features');
    }
};
