<?php

namespace App\Enums;

/**
 * Kinds of event recorded in `candidate_timeline_events` — the unified timeline's own store for
 * events that have no other authoritative table (notes, portal activity, referrals, talent pools,
 * duplicate decisions, self-scheduling, documents, and Phase 5 provider-sent communications).
 * Stage changes, interviews, offers, structured call/WhatsApp/email activities and follow-ups are
 * NOT copied here — CandidateTimelineService reads them from their own tables, so there is never a
 * second source of truth.
 */
enum TimelineEventType: string
{
    case Note = 'note';
    case Call = 'call';
    case CallAttempt = 'call_attempt';
    case CallConnected = 'call_connected';
    case WhatsApp = 'whatsapp';
    case Email = 'email';
    case Sms = 'sms';
    case InterviewScheduled = 'interview_scheduled';
    case InterviewConfirmed = 'interview_confirmed';
    case InterviewRescheduled = 'interview_rescheduled';
    case InterviewCancelled = 'interview_cancelled';
    case RescheduleRequested = 'reschedule_requested';
    case DocumentUploaded = 'document_uploaded';
    case ProfileUpdated = 'profile_updated';
    case PortalAccess = 'portal_access';
    case Referral = 'referral';
    case TalentPool = 'talent_pool';
    case DuplicateDetected = 'duplicate_detected';
    case DuplicateOverride = 'duplicate_override';
    case RecruiterAction = 'recruiter_action';
    case SystemEvent = 'system_event';

    public function label(): string
    {
        return match ($this) {
            self::Note => 'Note',
            self::Call => 'Call',
            self::CallAttempt => 'Call attempt',
            self::CallConnected => 'Call connected',
            self::WhatsApp => 'WhatsApp',
            self::Email => 'Email',
            self::Sms => 'SMS',
            self::InterviewScheduled => 'Interview scheduled',
            self::InterviewConfirmed => 'Interview confirmed',
            self::InterviewRescheduled => 'Interview rescheduled',
            self::InterviewCancelled => 'Interview cancelled',
            self::RescheduleRequested => 'Reschedule requested',
            self::DocumentUploaded => 'Document uploaded',
            self::ProfileUpdated => 'Profile updated',
            self::PortalAccess => 'Portal access',
            self::Referral => 'Referral',
            self::TalentPool => 'Talent pool',
            self::DuplicateDetected => 'Possible duplicate',
            self::DuplicateOverride => 'Duplicate override',
            self::RecruiterAction => 'Recruiter action',
            self::SystemEvent => 'System',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Note => 'heroicon-o-pencil-square',
            self::Call, self::CallAttempt, self::CallConnected => 'heroicon-o-phone',
            self::WhatsApp, self::Sms => 'heroicon-o-chat-bubble-left-ellipsis',
            self::Email => 'heroicon-o-envelope',
            self::InterviewScheduled, self::InterviewConfirmed, self::InterviewRescheduled, self::InterviewCancelled, self::RescheduleRequested => 'heroicon-o-calendar-days',
            self::DocumentUploaded => 'heroicon-o-paper-clip',
            self::ProfileUpdated => 'heroicon-o-user-circle',
            self::PortalAccess => 'heroicon-o-key',
            self::Referral => 'heroicon-o-user-group',
            self::TalentPool => 'heroicon-o-rectangle-group',
            self::DuplicateDetected, self::DuplicateOverride => 'heroicon-o-document-duplicate',
            self::RecruiterAction => 'heroicon-o-briefcase',
            self::SystemEvent => 'heroicon-o-cog-6-tooth',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::InterviewConfirmed, self::CallConnected => 'success',
            self::InterviewRescheduled, self::RescheduleRequested, self::DuplicateDetected, self::DuplicateOverride => 'warning',
            self::InterviewCancelled => 'danger',
            self::InterviewScheduled, self::DocumentUploaded, self::Referral, self::TalentPool, self::ProfileUpdated => 'info',
            default => 'gray',
        };
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $type) => [$type->value => $type->label()])->all();
    }
}
