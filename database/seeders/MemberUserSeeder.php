<?php

namespace Database\Seeders;

use App\Models\Member;
use App\Models\MembershipPlan;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class MemberUserSeeder extends Seeder
{
    public function run(): void
    {
        $defaultPlan = MembershipPlan::where('is_archived', false)->orderBy('id')->first();

        if (! $defaultPlan) {
            $defaultPlan = MembershipPlan::create([
                'name' => 'Trial Plan',
                'type' => 'Monthly',
                'price' => 0,
                'period' => '1 month',
                'description' => 'Trial membership for new members',
                'is_popular' => 0,
                'is_deactivated' => 0,
                'member_count' => 0,
                'monthly_revenue' => 0,
                'is_archived' => false,
            ]);
        }

        do {
            $memberId = str_pad((string) random_int(1, 9999), 4, '0', STR_PAD_LEFT);
        } while (Member::where('member_id', $memberId)->exists());

        $expiryDate = now();
        switch (strtolower($defaultPlan->type)) {
            case 'annual':
                $expiryDate = $expiryDate->addYear();
                break;
            case 'quarterly':
                $expiryDate = $expiryDate->addMonths(3);
                break;
            default:
                $expiryDate = $expiryDate->addMonth();
                break;
        }
        $expiryDate = $expiryDate->format('Y-m-d');

        $memberUser = User::firstOrCreate(
            ['email' => 'member@fitgym.com'],
            [
                'name' => 'Member User',
                'password' => Hash::make('password'),
                'role' => 'Member',
                'is_archived' => false,
            ]
        );

        $member = Member::firstOrCreate(
            ['email' => 'member@fitgym.com'],
            [
                'member_id' => $memberId,
                'full_name' => 'Member User',
                'phone' => '123-456-7890',
                'address' => null,
                'membership_plan_id' => $defaultPlan->id,
                'status' => 'Active',
                'expiry_date' => $expiryDate,
                'payment_status' => 'Paid',
                'is_archived' => false,
            ]
        );

        if ($memberUser->wasRecentlyCreated) {
            $this->command->info('Member user created! Email: member@fitgym.com, Password: password');
        }

        // If the member exists but is archived, unarchive it so member login works.
        if ($member->is_archived) {
            $member->update([
                'full_name' => 'Member User',
                'phone' => $member->phone ?: '123-456-7890',
                'address' => null,
                'membership_plan_id' => $defaultPlan->id,
                'status' => 'Active',
                'expiry_date' => $expiryDate,
                'payment_status' => 'Paid',
                'is_archived' => false,
            ]);
        }

        if ($member->wasRecentlyCreated) {
            $this->command->info('Member record created. Member ID: ' . $member->member_id);
        }
    }
}
