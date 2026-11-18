<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * GCash reconciliation:
     *  - receipt_path: optional photo of the GCash receipt (private disk), the
     *    alternative to typing the last 4 digits of the reference number.
     *  - matched_at/by: a super admin ticked this payment off against the
     *    GCash app's transaction history.
     *  - method_changed_*: an admin corrected the method (e.g. GCash tapped by
     *    mistake on a cash payment); kept so the correction stays traceable.
     */
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->string('receipt_path')->nullable()->after('reference_number');
            $table->timestamp('matched_at')->nullable()->after('receipt_path');
            $table->foreignId('matched_by')->nullable()->after('matched_at')->constrained('users')->nullOnDelete();
            $table->string('method_changed_from', 10)->nullable()->after('matched_by');
            $table->foreignId('method_changed_by')->nullable()->after('method_changed_from')->constrained('users')->nullOnDelete();
            $table->timestamp('method_changed_at')->nullable()->after('method_changed_by');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('method_changed_by');
            $table->dropConstrainedForeignId('matched_by');
            $table->dropColumn(['receipt_path', 'matched_at', 'method_changed_from', 'method_changed_at']);
        });
    }
};
