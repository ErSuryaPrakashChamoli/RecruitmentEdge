<?php

namespace App\Enums;

/**
 * SaaS-6: an integration connection is used only while Active.
 */
enum ConnectionStatus: string
{
    case Active = 'active';
    case Disabled = 'disabled';

    public function label(): string
    {
        return ucfirst($this->value);
    }

    public function color(): string
    {
        return $this === self::Active ? 'success' : 'gray';
    }
}
