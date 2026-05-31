<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Global default: Day Summary page is enabled. A super admin can add a
        // per-branch override set to 'false' to hide it for a branch.
        $exists = DB::table('settings')
            ->where('key', 'day_summary_enabled')
            ->whereNull('branch_id')
            ->exists();

        if (! $exists) {
            DB::table('settings')->insert([
                'branch_id'  => null,
                'key'        => 'day_summary_enabled',
                'value'      => 'true',
                'group'      => 'general',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('settings')
            ->where('key', 'day_summary_enabled')
            ->whereNull('branch_id')
            ->delete();
    }
};
