<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('staff') && ! Schema::hasTable('trainers')) {
            Schema::rename('staff', 'trainers');
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('trainers') && ! Schema::hasTable('staff')) {
            Schema::rename('trainers', 'staff');
        }
    }
};
