<?php

namespace App\Enums;

enum ActivityOutcome: string
{
    case Connected = 'connected';
    case NoAnswer = 'no_answer';
    case Busy = 'busy';
    case SwitchedOff = 'switched_off';
    case NotReachable = 'not_reachable';
    case InvalidNumber = 'invalid_number';
    case CallBackLater = 'call_back_later';
    case WrongNumber = 'wrong_number';
    case NotInterested = 'not_interested';

    public function label(): string
    {
        return match ($this) {
            self::Connected => 'Connected',
            self::NoAnswer => 'No Answer',
            self::Busy => 'Busy',
            self::SwitchedOff => 'Switched Off',
            self::NotReachable => 'Not Reachable',
            self::InvalidNumber => 'Invalid Number',
            self::CallBackLater => 'Call Back Later',
            self::WrongNumber => 'Wrong Number',
            self::NotInterested => 'Not Interested',
        };
    }

    /**
     * Only a real conversation counts as "connected" for the ConnectedCalls target metric — a
     * "Not Interested" outcome is deliberately excluded here, since it is recorded as a separate
     * outcome rather than a Connected call.
     */
    public function isConnected(): bool
    {
        return $this === self::Connected;
    }

    /**
     * The single source of truth for this outcome's badge color (Section 32: one badge system).
     */
    public function color(): string
    {
        return match ($this) {
            self::Connected => 'success',
            self::CallBackLater => 'info',
            self::NoAnswer, self::Busy, self::SwitchedOff, self::NotReachable => 'gray',
            self::InvalidNumber, self::WrongNumber, self::NotInterested => 'danger',
        };
    }
}
