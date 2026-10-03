<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class AdminUserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $admin = User::firstOrCreate(
            ['email' => 'admin@fitgym.com'],
            [
                'name' => 'Admin User',
                'password' => Hash::make('password'),
                'role' => 'Admin',
                'is_archived' => false,
            ]
        );

        if ($admin->wasRecentlyCreated) {
            $this->command->info('Admin user created successfully!');
            $this->command->info('Email: admin@fitgym.com');
            $this->command->info('Password: password');
            $this->command->warn('Please change the password after first login!');
        } else {
            $this->command->info('Admin user already exists.');
        }
    }
}
