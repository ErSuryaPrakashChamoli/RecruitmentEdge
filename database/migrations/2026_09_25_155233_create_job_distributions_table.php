<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A posting's state on one channel (career site, XML feed, a job board). Unique per posting and
     * channel so a posting can never be published twice to the same place.
     */
    public function up(): void
    {
        Schema::create('job_distributions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('job_posting_id')->constrained('job_postings')->cascadeOnDelete();
            $table->string('channel', 40);
            $table->string('status', 20)->default('pending');
            $table->string('external_id')->nullable();
            $table->string('external_url', 500)->nullable();
            $table->text('last_error')->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('published_at')->nullable();
            $table->timestamp('unpublished_at')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();

            $table->unique(['job_posting_id', 'channel']);
            $table->index(['channel', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('job_distributions');
    }
};
