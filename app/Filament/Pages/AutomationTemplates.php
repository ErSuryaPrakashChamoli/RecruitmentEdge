<?php

namespace App\Filament\Pages;

use App\Filament\Resources\AutomationRules\AutomationRuleActions;
use App\Filament\Resources\AutomationRules\AutomationRuleResource;
use App\Models\AutomationRule;
use App\Services\Automation\AutomationEventRegistry;
use App\Services\Automation\AutomationRuleService;
use App\Services\Automation\AutomationTemplateCatalog;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
use UnitEnum;

/**
 * Automation templates (Phase 6): ready-made starting points. "Use template" creates a Draft rule
 * (scoped to the user's team unless they may create organization-wide rules) to review, adjust,
 * dry-run and activate — nothing is activated automatically.
 */
class AutomationTemplates extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSquares2x2;

    protected static string|UnitEnum|null $navigationGroup = 'Automation';

    protected static ?string $navigationLabel = 'Templates';

    protected static ?int $navigationSort = 3;

    protected static ?string $title = 'Automation Templates';

    protected string $view = 'filament.pages.automation-templates';

    public static function canAccess(): bool
    {
        return auth()->user()?->can('automation.view') ?? false;
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function getTemplates(): Collection
    {
        $events = app(AutomationEventRegistry::class);
        $used = AutomationRule::query()->whereNotNull('template_key')->get(['template_key', 'status'])->groupBy('template_key');

        return app(AutomationTemplateCatalog::class)->all()->values()->map(fn (array $template) => [
            ...$template,
            'trigger_label' => $events->find($template['rule']['trigger'])?->label,
            'rules' => $used->get($template['key'], collect())->count(),
            'active' => $used->get($template['key'], collect())->contains(fn ($rule) => $rule->isActive()),
        ]);
    }

    public function canUse(): bool
    {
        return auth()->user()?->can('automation.manage') ?? false;
    }

    public function useTemplate(string $key): void
    {
        abort_unless($this->canUse(), 403);

        $rule = AutomationRuleActions::guarded('The template could not be used', fn () => app(AutomationRuleService::class)->createFromTemplate($key, auth()->user()));

        Notification::make()->title('Draft rule created')->body('Review it, run a dry run, then activate it.')->success()->send();
        $this->redirect(AutomationRuleResource::getUrl('edit', ['record' => $rule]));
    }
}
