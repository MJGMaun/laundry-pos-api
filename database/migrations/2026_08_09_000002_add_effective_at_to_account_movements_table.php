<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The exact instant an opening balance sealed the account. Everything
     * already recorded at that point is baked into the counted figure, so the
     * running balance only counts what was entered afterwards.
     *
     * Replaces the previous date-based cutover, which still added the same
     * day's earlier payments and expenses on top of the counted figure — the
     * balance never matched what was actually in the drawer.
     */
    public function up(): void
    {
        Schema::table('account_movements', function (Blueprint $table) {
            $table->timestamp('effective_at')->nullable()->after('occurred_on');
        });

        // Existing opening rows sealed the account when they were recorded.
        DB::table('account_movements')
            ->where('type', 'opening')
            ->update(['effective_at' => DB::raw('created_at')]);
    }

    public function down(): void
    {
        Schema::table('account_movements', function (Blueprint $table) {
            $table->dropColumn('effective_at');
        });
    }
};
