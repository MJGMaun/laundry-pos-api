<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')
                  ->constrained('orders')
                  ->cascadeOnDelete();
            $table->foreignId('service_id')
                  ->constrained('services')
                  ->restrictOnDelete();
            $table->string('service_name_snapshot');           // frozen at order time
            $table->decimal('unit_price_snapshot', 10, 2);     // frozen at order time
            $table->decimal('quantity', 8, 2);                 // kg, pieces, or 1 for flat
            $table->decimal('line_total', 10, 2);              // unit_price * quantity
            $table->enum('status', ['in_process', 'ready', 'picked_up'])->default('in_process');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loads');
    }
};
