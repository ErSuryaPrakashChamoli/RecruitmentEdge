<?php

namespace Database\Factories;

use App\Enums\AutomationRuleStatus;
use App\Models\AutomationRule;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;

/**
 * @extends Factory<AutomationRule>
 */
class AutomationRuleFactory extends Factory
{
    /**
     * Define the model's default state: a Draft, organization-wide rule on "interview scheduled"
     * that creates a recruiter action. Every created rule gets its version-1 snapshot, as the
     * service would.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'key' => 'rule_'.Str::lower(Str::random(10)),
            'name' => fake()->sentence(3),
            'trigger' => 'interview.scheduled',
            'conditions' => ['match' => 'all', 'negate' => false, 'rules' => []],
            'actions' => [
                ['type' => 'create_action', 'action_type' => 'confirm_interview', 'owner' => 'recruiter', 'priority' => 'high', 'title' => 'Confirm the interview', 'due_in_hours' => 4],
            ],
            'timing' => ['mode' => 'immediate'],
            'escalation' => ['steps' => [], 'stop_conditions' => null],
            // Phase 8.4: every rule has an accountable owner whose authority is re-checked when it runs.
            'owner_id' => User::factory(),
        ];
    }

    public function configure(): static
    {
        return $this->afterCreating(function (AutomationRule $rule): void {
            $rule->versions()->create(['version' => $rule->version + 1, 'snapshot' => $rule->configuration(), 'change_summary' => 'Factory']);
            $rule->forceFill(['version' => $rule->version + 1])->saveQuietly();

            // A factory-made owner can run the rule it owns (organisation-wide, like the default scope).
            $owner = $rule->owner;

            if ($owner !== null && $owner->roles()->doesntExist() && $owner->getDirectPermissions()->isEmpty()) {
                $owner->givePermissionTo(collect(['automation.activate', 'automation.organization'])->map(fn (string $name) => Permission::findOrCreate($name))->all());
            }
        });
    }

    public function active(): static
    {
        return $this->state(fn (): array => ['status' => AutomationRuleStatus::Active]);
    }

    /**
     * @param  array<string, mixed>|null  $conditions
     */
    public function on(string $trigger, ?array $conditions = null): static
    {
        return $this->state(fn (): array => array_filter(['trigger' => $trigger, 'conditions' => $conditions]));
    }

    /**
     * @param  array<int, array<string, mixed>>  $actions
     */
    public function withActions(array $actions): static
    {
        return $this->state(fn (): array => ['actions' => $actions]);
    }
}
