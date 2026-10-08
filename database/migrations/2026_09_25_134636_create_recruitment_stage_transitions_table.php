<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Explicit allowed transitions between library stages. A stage with no rows here falls back to
     * the default forward rule (any later stage, without skipping a non-skippable one).
     */
    public function up(): void
    {
        Schema::create('recruitment_stage_transitions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('from_stage_id')->constrained('recruitment_stages')->cascadeOnDelete();
            $table->foreignId('to_stage_id')->constrained('recruitment_stages')->cascadeOnDelete();
            $table->boolean('requires_remarks')->default(false);
            $table->timestamps();

            $table->unique(['from_stage_id', 'to_stage_id'], 'rst_from_to_unique');
            $table->index('to_stage_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recruitment_stage_transitions');
    }
};
