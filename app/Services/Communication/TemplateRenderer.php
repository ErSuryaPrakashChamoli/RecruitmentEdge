<?php

namespace App\Services\Communication;

use DomainException;

/**
 * Renders communication templates by plain {{variable}} substitution from a fixed whitelist —
 * never Blade/PHP evaluation of stored text, so a template can't execute code or read data outside
 * VARIABLES. Values are inserted as plain text (the email view escapes them).
 */
class TemplateRenderer
{
    /**
     * @var array<string, string> variable => description
     */
    public const array VARIABLES = [
        'candidate.name' => 'Candidate full name',
        'candidate.first_name' => 'Candidate first name',
        'requisition.title' => 'Position title (designation)',
        'requisition.code' => 'Requisition reference',
        'requisition.location' => 'Position location',
        'application.reference' => 'Application reference',
        'interview.date' => 'Interview date',
        'interview.time' => 'Interview time',
        'interview.location' => 'Interview location or meeting link',
        'interview.mode' => 'Interview mode',
        'interview.round' => 'Interview round',
        'recruiter.name' => 'Recruiter name',
        'offer.joining_date' => 'Offered joining date',
        'joining.date' => 'Expected joining date',
        'company.name' => 'Company name',
        'links.portal' => 'Candidate portal link',
        'links.scheduling' => 'Self-scheduling link',
    ];

    private const string PATTERN = '/\{\{\s*([a-z_]+(?:\.[a-z_]+)*)\s*\}\}/';

    /**
     * Unknown variables in $text.
     *
     * @return array<int, string>
     */
    public function unknownVariables(string $text): array
    {
        preg_match_all(self::PATTERN, $text, $matches);

        return array_values(array_unique(array_diff($matches[1], array_keys(self::VARIABLES))));
    }

    /**
     * @return array<int, string>
     */
    public function usedVariables(string $text): array
    {
        preg_match_all(self::PATTERN, $text, $matches);

        return array_values(array_unique($matches[1]));
    }

    public function validate(string ...$texts): void
    {
        $unknown = array_unique(array_merge(...array_map(fn (string $t) => $this->unknownVariables($t), $texts)));

        if ($unknown !== []) {
            throw new DomainException('Unknown template variable(s): '.implode(', ', array_map(fn ($v) => '{{'.$v.'}}', $unknown)).'.');
        }

        if (preg_match('/\{\{(?!\s*[a-z_]+(?:\.[a-z_]+)*\s*\}\})/', implode("\n", $texts))) {
            throw new DomainException('A template contains a malformed {{ … }} placeholder.');
        }
    }

    /**
     * Renders $text; throws when a used variable has no value in this context (e.g. an interview
     * template sent without an interview), so an incomplete message is never sent.
     */
    public function render(string $text, MessageContext $context): string
    {
        $this->validate($text);
        $values = $this->values($context);

        return preg_replace_callback(self::PATTERN, function (array $match) use ($values): string {
            $value = $values[$match[1]] ?? null;

            if ($value === null || $value === '') {
                throw new DomainException("No value is available for {{{$match[1]}}} in this context.");
            }

            return $value;
        }, $text);
    }

    /**
     * Sample values for previewing a template without real records.
     */
    public function preview(string $text): string
    {
        $this->validate($text);

        return preg_replace_callback(self::PATTERN, fn (array $m) => '['.(self::VARIABLES[$m[1]] ?? $m[1]).']', $text);
    }

    /**
     * Ordered values for the variables used in $text (WhatsApp provider-template parameters).
     *
     * @return array<int, string>
     */
    public function parameters(string $text, MessageContext $context): array
    {
        $values = $this->values($context);

        return array_map(fn (string $v) => (string) ($values[$v] ?? ''), $this->usedVariables($text));
    }

    /**
     * @return array<string, string|null>
     */
    private function values(MessageContext $context): array
    {
        $candidate = $context->candidate;
        $application = $context->application;
        $requisition = $application?->requisition;
        $interview = $context->interview;

        return [
            'candidate.name' => $candidate->full_name,
            'candidate.first_name' => strtok((string) $candidate->full_name, ' ') ?: $candidate->full_name,
            'requisition.title' => $requisition?->designation?->name,
            'requisition.code' => $requisition?->code,
            'requisition.location' => $requisition?->location?->name,
            'application.reference' => $application?->application_code,
            'interview.date' => $interview?->scheduled_at?->format('D, d M Y'),
            'interview.time' => $interview?->scheduled_at?->format('h:i A'),
            'interview.location' => $interview !== null ? ($interview->meeting_link ?: $interview->location ?: $interview->mode?->label()) : null,
            'interview.mode' => $interview?->mode?->label(),
            'interview.round' => $interview !== null ? (string) $interview->round_number : null,
            'recruiter.name' => $application?->recruiter?->fullName(),
            'offer.joining_date' => $context->offer?->expected_joining_date?->format('d M Y'),
            'joining.date' => $context->joining?->expected_doj?->format('d M Y'),
            'company.name' => (string) config('app.name'),
            'links.portal' => route('portal.login'),
            'links.scheduling' => $context->links['scheduling'] ?? null,
        ];
    }
}
