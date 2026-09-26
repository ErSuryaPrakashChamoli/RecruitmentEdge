<?php

use App\Services\CandidateIdentityNormalizer;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Indexed, normalised copies of the identifiers duplicate detection matches on, so a lookup
     * stays an index seek on large candidate tables instead of normalising every row at query
     * time. Maintained by Candidate's saving hook; existing rows are backfilled here in chunks.
     */
    public function up(): void
    {
        Schema::table('candidates', function (Blueprint $table) {
            $table->string('mobile_normalized', 20)->nullable()->after('mobile');
            $table->string('alternate_mobile_normalized', 20)->nullable()->after('alternate_mobile');
            $table->string('email_normalized')->nullable()->after('email');
            $table->string('name_normalized')->nullable()->after('full_name');

            $table->index('mobile_normalized');
            $table->index('alternate_mobile_normalized');
            $table->index('email_normalized');
            $table->index('name_normalized');
        });

        DB::table('candidates')
            ->select(['id', 'full_name', 'mobile', 'alternate_mobile', 'email'])
            ->orderBy('id')
            ->chunkById(500, function ($candidates): void {
                foreach ($candidates as $candidate) {
                    DB::table('candidates')->where('id', $candidate->id)->update([
                        'mobile_normalized' => CandidateIdentityNormalizer::mobile($candidate->mobile),
                        'alternate_mobile_normalized' => CandidateIdentityNormalizer::mobile($candidate->alternate_mobile),
                        'email_normalized' => CandidateIdentityNormalizer::email($candidate->email),
                        'name_normalized' => CandidateIdentityNormalizer::name($candidate->full_name),
                    ]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('candidates', function (Blueprint $table) {
            $table->dropIndex(['mobile_normalized']);
            $table->dropIndex(['alternate_mobile_normalized']);
            $table->dropIndex(['email_normalized']);
            $table->dropIndex(['name_normalized']);
            $table->dropColumn(['mobile_normalized', 'alternate_mobile_normalized', 'email_normalized', 'name_normalized']);
        });
    }
};
