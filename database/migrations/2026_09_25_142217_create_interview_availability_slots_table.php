<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Interviewer availability for candidate self-scheduling (Phase 4). Times are stored in UTC;
     * `timezone` is the zone the slot was defined in and is displayed in.
     */
    public function up(): void
    {
        Schema::create('interview_availability_slots', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('interviewer_id')->constrained('employees')->restrictOnDelete();
            $table->foreignId('requisition_id')->nullable()->constrained('recruitment_requisitions')->nullOnDelete();
            $table->string('round_name')->nullable();
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->string('timezone', 64);
            $table->unsignedSmallInteger('capacity')->default(1);
            $table->unsignedSmallInteger('booked_count')->default(0);
            $table->string('status', 20)->default('available');
            $table->string('mode', 20);
            $table->string('location')->nullable();
            $table->string('meeting_link')->nullable();
            $table->dateTime('bookable_until')->nullable();
            $table->string('external_calendar_event_id')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('employees')->nullOnDelete();
            $table->foreignId('cancelled_by')->nullable()->constrained('employees')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->timestamps();

            $table->index(['status', 'starts_at'], 'ias_status_starts_index');
            $table->index(['interviewer_id', 'starts_at'], 'ias_interviewer_starts_index');
            $table->index('requisition_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('interview_availability_slots');
    }
};
