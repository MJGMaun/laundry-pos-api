<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
	/**
	 * Run the migrations.
	 */
	public function up(): void
	{
		// Drop foreign key and column
		Schema::table('services', function (Blueprint $table) {
			$table->dropForeign(['service_category_id']);
			$table->dropColumn('service_category_id');
		});

		// Drop the service_categories table
		Schema::dropIfExists('service_categories');
	}

	/**
	 * Reverse the migrations.
	 */
	public function down(): void
	{
		// Recreate the table (basic structure)
		Schema::create('service_categories', function (Blueprint $table) {
			$table->id();
			$table->string('name');
			$table->timestamps();
		});

		// Add back the column and foreign key
		Schema::table('services', function (Blueprint $table) {
			$table->foreignId('service_category_id')->constrained('service_categories')->cascadeOnDelete();
		});
	}
};
