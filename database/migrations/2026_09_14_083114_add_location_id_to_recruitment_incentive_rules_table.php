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
        Schema::table('recruitment_incentive_rules', function (Blueprint $table) {
            $table->foreignId('location_id')
                ->nullable()
                ->after('designation_id')
                ->constrained('locations')
                ->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('recruitment_incentive_rules', function (Blueprint $table) {
            $table->dropConstrainedForeignId('location_id');
        });
    }
};
