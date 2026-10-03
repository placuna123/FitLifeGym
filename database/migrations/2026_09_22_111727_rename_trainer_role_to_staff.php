<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * The "Trainer" role was renamed to "Staff" in code — backfill existing
     * rows so accounts created before this change keep working. AuditLog
     * stores a point-in-time snapshot of the role string (not a live lookup),
     * so its historical rows need the same backfill.
     */
    public function up(): void
    {
        DB::table('users')->where('role', 'Trainer')->update(['role' => 'Staff']);
        DB::table('audit_logs')->where('role', 'Trainer')->update(['role' => 'Staff']);
    }

    public function down(): void
    {
        DB::table('users')->where('role', 'Staff')->update(['role' => 'Trainer']);
        DB::table('audit_logs')->where('role', 'Staff')->update(['role' => 'Trainer']);
    }
};
