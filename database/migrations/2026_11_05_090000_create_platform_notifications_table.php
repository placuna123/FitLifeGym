<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The Super Admin's notification bell: a new branch signed up, a branch sent a
 * payment receipt, a branch's trial or subscription is about to end.
 * Separate from `notifications`, which belongs to one gym (GymScope).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('platform_notifications')) {
            return;
        }
        Schema::create('platform_notifications', function (Blueprint $table) {
            $table->id();
            $table->string('type', 20); // signup | payment | expiry
            $table->string('title');
            $table->text('message');
            $table->foreignId('gym_id')->nullable()->constrained('gyms')->nullOnDelete();
            // the Super Admin tab that opens when the notification is clicked
            $table->string('tab', 20)->nullable();
            // stops the same reminder being made twice (e.g. "expiry:12:2026-11-10")
            $table->string('dedupe_key')->nullable()->unique();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
            $table->index('read_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_notifications');
    }
};
