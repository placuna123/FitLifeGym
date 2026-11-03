<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Proof of payment a branch Admin sends FitGym (GCash or bank receipt). The Super
     * Admin approves it, which records a subscription payment, or rejects it with a reason.
     */
    public function up(): void
    {
        Schema::create('subscription_payment_requests', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('gym_id')->index();
            $table->string('plan', 30);
            $table->unsignedSmallInteger('months');
            $table->decimal('amount', 10, 2);
            $table->string('payment_method', 30);
            $table->string('reference', 100)->nullable();
            $table->string('receipt_path')->nullable();
            $table->text('notes')->nullable();
            $table->string('status', 20)->default('Pending')->index();
            $table->string('review_note', 500)->nullable();
            $table->unsignedBigInteger('submitted_by')->nullable();
            $table->unsignedBigInteger('reviewed_by')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->unsignedBigInteger('subscription_payment_id')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_payment_requests');
    }
};
