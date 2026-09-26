<?php

namespace App\Filament\Resources\AiConversations;

use App\Filament\Resources\AiConversations\Pages\ListAiConversations;
use App\Filament\Resources\AiConversations\Pages\ViewAiConversation;
use App\Filament\Resources\AiConversations\Schemas\AiConversationInfolist;
use App\Filament\Resources\AiConversations\Tables\AiConversationsTable;
use App\Models\AiConversation;
use App\Models\User;
use App\Services\AI\Privacy\AiConversationVisibility;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Read-only review of every user's Copilot conversations — messages, tool calls, and tool results —
 * for support/audit. Gated by AiConversationPolicy::viewAny (ai.manage); users read their own
 * conversations on the AI Copilot page instead.
 */
class AiConversationResource extends Resource
{
    protected static ?string $model = AiConversation::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChatBubbleLeftRight;

    protected static string|UnitEnum|null $navigationGroup = 'AI Assistant';

    protected static ?string $navigationLabel = 'AI Conversations';

    protected static ?string $recordTitleAttribute = 'title';

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()
            ->with('user')
            ->withCount('messages');
        $viewer = auth()->user();

        return $viewer instanceof User
            ? app(AiConversationVisibility::class)->scope($query, $viewer)
            : $query->whereRaw('1 = 0');
    }

    public static function table(Table $table): Table
    {
        return AiConversationsTable::configure($table);
    }

    public static function infolist(Schema $schema): Schema
    {
        return AiConversationInfolist::configure($schema);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAiConversations::route('/'),
            'view' => ViewAiConversation::route('/{record}'),
        ];
    }
}
