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
        Schema::create('recruitment_incentive_slabs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('incentive_rule_id')->constrained('recruitment_incentive_rules')->cascadeOnDelete();
            $table->decimal('achievement_min', 6, 2);
            $table->decimal('achievement_max', 6, 2)->nullable();
            $table->decimal('amount', 12, 2);
            $table->timestamps();

            $table->index('incentive_rule_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('recruitment_incentive_slabs');
    }
};
