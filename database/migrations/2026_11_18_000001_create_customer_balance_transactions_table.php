<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Prepaid customer balance (deposits). A deposit is money received now for
     * services later: it lands in the drawer/GCash right away but is not sales
     * until it is spent on an order, where it shows up as a payment with
     * method = 'balance'. `customers.balance` is the running total; this table
     * is the ledger behind it.
     */
    public function up(): void
    {
        Schema::create('customer_balance_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->cascadeOnDelete();
            // deposit: money in (cash/gcash). payment: spent on an order.
            // refund: an order refund credited back to the balance.
            // reversal: a deleted balance payment or a voided deposit.
            $table->string('type', 20);
            // Only deposits carry a money method; the rest move balance alone.
            $table->string('method', 10)->nullable();
            // Signed: + adds to the customer's balance, - takes from it.
            $table->decimal('amount', 12, 2);
            $table->decimal('balance_after', 12, 2);
            $table->foreignId('payment_id')->nullable()->constrained('payments')->nullOnDelete();
            $table->foreignId('reverses_id')->nullable()->constrained('customer_balance_transactions')->nullOnDelete();
            $table->timestamp('voided_at')->nullable();
            $table->string('note', 500)->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['customer_id', 'created_at']);
            $table->index(['branch_id', 'type', 'created_at']);
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->decimal('balance', 12, 2)->default(0)->after('total_spent');
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE payments MODIFY COLUMN method ENUM('cash','gcash','maya','card','balance') NOT NULL");
        } else {
            // SQLite enforces the enum with a CHECK constraint; rebuild the column.
            Schema::table('payments', function (Blueprint $table) {
                $table->enum('method', ['cash', 'gcash', 'maya', 'card', 'balance'])->change();
            });
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::table('payments')->where('method', 'balance')->delete();
            DB::statement("ALTER TABLE payments MODIFY COLUMN method ENUM('cash','gcash','maya','card') NOT NULL");
        }

        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn('balance');
        });

        Schema::dropIfExists('customer_balance_transactions');
    }
};
