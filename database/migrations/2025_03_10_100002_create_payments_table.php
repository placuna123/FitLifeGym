<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('payments')) {
            return;
        }
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->string('invoice_number', 100)->unique();
            $table->foreignId('member_id')->constrained('members')->onDelete('restrict');
            $table->foreignId('membership_plan_id')->nullable()->constrained('membership_plans')->onDelete('set null');
            $table->decimal('amount', 10, 2);
            $table->string('payment_method', 50)->default('Cash');
            $table->date('payment_date');
            $table->string('status', 20)->default('Paid');
            $table->text('notes')->nullable();
            $table->tinyInteger('is_archived')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
