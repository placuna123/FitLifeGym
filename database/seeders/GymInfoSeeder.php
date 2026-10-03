<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class GymInfoSeeder extends Seeder
{
    public function run(): void
    {
        if (!Schema::hasTable('gym_info')) {
            return;
        }
        if (DB::table('gym_info')->exists()) {
            return;
        }
        DB::table('gym_info')->insert([
            'name' => 'FitLife Gym',
            'email' => 'info@fitlifegym.com',
            'phone' => '+63 2 1234 5678',
            'address' => '123 Fitness Ave, Metro Manila',
            'operating_hours' => json_encode([
                'Monday' => '6AM-10PM',
                'Tuesday' => '6AM-10PM',
                'Wednesday' => '6AM-10PM',
                'Thursday' => '6AM-10PM',
                'Friday' => '6AM-10PM',
                'Saturday' => '7AM-8PM',
                'Sunday' => '7AM-6PM',
            ]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
