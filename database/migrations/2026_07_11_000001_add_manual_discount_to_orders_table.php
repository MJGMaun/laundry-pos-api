<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Separate from discount_amount (the loyalty discount, which the
        // system recomputes) so an admin's manual adjustment is never wiped.
        Schema::table('orders', function (Blueprint $table) {
            $table->decimal('manual_discount_amount', 10, 2)->default(0)->after('discount_amount');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('manual_discount_amount');
        });
    }
};
