<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('notifications')) {
            return;
        }
        Schema::create('notifications', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('message');
            $table->string('type', 20)->default('Info');
            $table->tinyInteger('is_read')->default(0);
            $table->timestamp('read_at')->nullable();
            $table->tinyInteger('is_archived')->default(0);
            $table->timestamps();
            $table->index('is_read');
            $table->index('type');
            $table->index('is_archived');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
