<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The external calendar event mirroring an interview (Phase 5). The interview itself stays the
     * authoritative record; this only maps it to the provider's event id so updates/cancellations
     * reach the same event and a retry never creates a second one.
     */
    public function up(): void
    {
        Schema::create('interview_calendar_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('interview_id')->constrained('interviews')->cascadeOnDelete();
            $table->foreignId('calendar_connection_id')->nullable()->constrained('calendar_connections')->nullOnDelete();
            $table->string('provider', 30);
            $table->string('external_event_id')->nullable();
            $table->string('status', 20)->default('pending');
            $table->text('last_error')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            $table->unique(['interview_id', 'provider']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('interview_calendar_events');
    }
};
