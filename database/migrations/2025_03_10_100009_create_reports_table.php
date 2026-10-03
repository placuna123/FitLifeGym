<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('reports')) {
            return;
        }
        Schema::create('reports', function (Blueprint $table) {
            $table->id();
            $table->string('report_type', 100);
            $table->string('report_name');
            $table->foreignId('generated_by')->nullable()->constrained('users')->onDelete('set null');
            $table->date('date_from')->nullable();
            $table->date('date_to')->nullable();
            $table->string('file_path', 500)->nullable();
            $table->json('data')->nullable();
            $table->tinyInteger('is_archived')->default(0);
            $table->timestamps();
            $table->index('report_type');
            $table->index('generated_by');
            $table->index('is_archived');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reports');
    }
};
