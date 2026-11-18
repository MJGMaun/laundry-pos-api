<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * tip_payout: tips handed to staff out of the cash drawer or GCash. Tips
     * sit in the account when collected (that is where the money physically
     * is); paying them out takes them back out, without touching sales.
     */
    public function up(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE account_movements MODIFY COLUMN type ENUM('opening','withdrawal','deposit','transfer','tip_payout') NOT NULL");
        } else {
            Schema::table('account_movements', function (Blueprint $table) {
                $table->enum('type', ['opening', 'withdrawal', 'deposit', 'transfer', 'tip_payout'])->change();
            });
        }
    }

    public function down(): void
    {
        DB::table('account_movements')->where('type', 'tip_payout')->delete();

        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE account_movements MODIFY COLUMN type ENUM('opening','withdrawal','deposit','transfer') NOT NULL");
        }
    }
};
