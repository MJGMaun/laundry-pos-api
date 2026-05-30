<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('loyalty_stamps', function (Blueprint $table) {
            // Manual adjustments aren't tied to an order, and may be negative.
            $table->unsignedBigInteger('order_id')->nullable()->change();
            $table->integer('stamps_earned')->change();
            $table->string('note')->nullable()->after('stamps_earned');
            $table->foreignId('created_by')->nullable()->after('note')
                  ->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('loyalty_stamps', function (Blueprint $table) {
            $table->dropConstrainedForeignId('created_by');
            $table->dropColumn('note');
            $table->unsignedInteger('stamps_earned')->change();
            $table->unsignedBigInteger('order_id')->nullable(false)->change();
        });
    }
};
