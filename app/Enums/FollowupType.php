<?php

namespace App\Enums;

enum FollowupType: string
{
    case Call = 'call';
    case WhatsApp = 'whatsapp';
    case Email = 'email';
    case InterviewConfirmation = 'interview_confirmation';
    case OfferFollowup = 'offer_followup';
    case JoiningConfirmation = 'joining_confirmation';
    case DocumentFollowup = 'document_followup';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Call => 'Call',
            self::WhatsApp => 'WhatsApp',
            self::Email => 'Email',
            self::InterviewConfirmation => 'Interview Confirmation',
            self::OfferFollowup => 'Offer Follow-up',
            self::JoiningConfirmation => 'Joining Confirmation',
            self::DocumentFollowup => 'Document Follow-up',
            self::Other => 'Other',
        };
    }
}
