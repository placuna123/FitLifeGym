<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Each branch describes itself and can add a photo, so people creating a
     * Member account can see what the gym is like before choosing it.
     */
    public function up(): void
    {
        Schema::table('gyms', function (Blueprint $table) {
            $table->text('description')->nullable()->after('address');
            $table->string('cover_photo_path', 2048)->nullable()->after('description');
        });
    }

    public function down(): void
    {
        Schema::table('gyms', function (Blueprint $table) {
            $table->dropColumn(['description', 'cover_photo_path']);
        });
    }
};
