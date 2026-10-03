<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('staff_schedules') && ! Schema::hasTable('trainer_schedules')) {
            Schema::rename('staff_schedules', 'trainer_schedules');
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('trainer_schedules') && ! Schema::hasTable('staff_schedules')) {
            Schema::rename('trainer_schedules', 'staff_schedules');
        }
    }
};
