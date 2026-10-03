<?php

namespace Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The starter-kit self-registration is turned off: FitGym members and gym
 * owners sign up through the FitGym portal instead (see SaasRulesTest,
 * EmailNotificationsTest and MemberPendingApprovalTest).
 */
class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_starter_registration_page_is_disabled()
    {
        $this->get('/register')->assertNotFound();
    }

    public function test_starter_registration_endpoint_is_disabled()
    {
        $this->post('/register', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ])->assertNotFound();

        $this->assertGuest();
        $this->assertDatabaseMissing('users', ['email' => 'test@example.com']);
    }
}
