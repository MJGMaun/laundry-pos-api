<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')
                  ->constrained('orders')
                  ->cascadeOnDelete();
            $table->decimal('amount', 12, 2);
            $table->enum('method', ['cash', 'gcash', 'maya', 'card']);
            $table->enum('type', ['payment', 'refund'])->default('payment');
            $table->decimal('tendered', 12, 2)->nullable();        // cash given by customer
            $table->decimal('change_amount', 12, 2)->nullable();   // change returned
            $table->string('reference_number')->nullable();         // gcash/maya/card ref
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
