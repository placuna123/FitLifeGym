<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('class_bookings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('gym_id')->constrained('gyms')->onDelete('cascade');
            $table->foreignId('gym_class_id')->constrained('gym_classes')->onDelete('cascade');
            $table->foreignId('member_id')->constrained('members')->onDelete('cascade');
            // The specific calendar occurrence of the class's recurring weekly slot.
            $table->date('class_date');
            $table->string('status', 20)->default('Booked');
            $table->timestamps();
            // One active booking per member per class occurrence.
            $table->unique(['gym_class_id', 'member_id', 'class_date']);
            $table->index('class_date');
            $table->index('member_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('class_bookings');
    }
};
