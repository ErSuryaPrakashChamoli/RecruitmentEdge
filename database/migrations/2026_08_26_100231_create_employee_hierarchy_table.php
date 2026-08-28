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
        Schema::create('employee_hierarchy', function (Blueprint $table) {
            $table->foreignId('ancestor_id')->constrained('employees')->cascadeOnDelete();
            $table->foreignId('descendant_id')->constrained('employees')->cascadeOnDelete();
            $table->unsignedInteger('depth');
            $table->timestamp('created_at')->nullable();

            $table->primary(['ancestor_id', 'descendant_id']);
            $table->index('descendant_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('employee_hierarchy');
    }
};
