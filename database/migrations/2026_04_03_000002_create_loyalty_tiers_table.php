<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loyalty_tiers', function (Blueprint $table) {
            $table->id();
            $table->string('name');                          // Bronze, Silver, Gold
            $table->decimal('multiplier', 3, 1);             // 1.0, 1.5, 2.0
            $table->decimal('min_spend_threshold', 10, 2);   // min total_spent to qualify
            $table->boolean('is_default')->default(false);   // Bronze = true
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loyalty_tiers');
    }
};
