<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('phone')->unique();
            $table->string('email')->nullable();
            $table->string('address')->nullable();
            $table->string('loyalty_card_number')->nullable()->unique();
            $table->foreignId('loyalty_tier_id')
                  ->nullable()
                  ->constrained('loyalty_tiers')
                  ->nullOnDelete();
            $table->integer('loyalty_points')->default(0);
            $table->integer('total_visits')->default(0);
            $table->decimal('total_spent', 12, 2)->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};
