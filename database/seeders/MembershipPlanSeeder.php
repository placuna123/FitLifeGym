<?php

namespace Database\Seeders;

use App\Models\MembershipPlan;
use Illuminate\Database\Seeder;

class MembershipPlanSeeder extends Seeder
{
    public function run(): void
    {
        $plans = [
            [
                'name' => 'Basic Plan',
                'type' => 'Monthly',
                'price' => 1000.00,
                'period' => 'per month',
                'description' => 'Basic gym access with standard facilities',
                'is_popular' => 0,
                'is_deactivated' => 0,
                'member_count' => 0,
                'monthly_revenue' => 0,
                'is_archived' => false,
            ],
            [
                'name' => 'VIP Plan',
                'type' => 'Quarterly',
                'price' => 2500.00,
                'period' => 'per 3 months',
                'description' => 'VIP access with premium facilities and personal training',
                'is_popular' => 1,
                'is_deactivated' => 0,
                'member_count' => 0,
                'monthly_revenue' => 0,
                'is_archived' => false,
            ],
            [
                'name' => 'Premium Plan',
                'type' => 'Annual',
                'price' => 10000.00,
                'period' => 'per year',
                'description' => 'Premium access with all facilities, personal training, and exclusive benefits',
                'is_popular' => 0,
                'is_deactivated' => 0,
                'member_count' => 0,
                'monthly_revenue' => 0,
                'is_archived' => false,
            ],
        ];

        foreach ($plans as $planData) {
            MembershipPlan::firstOrCreate(
                ['name' => $planData['name']],
                $planData
            );
        }

        $this->command->info('Membership plans seeded successfully!');
    }
}
