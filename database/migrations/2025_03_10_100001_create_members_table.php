<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('members')) {
            return;
        }
        Schema::create('members', function (Blueprint $table) {
            $table->id();
            $table->string('member_id', 50)->unique();
            $table->string('full_name');
            $table->string('email', 191)->unique();
            $table->string('phone', 50);
            $table->text('address')->nullable();
            $table->foreignId('membership_plan_id')->nullable()->constrained('membership_plans')->onDelete('set null');
            $table->string('status', 20)->default('Active');
            $table->date('expiry_date')->nullable();
            $table->string('payment_status', 20)->default('Paid');
            $table->tinyInteger('is_archived')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('members');
    }
};
