<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A candidate's booking of an availability slot. Reschedules create a new booking pointing at
     * the one it replaced (`rescheduled_from_id`), so the full reschedule history is kept.
     */
    public function up(): void
    {
        Schema::create('interview_slot_bookings', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('slot_id')->constrained('interview_availability_slots')->restrictOnDelete();
            $table->foreignId('candidate_application_id')->constrained('candidate_applications')->cascadeOnDelete();
            $table->foreignId('candidate_id')->constrained('candidates')->cascadeOnDelete();
            $table->foreignId('interview_id')->nullable()->constrained('interviews')->nullOnDelete();
            $table->foreignId('invitation_id')->nullable()->constrained('interview_scheduling_invitations')->nullOnDelete();
            $table->foreignId('rescheduled_from_id')->nullable()->constrained('interview_slot_bookings')->nullOnDelete();
            $table->string('status', 20)->default('booked');
            $table->string('channel', 30);
            $table->timestamp('booked_at');
            $table->timestamp('cancelled_at')->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->text('candidate_note')->nullable();
            $table->timestamps();

            $table->index(['candidate_application_id', 'status'], 'isb_application_status_index');
            $table->index(['slot_id', 'status'], 'isb_slot_status_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('interview_slot_bookings');
    }
};
