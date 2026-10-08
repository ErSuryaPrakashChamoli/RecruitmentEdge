<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Evidence of whether an external integration actually works: the result of the last explicit
     * "test connection" run per provider. "Operational" is only ever claimed from a successful row
     * here — never from configuration alone. Secrets are never stored in this table.
     */
    public function up(): void
    {
        Schema::create('integration_statuses', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 60)->unique();
            $table->string('category', 30);
            $table->boolean('last_test_ok')->nullable();
            $table->text('last_test_message')->nullable();
            $table->timestamp('last_tested_at')->nullable();
            $table->foreignId('last_tested_by')->nullable()->constrained('employees')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('integration_statuses');
    }
};
