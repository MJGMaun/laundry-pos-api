<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $isMysql = DB::getDriverName() === 'mysql';

        // Step 1: expand ENUM to include both values so the UPDATE is valid
        // (SQLite tests: status is a plain string column — no enum to alter)
        if ($isMysql) {
            DB::statement("ALTER TABLE orders MODIFY COLUMN status ENUM('pending','ready','to_collect','claimed','completed') NOT NULL DEFAULT 'pending'");
        }

        // Step 2: migrate existing rows
        DB::table('orders')->where('status', 'to_collect')->update(['status' => 'claimed']);

        // Step 3: drop the old value
        if ($isMysql) {
            DB::statement("ALTER TABLE orders MODIFY COLUMN status ENUM('pending','ready','claimed','completed') NOT NULL DEFAULT 'pending'");
        }
    }

    public function down(): void
    {
        DB::table('orders')->where('status', 'claimed')->update(['status' => 'to_collect']);

        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE orders MODIFY COLUMN status ENUM('pending','ready','to_collect','completed') NOT NULL DEFAULT 'pending'");
        }
    }
};
