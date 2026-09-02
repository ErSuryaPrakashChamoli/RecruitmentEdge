<?php

namespace App\Enums;

enum AiMessageRole: string
{
    case System = 'system';
    case User = 'user';
    case Assistant = 'assistant';
    case Tool = 'tool';

    public function label(): string
    {
        return match ($this) {
            self::System => 'System',
            self::User => 'User',
            self::Assistant => 'Assistant',
            self::Tool => 'Tool',
        };
    }
}
