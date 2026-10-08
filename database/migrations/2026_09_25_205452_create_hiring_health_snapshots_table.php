<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Hiring Health time series per requisition. Each snapshot keeps the exact metrics, thresholds and
     * values it was computed from; history is never recomputed.
     */
    public function up(): void
    {
        Schema::create('hiring_health_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('requisition_id')->constrained('recruitment_requisitions')->cascadeOnDelete();
            $table->string('status', 30);
            $table->string('rules_version', 40);
            $table->json('metrics');
            $table->unsignedSmallInteger('breach_count')->default(0);
            $table->unsignedSmallInteger('watch_count')->default(0);
            $table->decimal('completeness_pct', 5, 1)->default(0);
            $table->boolean('is_current')->default(true);
            $table->timestamp('computed_at');
            $table->timestamps();

            $table->index(['requisition_id', 'is_current'], 'hiring_health_req_current');
            $table->index(['status', 'is_current'], 'hiring_health_status_current');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hiring_health_snapshots');
    }
};
