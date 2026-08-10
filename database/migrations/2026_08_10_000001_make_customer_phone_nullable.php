<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Branches that serve walk-ins who will not give a number can turn the
     * phone requirement off (customer_phone_required), so the column has to
     * accept NULL.
     *
     * The unique index stays as it is: it covers (branch_id, phone), and SQL
     * treats NULLs as distinct, so any number of customers in one branch can
     * have no phone while two customers still cannot share the same number.
     * That only holds for real NULLs — an empty string would collide on the
     * second customer, which is why CustomerController normalises '' to null.
     */
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->string('phone')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->string('phone')->nullable(false)->change();
        });
    }
};
