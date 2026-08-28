<?php

namespace App\Filament\Pages;

use App\Models\AiKnowledgeArticle;
use App\Services\AiAssistantService;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
use UnitEnum;

/**
 * A keyword-search FAQ assistant over AiKnowledgeArticle — see AiAssistantService's docblock for
 * why this isn't a live LLM call.
 */
class AskAi extends Page implements HasForms
{
    use InteractsWithForms;

    protected string $view = 'filament.pages.ask-ai';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChatBubbleLeftRight;

    protected static string|UnitEnum|null $navigationGroup = 'AI Assistant';

    protected static ?string $navigationLabel = 'Ask AI';

    /**
     * @var array<string, mixed>
     */
    public array $data = [];

    public ?string $answer = null;

    /**
     * @var Collection<int, AiKnowledgeArticle>
     */
    public Collection $articles;

    public function mount(): void
    {
        $this->articles = collect();
        $this->form->fill();
    }

    public static function canAccess(): bool
    {
        return (bool) Filament::auth()->user()?->can('ai.query');
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Textarea::make('question')
                    ->label('Ask a recruitment or HR question')
                    ->placeholder('e.g. What is our notice period policy?')
                    ->rows(3)
                    ->required(),
            ])
            ->statePath('data');
    }

    public function ask(): void
    {
        $state = $this->form->getState();

        $result = app(AiAssistantService::class)->ask($state['question'], Filament::auth()->id());

        $this->answer = $result['answer'];
        $this->articles = $result['articles'];
    }
}
