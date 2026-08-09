<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Money moving in or out of an account for reasons that are NOT business
     * expenses: owner/partner withdrawals, capital put back in, transfers
     * between cash and GCash, and the opening balance that anchors the running
     * total. Deliberately a separate table from `expenses` so profit and margin
     * in the reports are untouched — a draw is a distribution of profit, not a
     * cost of earning it.
     */
    public function up(): void
    {
        Schema::create('account_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained('branches')->cascadeOnDelete();
            $table->enum('type', ['opening', 'withdrawal', 'deposit', 'transfer']);
            // For a transfer, `method` is the source account and `to_method`
            // the destination; every other type uses `method` alone.
            $table->enum('method', ['cash', 'gcash']);
            $table->enum('to_method', ['cash', 'gcash'])->nullable();
            $table->decimal('amount', 12, 2);
            $table->date('occurred_on');
            $table->string('recipient', 100)->nullable();
            $table->string('note', 500)->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['branch_id', 'method', 'occurred_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('account_movements');
    }
};
