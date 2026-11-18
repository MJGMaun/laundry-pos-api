<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * tip_amount: money a customer sent on top of what the order needed
     * (typically an overpaid GCash transfer). It lands in the account, so it
     * counts toward cash/GCash balances, but it is not sales: `amount` stays
     * the part applied to the order, and every revenue figure sums `amount`.
     *
     * account_checks: a super admin's end-of-day count — the balance the GCash
     * app (or the drawer) actually shows, against what the system expected at
     * that moment. Unlike an opening balance it seals nothing; it only records
     * whether the two agreed.
     */
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->decimal('tip_amount', 12, 2)->default(0)->after('amount');
        });

        Schema::create('account_checks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained('branches')->cascadeOnDelete();
            $table->string('method', 10);
            $table->decimal('counted_amount', 12, 2);
            $table->decimal('expected_amount', 12, 2);
            $table->decimal('difference', 12, 2);
            $table->string('note', 500)->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['branch_id', 'method', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('account_checks');

        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn('tip_amount');
        });
    }
};
