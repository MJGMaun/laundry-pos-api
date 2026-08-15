<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A free load is worth whatever the cheapest eligible load happens to
        // cost, which varies by service. A fixed discount is a flat peso amount
        // the shop can state up front, so add it as a third reward type.
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE loyalty_rules MODIFY COLUMN reward_type ENUM('free_load','free_item','fixed_discount') NOT NULL");
        } else {
            // SQLite stores the enum as a varchar guarded by a CHECK constraint;
            // widening it to a plain string drops the constraint along with it.
            Schema::table('loyalty_rules', function (Blueprint $table) {
                $table->string('reward_type', 32)->change();
            });
        }

        Schema::table('loyalty_rules', function (Blueprint $table) {
            // Only meaningful for fixed_discount; null for the other types.
            $table->decimal('reward_amount', 10, 2)->nullable()->after('reward_type');
        });
    }

    public function down(): void
    {
        DB::table('loyalty_rules')->where('reward_type', 'fixed_discount')->delete();

        Schema::table('loyalty_rules', function (Blueprint $table) {
            $table->dropColumn('reward_amount');
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE loyalty_rules MODIFY COLUMN reward_type ENUM('free_load','free_item') NOT NULL");
        }
    }
};
