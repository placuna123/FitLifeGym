<?php

namespace Tests\Feature;

use App\Models\Gym;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlanFeaturesTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_create_a_plan_with_features(): void
    {
        $admin = $this->adminForNewGym('gym-a');

        $this->actingAs($admin)->postJson(route('membership-plans.store'), $this->planPayload([
            'features' => ['Gym Access', '  Locker Room  ', '', 'gym access', 'Free Wifi'],
        ]))->assertCreated()
            ->assertJsonPath('plan.features', ['Gym Access', 'Locker Room', 'Free Wifi']);

        $this->actingAs($admin)->getJson(route('membership-plans.index'))
            ->assertOk()
            ->assertJsonPath('0.features', ['Gym Access', 'Locker Room', 'Free Wifi']);
    }

    public function test_editing_a_plan_replaces_its_features(): void
    {
        $admin = $this->adminForNewGym('gym-a');
        $id = $this->actingAs($admin)->postJson(route('membership-plans.store'), $this->planPayload([
            'features' => ['Gym Access', 'Sauna'],
        ]))->json('plan.id');

        $this->actingAs($admin)->putJson(route('membership-plans.update', $id), $this->planPayload([
            'features' => ['Gym Access', 'Personal Trainer'],
        ]))->assertOk()
            ->assertJsonPath('plan.features', ['Gym Access', 'Personal Trainer']);
    }

    public function test_deactivating_a_plan_keeps_its_features(): void
    {
        $admin = $this->adminForNewGym('gym-a');
        $id = $this->actingAs($admin)->postJson(route('membership-plans.store'), $this->planPayload([
            'features' => ['Gym Access', 'Sauna'],
        ]))->json('plan.id');

        // The Deactivate button sends the plan fields without a features list.
        $this->actingAs($admin)->putJson(route('membership-plans.update', $id), $this->planPayload([
            'is_deactivated' => 1,
        ]))->assertOk()
            ->assertJsonPath('plan.features', ['Gym Access', 'Sauna']);
    }

    public function test_other_branches_do_not_see_a_plans_features(): void
    {
        $adminA = $this->adminForNewGym('gym-a');
        $adminB = $this->adminForNewGym('gym-b');

        $this->actingAs($adminA)->postJson(route('membership-plans.store'), $this->planPayload([
            'features' => ['Gym Access'],
        ]))->assertCreated();

        $this->actingAs($adminB)->getJson(route('membership-plans.index'))
            ->assertOk()
            ->assertExactJson([]);
    }

    public function test_too_many_features_are_rejected(): void
    {
        $admin = $this->adminForNewGym('gym-a');

        $this->actingAs($admin)->postJson(route('membership-plans.store'), $this->planPayload([
            'features' => array_map(fn ($i) => "Feature {$i}", range(1, 21)),
        ]))->assertStatus(422);
    }

    private function adminForNewGym(string $slug): User
    {
        $gym = Gym::create(['name' => strtoupper($slug), 'slug' => $slug]);

        return User::factory()->create(['role' => 'Admin', 'gym_id' => $gym->id]);
    }

    private function planPayload(array $overrides = []): array
    {
        return [
            'name' => 'Basic',
            'type' => 'Monthly',
            'price' => 500,
            'period' => 'per month',
            'description' => 'Basic Plan',
            ...$overrides,
        ];
    }
}
