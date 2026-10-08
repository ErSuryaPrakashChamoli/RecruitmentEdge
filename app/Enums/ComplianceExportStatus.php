<?php

namespace App\Enums;

enum ComplianceExportStatus: string
{
    case Requested = 'requested';
    case Running = 'running';
    case Ready = 'ready';
    case Failed = 'failed';
    case Expired = 'expired';

    public function label(): string
    {
        return ucfirst($this->value);
    }

    public function color(): string
    {
        return match ($this) {
            self::Requested, self::Running => 'info',
            self::Ready => 'success',
            self::Failed => 'danger',
            self::Expired => 'gray',
        };
    }
}
