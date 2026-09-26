<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One rediscovered candidate per run, with why (evidence) and what a person decided.
     */
    public function up(): void
    {
        Schema::create('rediscovery_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rediscovery_run_id')->constrained()->cascadeOnDelete();
            $table->foreignId('candidate_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('rank');
            $table->string('band', 30);
            $table->decimal('required_coverage_pct', 5, 1)->nullable();
            $table->json('summary');
            $table->boolean('do_not_contact')->default(false);
            $table->string('status', 30)->default('suggested');
            $table->foreignId('actioned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('actioned_at')->nullable();
            $table->string('action_note')->nullable();
            $table->timestamps();

            $table->unique(['rediscovery_run_id', 'candidate_id'], 'rediscovery_results_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rediscovery_results');
    }
};
