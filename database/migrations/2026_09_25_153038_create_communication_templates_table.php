<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Reusable candidate message templates (Phase 5). `key` names the purpose (e.g.
     * interview_confirmation) so event-driven communications can find the active template for a
     * channel + language; `provider_template` holds a provider-approved template name (WhatsApp).
     */
    public function up(): void
    {
        Schema::create('communication_templates', function (Blueprint $table) {
            $table->id();
            $table->string('key', 80);
            $table->string('name');
            $table->string('channel', 20);
            $table->string('language', 10)->default('en');
            $table->string('subject')->nullable();
            $table->text('body');
            $table->string('status', 20)->default('draft');
            $table->unsignedInteger('version')->default(1);
            $table->text('description')->nullable();
            $table->string('provider_template')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('employees')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('employees')->nullOnDelete();
            $table->timestamps();

            $table->unique(['key', 'channel', 'language'], 'ct_key_channel_language_unique');
            $table->index(['status', 'channel']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('communication_templates');
    }
};
