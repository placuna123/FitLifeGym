<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class SuperAdminSeeder extends Seeder
{
    public function run(): void
    {
        $superAdmin = User::firstOrCreate(
            ['email' => 'superadmin@fitgym.com'],
            [
                'name' => 'Platform Super Admin',
                'password' => Hash::make('password'),
                'role' => 'SuperAdmin',
                'gym_id' => null,
                'is_archived' => false,
            ]
        );

        if ($superAdmin->wasRecentlyCreated) {
            $this->command->info('Super Admin created! Email: superadmin@fitgym.com, Password: password');
        }
    }
}
