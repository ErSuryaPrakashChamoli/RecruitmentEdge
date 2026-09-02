<?php

namespace App\Enums;

enum AiDocumentStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Indexed = 'indexed';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Processing => 'Processing',
            self::Indexed => 'Indexed',
            self::Failed => 'Failed',
        };
    }
}
