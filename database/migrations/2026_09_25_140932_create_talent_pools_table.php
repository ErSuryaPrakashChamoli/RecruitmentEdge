<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('talent_pools', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->string('status', 20)->default('active');
            $table->string('visibility', 20)->default('team');
            $table->foreignId('owner_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->foreignId('department_id')->nullable()->constrained('departments')->nullOnDelete();
            $table->json('tags')->nullable();
            $table->text('criteria')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('employees')->nullOnDelete();
            $table->timestamp('archived_at')->nullable();
            $table->foreignId('archived_by')->nullable()->constrained('employees')->nullOnDelete();
            $table->timestamps();

            $table->index(['status', 'visibility']);
            $table->index('owner_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('talent_pools');
    }
};
