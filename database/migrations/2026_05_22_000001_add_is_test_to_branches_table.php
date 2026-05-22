<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
	public function up(): void
	{
		Schema::table('branches', function (Blueprint $table) {
			$table->boolean('is_test')->default(false)->after('is_active');
		});
	}

	public function down(): void
	{
		Schema::table('branches', function (Blueprint $table) {
			$table->dropColumn('is_test');
		});
	}
};
