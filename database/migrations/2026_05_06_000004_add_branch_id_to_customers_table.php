<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->foreignId('branch_id')
                ->nullable()
                ->after('id')
                ->constrained('branches')
                ->nullOnDelete();

            // Phone is now unique per branch, not globally
            $table->dropUnique(['phone']);
            $table->unique(['branch_id', 'phone']);
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropUnique(['branch_id', 'phone']);
            $table->unique(['phone']);
            $table->dropConstrainedForeignId('branch_id');
        });
    }
};
