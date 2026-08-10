<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per-branch overrides of who can reach which page. Sparse on purpose:
     * a row exists only where a branch departs from PageRegistry's defaults,
     * so an untouched branch behaves exactly as it did before this shipped
     * and the table stays small.
     */
    public function up(): void
    {
        Schema::create('branch_page_access', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained('branches')->cascadeOnDelete();
            $table->string('page', 64);
            $table->enum('role', ['admin', 'cashier', 'staff']);
            $table->boolean('can_view')->default(false);
            $table->boolean('can_edit')->default(false);
            $table->timestamps();

            $table->unique(['branch_id', 'page', 'role']);
            $table->index(['branch_id', 'role']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('branch_page_access');
    }
};
