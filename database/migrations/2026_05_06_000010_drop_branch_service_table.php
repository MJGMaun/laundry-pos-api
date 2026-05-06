<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('branch_service');
    }

    public function down(): void
    {
        // Recreate the pivot if rolling back — see 2026_05_06_000003
        Schema::create('branch_service', function ($table) {
            $table->foreignId('branch_id')->constrained('branches')->cascadeOnDelete();
            $table->foreignId('service_id')->constrained('services')->cascadeOnDelete();
            $table->decimal('price', 10, 2)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->primary(['branch_id', 'service_id']);
        });
    }
};
