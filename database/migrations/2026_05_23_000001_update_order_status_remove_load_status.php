<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Map any existing 'in_process' orders to 'pending' before changing the enum
        DB::table('orders')->where('status', 'in_process')->update(['status' => 'pending']);

        // Change order status enum: remove 'in_process', add 'to_collect'
        DB::statement("ALTER TABLE orders MODIFY COLUMN status ENUM('pending','ready','to_collect','completed') NOT NULL DEFAULT 'pending'");

        // Drop status index and column from loads
        Schema::table('loads', function (Blueprint $table) {
            $table->dropIndex(['status']);
            $table->dropColumn('status');
        });
    }

    public function down(): void
    {
        // Restore loads status column
        Schema::table('loads', function (Blueprint $table) {
            $table->enum('status', ['in_process', 'ready', 'picked_up'])->default('in_process')->after('line_total');
            $table->index('status');
        });

        // Restore order status enum
        DB::statement("ALTER TABLE orders MODIFY COLUMN status ENUM('pending','in_process','ready','completed') NOT NULL DEFAULT 'pending'");
    }
};
