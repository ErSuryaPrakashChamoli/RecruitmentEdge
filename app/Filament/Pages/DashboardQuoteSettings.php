<?php

namespace App\Filament\Pages;

use App\Models\RecruitmentSetting;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Blade;
use UnitEnum;

/**
 * Lets an admin set the Dashboard greeting's "quote of the day" line and its trailing icon
 * (App\Filament\Pages\Dashboard::getSubheading() reads both via RecruitmentSetting — the same
 * generic key/value store RecruitmentSettingResource already uses, just with a purpose-built form
 * here instead of a raw key/value row, since the icon needs a visual picker).
 *
 * @property-read Schema $form
 */
class DashboardQuoteSettings extends Page implements HasForms
{
    use InteractsWithForms;

    protected string $view = 'filament.pages.dashboard-quote-settings';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChatBubbleBottomCenterText;

    protected static string|UnitEnum|null $navigationGroup = 'Administration';

    protected static ?string $navigationLabel = 'Dashboard Quote';

    /**
     * @var array<string, mixed>|null
     */
    public ?array $data = [];

    /**
     * A curated shortlist, not the full Heroicon set (hundreds of icons across outline/solid/mini
     * variants) — impractical to browse in a single-select dropdown. Covers the tones a "quote of
     * the day" line would realistically want: motivational, celebratory, reflective.
     *
     * @var array<string, string>
     */
    private const array ICON_OPTIONS = [
        'heroicon-o-sparkles' => 'Sparkles',
        'heroicon-o-light-bulb' => 'Light Bulb',
        'heroicon-o-star' => 'Star',
        'heroicon-o-fire' => 'Fire',
        'heroicon-o-sun' => 'Sun',
        'heroicon-o-trophy' => 'Trophy',
        'heroicon-o-heart' => 'Heart',
        'heroicon-o-rocket-launch' => 'Rocket Launch',
        'heroicon-o-flag' => 'Flag',
        'heroicon-o-academic-cap' => 'Academic Cap',
        'heroicon-o-bolt' => 'Bolt',
        'heroicon-o-hand-thumb-up' => 'Thumbs Up',
        'heroicon-o-gift' => 'Gift',
        'heroicon-o-megaphone' => 'Megaphone',
        'heroicon-o-shield-check' => 'Shield Check',
        'heroicon-o-face-smile' => 'Smiley Face',
        'heroicon-o-globe-alt' => 'Globe',
        'heroicon-o-check-badge' => 'Check Badge',
    ];

    public static function canAccess(): bool
    {
        return (bool) Filament::auth()->user()?->can('settings.manage');
    }

    public function mount(): void
    {
        $this->form->fill([
            'quote' => RecruitmentSetting::get('dashboard.quote_text', ''),
            'icon' => RecruitmentSetting::get('dashboard.quote_icon', 'heroicon-o-sparkles'),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Form::make([
                    Textarea::make('quote')
                        ->label('Quote of the Day')
                        ->helperText('Shown on the Dashboard beneath the greeting. Leave blank to hide this line entirely.')
                        ->rows(3)
                        ->maxLength(280),
                    Select::make('icon')
                        ->label('Icon')
                        ->options(self::iconOptionsWithPreviews())
                        ->allowHtml()
                        ->native(false)
                        ->required()
                        ->searchable(false),
                ])
                    ->livewireSubmitHandler('save')
                    ->footer([
                        Actions::make([
                            Action::make('save')
                                ->submit('save')
                                ->keyBindings(['mod+s']),
                        ]),
                    ]),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        $data = $this->form->getState();

        RecruitmentSetting::put('dashboard.quote_text', (string) $data['quote'], group: 'dashboard', description: 'Dashboard greeting quote of the day');
        RecruitmentSetting::put('dashboard.quote_icon', (string) $data['icon'], group: 'dashboard', description: 'Icon shown next to the dashboard quote of the day');

        Notification::make()
            ->title('Dashboard quote updated')
            ->success()
            ->send();
    }

    /**
     * @return array<string, string>
     */
    private static function iconOptionsWithPreviews(): array
    {
        return collect(self::ICON_OPTIONS)
            ->mapWithKeys(fn (string $label, string $icon) => [
                $icon => Blade::render(
                    '<span class="flex items-center gap-2"><x-filament::icon :icon="$icon" class="h-4 w-4" />{{ $label }}</span>',
                    ['icon' => $icon, 'label' => $label],
                ),
            ])
            ->all();
    }
}
