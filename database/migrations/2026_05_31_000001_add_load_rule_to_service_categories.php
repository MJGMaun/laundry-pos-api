<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_categories', function (Blueprint $table) {
            // How this category counts toward the "Loads" metric:
            //   quantity  = each unit is a load (e.g. All In, Packages)
            //   per_order = all of this category's items in an order = 1 load (Self Service)
            //   none      = never counts as a load (Add ons)
            $table->string('load_rule')->default('quantity')->after('icon');
        });

        // Seed existing categories to match the business rules.
        DB::table('service_categories')->where('name', 'Self Service')->update(['load_rule' => 'per_order']);
        DB::table('service_categories')->where('name', 'Add ons')->update(['load_rule' => 'none']);
    }

    public function down(): void
    {
        Schema::table('service_categories', function (Blueprint $table) {
            $table->dropColumn('load_rule');
        });
    }
};
