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
        Schema::table('offers', function (Blueprint $table) {
            $table->foreignId('offer_letter_template_id')
                ->nullable()
                ->after('remarks')
                ->constrained('offer_letter_templates')
                ->nullOnDelete();
            $table->longText('offer_letter_body')->nullable()->after('offer_letter_template_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('offers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('offer_letter_template_id');
            $table->dropColumn('offer_letter_body');
        });
    }
};
