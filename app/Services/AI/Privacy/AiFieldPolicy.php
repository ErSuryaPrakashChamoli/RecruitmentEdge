<?php

namespace App\Services\AI\Privacy;

/**
 * The single classification source for data that may reach an AI provider (Phase 8.1).
 *
 * AiProjector builds provider-bound representations from explicit allowlists; this denylist is
 * the second line of defence that AiPayloadSanitizer applies to every tool result before it is
 * persisted or replayed, so a tool that forgets to project cannot leak these keys.
 */
final class AiFieldPolicy
{
    /**
     * Keys never allowed in a provider-bound structure, at any depth (compared lower-case).
     */
    public const array PROHIBITED_KEYS = [
        // identity & contact
        'full_name', 'first_name', 'last_name', 'name_normalized',
        'email', 'candidate_email', 'recipient', 'recipient_email', 'email_normalized',
        'mobile', 'alternate_mobile', 'phone', 'mobile_normalized', 'alternate_mobile_normalized',
        'address', 'photo_path', 'resume_path', 'account_email',
        // compensation
        'current_salary', 'expected_salary', 'offered_ctc', 'fixed_salary', 'variable_salary',
        'joining_bonus', 'salary_min', 'salary_max', 'ctc', 'salary',
        // private free text & internal links
        'remarks', 'source_details', 'meeting_link', 'external_meeting_id', 'offer_letter_body',
        'correction_reason', 'justification',
        // secrets
        'password', 'remember_token', 'api_key', 'token', 'access_token', 'refresh_token', 'secret',
    ];

    /**
     * Key suffixes treated as prohibited (e.g. recruiter_email, manager_mobile, expected_ctc).
     */
    public const array PROHIBITED_SUFFIXES = ['_email', '_mobile', '_phone', '_salary', '_ctc', '_password', '_token'];

    /**
     * Fields that may reach the provider only for the named purpose. Everything not allowlisted
     * by an AiProjector method and not listed here is internal only.
     */
    public const array CONDITIONAL = [
        'current_city' => 'Location-fit reasoning only (city level, never an address).',
        'compensation_fit' => 'Derived category (within/above/below budget, unknown); the figures never leave the application.',
        'feedback_excerpt' => 'Only inside the interview-feedback summarisation prompt, truncated and scrubbed.',
        'performance_metrics' => 'Explicit recruiter-performance questions by a user with performance.view, hierarchy-checked, read-only.',
    ];

    public static function isProhibitedKey(string|int $key): bool
    {
        if (! is_string($key)) {
            return false;
        }

        $key = strtolower($key);

        if (in_array($key, self::PROHIBITED_KEYS, true)) {
            return true;
        }

        foreach (self::PROHIBITED_SUFFIXES as $suffix) {
            if (str_ends_with($key, $suffix)) {
                return true;
            }
        }

        return false;
    }
}
