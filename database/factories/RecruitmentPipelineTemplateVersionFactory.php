<?php

namespace Database\Factories;

use App\Models\RecruitmentPipelineTemplate;
use App\Models\RecruitmentPipelineTemplateVersion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RecruitmentPipelineTemplateVersion>
 */
class RecruitmentPipelineTemplateVersionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'pipeline_template_id' => RecruitmentPipelineTemplate::factory(),
            'version' => 1,
            'stages' => [],
        ];
    }
}
