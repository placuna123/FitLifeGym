<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('staff_attendance') && ! Schema::hasTable('trainer_attendance')) {
            Schema::rename('staff_attendance', 'trainer_attendance');
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('trainer_attendance') && ! Schema::hasTable('staff_attendance')) {
            Schema::rename('trainer_attendance', 'staff_attendance');
        }
    }
};
