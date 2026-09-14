<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lets documents be attached to a candidate before joining: candidate_documents gains a
 * candidate_id FK and candidate_joining_id becomes optional. Existing joining documents are
 * backfilled with the candidate from joining → application → candidate.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('candidate_documents', function (Blueprint $table) {
            $table->foreignId('candidate_id')->nullable()->after('id')->constrained('candidates')->cascadeOnDelete();
        });

        Schema::table('candidate_documents', function (Blueprint $table) {
            $table->foreignId('candidate_joining_id')->nullable()->change();
        });

        DB::table('candidate_documents')
            ->whereNull('candidate_id')
            ->whereNotNull('candidate_joining_id')
            ->update([
                'candidate_id' => DB::raw(
                    '(select candidate_applications.candidate_id from candidate_joinings'
                    .' inner join candidate_applications on candidate_applications.id = candidate_joinings.candidate_application_id'
                    .' where candidate_joinings.id = candidate_documents.candidate_joining_id)'
                ),
            ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('candidate_documents')->whereNull('candidate_joining_id')->delete();

        Schema::table('candidate_documents', function (Blueprint $table) {
            $table->foreignId('candidate_joining_id')->nullable(false)->change();
        });

        Schema::table('candidate_documents', function (Blueprint $table) {
            $table->dropConstrainedForeignId('candidate_id');
        });
    }
};
