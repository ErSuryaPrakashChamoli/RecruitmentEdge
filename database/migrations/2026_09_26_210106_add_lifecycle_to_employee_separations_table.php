<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 8.4: a separation has a lifecycle. It may be recorded ahead of its last working day and
 * becomes effective the day after (effective_applied_at records when employment and access were
 * changed); it can be cancelled with a reason (kept, never deleted). One employee may now have more
 * than one separation over time (a rehire followed by a later exit), so the unique key on
 * employee_id becomes a plain index — an index change only, no data changes. users.access_source
 * records which lifecycle set the current access state, so reactivating employment lifts only a
 * suspension that employment caused.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employee_separations', function (Blueprint $table): void {
            $table->timestamp('effective_applied_at')->nullable()->after('notes');
            $table->timestamp('cancelled_at')->nullable()->after('effective_applied_at');
            $table->foreignId('cancelled_by')->nullable()->after('cancelled_at')->constrained('users')->nullOnDelete();
            $table->string('cancellation_reason', 255)->nullable()->after('cancelled_by');

            // The foreign key needs an index of its own before the unique one can go.
            $table->index('employee_id', 'employee_separations_employee');
            $table->index(['cancelled_at', 'effective_applied_at', 'separation_date'], 'employee_separations_due');
        });

        Schema::table('employee_separations', function (Blueprint $table): void {
            $table->dropUnique(['employee_id']);
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->string('access_source', 30)->nullable()->after('access_reason');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('access_source');
        });

        Schema::table('employee_separations', function (Blueprint $table): void {
            $table->unique('employee_id');
        });

        Schema::table('employee_separations', function (Blueprint $table): void {
            $table->dropIndex('employee_separations_due');
            $table->dropIndex('employee_separations_employee');
            $table->dropConstrainedForeignId('cancelled_by');
            $table->dropColumn(['effective_applied_at', 'cancelled_at', 'cancellation_reason']);
        });
    }
};
