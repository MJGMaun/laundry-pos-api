<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->foreignId('branch_id')
                ->nullable()
                ->after('id')
                ->constrained('branches')
                ->cascadeOnDelete();

            // key is no longer globally unique — unique per (key, branch_id)
            // null branch_id = global; a branch can override any global key
            $table->dropUnique(['key']);
            $table->unique(['key', 'branch_id']);
        });
    }

    public function down(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->dropUnique(['key', 'branch_id']);
            $table->unique(['key']);
            $table->dropConstrainedForeignId('branch_id');
        });
    }
};
