<?php

namespace App\Filament\Resources\AiConversations\Pages;

use App\Filament\Resources\AiConversations\AiConversationResource;
use Filament\Resources\Pages\ViewRecord;

/**
 * No header actions: conversations are an audit record — reviewers read them, they never edit
 * or continue another user's conversation from here.
 */
class ViewAiConversation extends ViewRecord
{
    protected static string $resource = AiConversationResource::class;
}
