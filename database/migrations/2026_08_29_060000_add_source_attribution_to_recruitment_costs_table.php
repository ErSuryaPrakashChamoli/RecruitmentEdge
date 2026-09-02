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
        Schema::table('recruitment_costs', function (Blueprint $table): void {
            $table->foreignId('source_id')->nullable()->after('department_id')->constrained('candidate_sources')->cascadeOnDelete();
            $table->foreignId('location_id')->nullable()->after('source_id')->constrained('locations')->cascadeOnDelete();
            $table->string('campaign')->nullable()->after('cost_type');
            $table->string('status')->default('draft')->after('amount');

            $table->index('source_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('recruitment_costs', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('source_id');
            $table->dropConstrainedForeignId('location_id');
            $table->dropColumn(['campaign', 'status']);
        });
    }
};
