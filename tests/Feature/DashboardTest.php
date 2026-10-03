<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The starter-kit dashboard isn't part of FitGym: everyone is sent to the
 * FitGym sign-in page, which routes them to their own portal.
 */
class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_sent_to_the_fitgym_sign_in_page()
    {
        $this->get(route('dashboard'))->assertRedirect('/');
    }

    public function test_signed_in_users_are_sent_to_the_fitgym_sign_in_page()
    {
        $this->actingAs(User::factory()->create());

        $this->get(route('dashboard'))->assertRedirect('/');
    }
}
