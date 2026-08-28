<?php

namespace App\Enums;

enum InterviewMode: string
{
    case InPerson = 'in_person';
    case Phone = 'phone';
    case VideoCall = 'video_call';

    public function label(): string
    {
        return match ($this) {
            self::InPerson => 'In Person',
            self::Phone => 'Phone',
            self::VideoCall => 'Video Call',
        };
    }
}
