<?php

namespace Tests\Feature;

use App\Models\Gym;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProfilePhotoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    public function test_admin_staff_and_member_can_upload_a_profile_photo(): void
    {
        $gym = Gym::create(['name' => 'Gym A', 'slug' => 'gym-a']);

        foreach (['Admin', 'Staff', 'Member'] as $role) {
            $user = User::factory()->create(['role' => $role, 'gym_id' => $gym->id]);

            $response = $this->actingAs($user)->post(route('profile.photo.store'), [
                'photo' => UploadedFile::fake()->image('me.jpg', 400, 400),
            ], ['Accept' => 'application/json'])->assertOk();

            $path = $user->fresh()->profile_photo_path;
            $this->assertNotNull($path, $role);
            Storage::disk('public')->assertExists($path);
            $this->assertSame('/storage/'.$path, $response->json('photo_url'));
        }
    }

    public function test_a_new_photo_replaces_and_deletes_the_old_one(): void
    {
        $user = User::factory()->create(['role' => 'Admin']);

        $this->actingAs($user)->post(route('profile.photo.store'), ['photo' => UploadedFile::fake()->image('a.jpg')], ['Accept' => 'application/json'])->assertOk();
        $first = $user->fresh()->profile_photo_path;
        $this->actingAs($user)->post(route('profile.photo.store'), ['photo' => UploadedFile::fake()->image('b.png')], ['Accept' => 'application/json'])->assertOk();

        Storage::disk('public')->assertMissing($first);
        Storage::disk('public')->assertExists($user->fresh()->profile_photo_path);
    }

    public function test_a_file_that_is_not_an_image_is_rejected(): void
    {
        $user = User::factory()->create(['role' => 'Member']);

        $this->actingAs($user)->post(route('profile.photo.store'), [
            'photo' => UploadedFile::fake()->create('notes.pdf', 10, 'application/pdf'),
        ], ['Accept' => 'application/json'])->assertStatus(422);

        $this->assertNull($user->fresh()->profile_photo_path);
    }

    public function test_photo_can_be_removed(): void
    {
        $user = User::factory()->create(['role' => 'Staff']);
        $this->actingAs($user)->post(route('profile.photo.store'), ['photo' => UploadedFile::fake()->image('a.jpg')], ['Accept' => 'application/json']);
        $path = $user->fresh()->profile_photo_path;

        $this->actingAs($user)->deleteJson(route('profile.photo.destroy'))->assertOk()->assertJsonPath('photo_url', null);

        $this->assertNull($user->fresh()->profile_photo_path);
        Storage::disk('public')->assertMissing($path);
    }

    public function test_guests_cannot_upload(): void
    {
        $this->post(route('profile.photo.store'), ['photo' => UploadedFile::fake()->image('a.jpg')], ['Accept' => 'application/json'])
            ->assertUnauthorized();
    }

    public function test_session_user_payload_includes_the_photo_url(): void
    {
        $gym = Gym::create(['name' => 'Gym A', 'slug' => 'gym-a']);
        $admin = User::factory()->create(['role' => 'Admin', 'gym_id' => $gym->id]);
        $this->actingAs($admin)->post(route('profile.photo.store'), ['photo' => UploadedFile::fake()->image('a.jpg')], ['Accept' => 'application/json']);

        $this->actingAs($admin)->getJson(route('admin.user'))
            ->assertOk()
            ->assertJsonPath('user.photo_url', $admin->fresh()->profilePhotoUrl());
    }
}
