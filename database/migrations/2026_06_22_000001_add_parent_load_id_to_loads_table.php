<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('loads', function (Blueprint $table) {
            // Add-ons (e.g. Add Dry, Add Fabcon) point at the laundry load they
            // belong to; primary loads leave this null.
            $table->foreignId('parent_load_id')
                  ->nullable()
                  ->after('order_id')
                  ->constrained('loads')
                  ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('loads', function (Blueprint $table) {
            $table->dropConstrainedForeignId('parent_load_id');
        });
    }
};
