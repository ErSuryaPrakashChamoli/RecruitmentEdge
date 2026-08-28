<?php

namespace Database\Seeders;

use App\Enums\RejectionCategory;
use App\Models\CandidateSource;
use App\Models\RecruitmentRejectionReason;
use Illuminate\Database\Seeder;

/**
 * Seeds the candidate sources (Section 33) and rejection/dropout reasons (Section 14) named in
 * the product spec, as editable starting points from Administration.
 */
class RecruitmentReferenceDataSeeder extends Seeder
{
    public function run(): void
    {
        $sources = [
            'Naukri', 'Indeed', 'LinkedIn', 'Apna', 'WorkIndia', 'Website', 'Employee Referral',
            'Walk-in', 'WhatsApp', 'Facebook', 'Instagram', 'Agency', 'Internal Database', 'Other',
        ];

        foreach ($sources as $index => $name) {
            CandidateSource::query()->firstOrCreate(
                ['code' => 'SRC-'.str_pad((string) ($index + 1), 3, '0', STR_PAD_LEFT)],
                ['name' => $name, 'is_active' => true],
            );
        }

        $reasons = [
            ['Not Interested', RejectionCategory::General],
            ['Salary Issue', RejectionCategory::General],
            ['Location Issue', RejectionCategory::General],
            ['Experience Mismatch', RejectionCategory::General],
            ['Qualification Mismatch', RejectionCategory::General],
            ['Notice Period', RejectionCategory::General],
            ['Selected Elsewhere', RejectionCategory::General],
            ['No Response', RejectionCategory::General],
            ['Interview Rejected', RejectionCategory::Interview],
            ['Offer Rejected', RejectionCategory::Offer],
            ['Did Not Join', RejectionCategory::Joining],
            ['Background Verification', RejectionCategory::Joining],
            ['Candidate Withdrew', RejectionCategory::General],
            ['Other', RejectionCategory::General],
        ];

        foreach ($reasons as $index => [$name, $category]) {
            RecruitmentRejectionReason::query()->firstOrCreate(
                ['code' => 'RSN-'.str_pad((string) ($index + 1), 3, '0', STR_PAD_LEFT)],
                ['name' => $name, 'category' => $category, 'is_active' => true],
            );
        }
    }
}
