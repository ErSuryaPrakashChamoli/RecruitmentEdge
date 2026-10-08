<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 8.3: released offers are immutable through ordinary edits. A change to a released offer is
 * a revision — requested with a reason, released only by someone with offers.release — and every
 * set of terms ever released is kept here (revision 1 is the original release, recorded when the
 * first revision is requested). Typed term columns, no candidate personal data.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('offer_revisions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('offer_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('revision');
            $table->string('status', 20);
            $table->foreignId('designation_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('location_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('offered_ctc', 12, 2)->nullable();
            $table->decimal('fixed_salary', 12, 2)->nullable();
            $table->decimal('variable_salary', 12, 2)->nullable();
            $table->decimal('joining_bonus', 12, 2)->nullable();
            $table->date('offer_expiry')->nullable();
            $table->date('expected_joining_date')->nullable();
            $table->longText('offer_letter_body')->nullable();
            $table->string('reason');
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();

            $table->unique(['offer_id', 'revision'], 'offer_revisions_offer_revision');
            $table->index(['offer_id', 'status'], 'offer_revisions_offer_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('offer_revisions');
    }
};
