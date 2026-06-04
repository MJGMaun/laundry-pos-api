<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bookings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->foreignId('customer_id')->constrained('customers')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->dateTime('scheduled_at');
            $table->string('address')->nullable();
            $table->text('notes')->nullable();
            $table->enum('status', ['scheduled', 'picked_up', 'cancelled'])->default('scheduled');
            $table->dateTime('picked_up_at')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index('scheduled_at');
            $table->index('branch_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bookings');
    }
};
