<?php

namespace Database\Seeders;

use App\Models\Trainer;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class TrainerUserSeeder extends Seeder
{
    public function run(): void
    {
        $trainerUser = User::firstOrCreate(
            ['email' => 'trainer@fitgym.com'],
            [
                'name' => 'Trainer User',
                'password' => Hash::make('password'),
                'role' => 'Staff',
                'is_archived' => false,
            ]
        );

        $maxId = Trainer::max('id') ?? 0;
        $staffId = 'STF' . str_pad($maxId + 1, 4, '0', STR_PAD_LEFT);
        $existingTrainer = Trainer::where('email', 'trainer@fitgym.com')->first();
        if ($existingTrainer) {
            $staffId = $existingTrainer->staff_id;
        } else {
            while (Trainer::where('staff_id', $staffId)->exists()) {
                $maxId++;
                $staffId = 'STF' . str_pad($maxId + 1, 4, '0', STR_PAD_LEFT);
            }
        }

        $trainer = Trainer::firstOrCreate(
            ['email' => 'trainer@fitgym.com'],
            [
                'staff_id' => $staffId,
                'full_name' => 'Trainer User',
                'phone' => '123-456-7890',
                'position' => 'Personal Trainer',
                'department' => null,
                'hire_date' => now(),
                'status' => 'Active',
                'is_archived' => 0,
            ]
        );

        if ($trainerUser->wasRecentlyCreated) {
            $this->command->info('Trainer user created! Email: trainer@fitgym.com, Password: password');
        }
        if ($trainer->wasRecentlyCreated) {
            $this->command->info('Trainer record created.');
        }
    }
}
