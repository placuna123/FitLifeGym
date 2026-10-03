<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gyms', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('email')->nullable();
            $table->string('phone', 50)->nullable();
            $table->text('address')->nullable();
            $table->json('operating_hours')->nullable();
            $table->string('gcash_number', 20)->nullable();
            $table->string('gcash_account_name')->nullable();
            $table->string('gcash_qr_path')->nullable();
            $table->unsignedBigInteger('owner_user_id')->nullable();
            $table->index('owner_user_id');
            $table->string('subscription_plan', 30)->default('trial');
            $table->string('subscription_status', 20)->default('trial');
            $table->date('subscription_expires_at')->nullable();
            $table->tinyInteger('is_archived')->default(0);
            $table->timestamps();
        });

        // Carry the existing single-gym settings (gym_info) over as the first tenant,
        // so the current demo data has somewhere to belong once gym_id is rolled out.
        $existing = Schema::hasTable('gym_info') ? DB::table('gym_info')->first() : null;

        $name = $existing?->name ?: 'FitLife Gym';
        $slug = Str::slug($name) ?: 'fitlife-gym';
        if (DB::table('gyms')->where('slug', $slug)->exists()) {
            $slug = $slug . '-' . Str::random(5);
        }

        DB::table('gyms')->insert([
            'name' => $name,
            'slug' => $slug,
            'email' => $existing?->email,
            'phone' => $existing?->phone,
            'address' => $existing?->address,
            'operating_hours' => $existing?->operating_hours,
            'gcash_number' => $existing?->gcash_number,
            'gcash_account_name' => $existing?->gcash_account_name,
            'gcash_qr_path' => $existing?->gcash_qr_path,
            'owner_user_id' => null,
            'subscription_plan' => 'pro',
            'subscription_status' => 'active',
            'subscription_expires_at' => null,
            'is_archived' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('gyms');
    }
};
