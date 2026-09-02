<?php

namespace App\Enums;

enum AiUsageRequestType: string
{
    case Chat = 'chat';
    case Embedding = 'embedding';
    case ToolCall = 'tool_call';
    case WebSearch = 'web_search';

    public function label(): string
    {
        return match ($this) {
            self::Chat => 'Chat',
            self::Embedding => 'Embedding',
            self::ToolCall => 'Tool Call',
            self::WebSearch => 'Web Search',
        };
    }
}
