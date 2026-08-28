<?php

namespace App\Enums;

enum ActivityOutcome: string
{
    case Connected = 'connected';
    case NoAnswer = 'no_answer';
    case Busy = 'busy';
    case SwitchedOff = 'switched_off';
    case InvalidNumber = 'invalid_number';
    case CallBackLater = 'call_back_later';
    case WrongNumber = 'wrong_number';

    public function label(): string
    {
        return match ($this) {
            self::Connected => 'Connected',
            self::NoAnswer => 'No Answer',
            self::Busy => 'Busy',
            self::SwitchedOff => 'Switched Off',
            self::InvalidNumber => 'Invalid Number',
            self::CallBackLater => 'Call Back Later',
            self::WrongNumber => 'Wrong Number',
        };
    }

    public function isConnected(): bool
    {
        return $this === self::Connected;
    }
}
