<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('orders')->where('status', 'to_collect')->update(['status' => 'claimed']);

        DB::statement("ALTER TABLE orders MODIFY COLUMN status ENUM('pending','ready','claimed','completed') NOT NULL DEFAULT 'pending'");
    }

    public function down(): void
    {
        DB::table('orders')->where('status', 'claimed')->update(['status' => 'to_collect']);

        DB::statement("ALTER TABLE orders MODIFY COLUMN status ENUM('pending','ready','to_collect','completed') NOT NULL DEFAULT 'pending'");
    }
};
