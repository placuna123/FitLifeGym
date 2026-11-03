<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * What each gym branch pays FitGym for its subscription (the platform's own
     * income), recorded by the Super Admin. Not branch-scoped: only the platform sees it.
     */
    public function up(): void
    {
        Schema::create('subscription_payments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('gym_id')->index();
            $table->string('plan', 30);
            $table->unsignedSmallInteger('months');
            $table->decimal('amount', 10, 2);
            $table->string('payment_method', 30);
            $table->string('reference', 100)->nullable();
            $table->date('paid_on');
            $table->date('period_start');
            $table->date('period_end');
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('recorded_by')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_payments');
    }
};
