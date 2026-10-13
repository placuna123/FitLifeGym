<?php

namespace Tests\Feature;

use App\Models\Gym;
use App\Models\Member;
use App\Models\MembershipPlan;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

class ReportExcelTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_downloads_a_real_xlsx_workbook_of_their_branch(): void
    {
        $gymA = $this->gymWithMember('Gym A', 'Alice Alpha', 1500);
        $this->gymWithMember('Gym B', 'Bob Bravo', 900);
        $admin = User::factory()->create(['role' => 'Admin', 'gym_id' => $gymA->id]);

        $response = $this->actingAs($admin)->get(route('reports.export-excel'));

        $response->assertOk();
        $this->assertStringContainsString('spreadsheetml.sheet', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('Gym_A_Report_', $response->headers->get('Content-Disposition'));

        $file = $response->baseResponse->getFile()->getPathname();
        $book = IOFactory::load($file);
        @unlink($file);

        $this->assertSame(['Summary', 'Members', 'Payments', 'Attendance', 'Plans'], $book->getSheetNames());

        $members = $book->getSheetByName('Members')->toArray();
        $this->assertSame('Alice Alpha', $members[1][1]);
        $this->assertCount(2, $members, 'only the header and the one member of this branch');

        $payments = $book->getSheetByName('Payments')->toArray();
        $this->assertSame('Alice Alpha', $payments[1][1]);
        $this->assertEquals(1500, $book->getSheetByName('Payments')->getCell('D2')->getValue(), 'amount is stored as a number');

        $summary = $book->getSheetByName('Summary');
        $this->assertStringContainsString('Gym A', $summary->getCell('A1')->getValue());
    }

    public function test_guests_cannot_download_the_report(): void
    {
        $this->getJson(route('reports.export-excel'))->assertUnauthorized();
    }

    private function gymWithMember(string $name, string $memberName, int $amount): Gym
    {
        $gym = Gym::create(['name' => $name, 'slug' => strtolower(str_replace(' ', '-', $name))]);
        $plan = MembershipPlan::create(['gym_id' => $gym->id, 'name' => 'Monthly', 'type' => 'Monthly', 'price' => $amount, 'period' => 'per month', 'is_archived' => 0]);
        $member = Member::create([
            'gym_id' => $gym->id,
            'member_id' => (string) random_int(1000, 9999),
            'full_name' => $memberName,
            'email' => strtolower(str_replace(' ', '.', $memberName)).'@example.com',
            'phone' => '09170000000',
            'membership_plan_id' => $plan->id,
            'status' => 'Active',
            'payment_status' => 'Paid',
            'expiry_date' => now()->addMonth()->toDateString(),
        ]);
        Payment::create([
            'gym_id' => $gym->id,
            'invoice_number' => 'INV-'.$gym->id,
            'member_id' => $member->id,
            'membership_plan_id' => $plan->id,
            'amount' => $amount,
            'payment_method' => 'Cash',
            'payment_date' => now()->toDateString(),
            'status' => 'Paid',
        ]);

        return $gym;
    }
}
