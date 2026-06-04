<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dateTime('delivery_scheduled_at')->nullable()->after('estimated_ready_at');
            $table->dateTime('delivered_at')->nullable()->after('delivery_scheduled_at');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['delivery_scheduled_at', 'delivered_at']);
        });
    }
};
