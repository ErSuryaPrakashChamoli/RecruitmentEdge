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
        Schema::create('ai_action_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('conversation_id')->nullable()->constrained('ai_conversations')->nullOnDelete();
            $table->string('tool_name');
            $table->string('risk_level');
            $table->string('entity_type')->nullable();
            $table->json('entity_ids')->nullable();
            $table->json('input')->nullable();
            $table->text('result_summary')->nullable();
            $table->string('status');
            $table->timestamps();

            $table->index('user_id');
            $table->index(['tool_name', 'created_at'], 'ai_action_logs_tool_created_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_action_logs');
    }
};
