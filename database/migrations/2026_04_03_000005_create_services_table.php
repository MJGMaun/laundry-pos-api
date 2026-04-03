<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('services', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_category_id')
                  ->constrained('service_categories')
                  ->cascadeOnDelete();
            $table->string('name');                                        // e.g. "Regular Wash", "Suit"
            $table->enum('pricing_type', ['per_kilo', 'per_piece', 'flat_rate']);
            $table->decimal('price', 10, 2);                              // base price
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('services');
    }
};
