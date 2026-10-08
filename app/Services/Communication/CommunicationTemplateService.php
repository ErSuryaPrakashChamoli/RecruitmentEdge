<?php

namespace App\Services\Communication;

use App\Enums\AutomationRuleStatus;
use App\Enums\CommunicationChannel;
use App\Enums\TemplateStatus;
use App\Models\AutomationRule;
use App\Models\CommunicationTemplate;
use App\Models\CommunicationTemplateVersion;
use App\Models\Employee;
use App\Services\Automation\Actions\Handlers\SendCommunicationAction;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The only writer of communication template content (Phase 5). Validates variables against
 * TemplateRenderer::VARIABLES, bumps `version` on every wording change and snapshots each version
 * immutably, so any sent message can be traced to its exact text. Template rows are audited via
 * Auditable (created, updated, status changes).
 */
class CommunicationTemplateService
{
    public function __construct(private readonly TemplateRenderer $renderer) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, ?Employee $actor = null): CommunicationTemplate
    {
        $channel = $data['channel'] instanceof CommunicationChannel ? $data['channel'] : CommunicationChannel::from((string) $data['channel']);
        $this->validateContent($channel, $data['subject'] ?? null, (string) $data['body']);

        $key = Str::slug((string) ($data['key'] ?? $data['name']), '_');
        $language = (string) ($data['language'] ?? 'en');

        if (CommunicationTemplate::query()->where(['key' => $key, 'channel' => $channel, 'language' => $language])->exists()) {
            throw new DomainException("A {$channel->label()} template \"{$key}\" ({$language}) already exists.");
        }

        return DB::transaction(function () use ($data, $channel, $key, $language, $actor): CommunicationTemplate {
            $template = CommunicationTemplate::query()->create([
                ...array_intersect_key($data, array_flip(['name', 'subject', 'body', 'description', 'provider_template'])),
                'key' => $key,
                'channel' => $channel,
                'language' => $language,
                'status' => $data['status'] ?? TemplateStatus::Draft,
                'created_by' => $actor?->id,
                'updated_by' => $actor?->id,
            ]);

            $this->snapshot($template, $actor);

            return $template;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(CommunicationTemplate $template, array $data, ?Employee $actor = null): CommunicationTemplate
    {
        $subject = array_key_exists('subject', $data) ? $data['subject'] : $template->subject;
        $body = (string) ($data['body'] ?? $template->body);
        $this->validateContent($template->channel, $subject, $body);

        if (array_key_exists('status', $data)) {
            $this->guardArchive($template, $data['status'] instanceof TemplateStatus ? $data['status'] : TemplateStatus::from((string) $data['status']));
        }

        return DB::transaction(function () use ($template, $data, $subject, $body, $actor): CommunicationTemplate {
            // Phase 8.6 (D8.6-023): the approved WhatsApp template is part of what is sent, so a
            // change to it is a new version too.
            $wordingChanged = $subject !== $template->subject || $body !== $template->body
                || (array_key_exists('provider_template', $data) && ($data['provider_template'] ?: null) !== ($template->provider_template ?: null));

            $template->fill([
                ...array_intersect_key($data, array_flip(['name', 'description', 'provider_template', 'status'])),
                'subject' => $subject,
                'body' => $body,
                'updated_by' => $actor?->id,
            ]);

            if ($wordingChanged) {
                $template->forceFill(['version' => $template->version + 1]);
            }

            $template->save();

            if ($wordingChanged) {
                $this->snapshot($template, $actor);
            }

            return $template;
        });
    }

    public function setStatus(CommunicationTemplate $template, TemplateStatus $status, ?Employee $actor = null): CommunicationTemplate
    {
        $this->guardArchive($template, $status);
        $template->update(['status' => $status, 'updated_by' => $actor?->id]);

        return $template;
    }

    /**
     * Phase 8.6 (D8.6-022): active or paused automation rules that send this template's key (as an
     * action or an escalation step's candidate message).
     *
     * @return Collection<int, AutomationRule>
     */
    public function rulesUsing(CommunicationTemplate $template): Collection
    {
        return AutomationRule::query()
            ->whereIn('status', [AutomationRuleStatus::Active, AutomationRuleStatus::Paused])
            ->get()
            ->filter(fn (AutomationRule $rule) => collect($rule->actions ?? [])->contains(fn (array $action) => ($action['config']['template_key'] ?? $action['template_key'] ?? null) === $template->key)
                || collect($rule->escalation['steps'] ?? [])->contains(fn (array $step) => ($step['candidate_template'] ?? null) === $template->key))
            ->values();
    }

    /**
     * A warning for archiving a template the application itself sends automatically (interview,
     * offer, application and reminder messages): archiving stops those messages. Null otherwise.
     */
    public function builtInArchiveWarning(CommunicationTemplate $template): ?string
    {
        return in_array($template->key, SendCommunicationAction::reservedTemplateKeys(), true)
            ? "\"{$template->key}\" is sent automatically by the application; while no active template has this key on this channel, those messages are not sent."
            : null;
    }

    private function guardArchive(CommunicationTemplate $template, TemplateStatus $status): void
    {
        if ($status !== TemplateStatus::Archived || $template->status === TemplateStatus::Archived) {
            return;
        }

        $rules = $this->rulesUsing($template);

        if ($rules->isNotEmpty()) {
            throw new DomainException('This template is used by active or paused automation rules ('.$rules->pluck('name')->implode(', ').'). Change or archive those rules first.');
        }
    }

    private function validateContent(CommunicationChannel $channel, ?string $subject, string $body): void
    {
        if (! in_array($channel, CommunicationChannel::sendable(), true)) {
            throw new DomainException("{$channel->label()} is not a channel messages can be sent on.");
        }

        if ($channel->supportsSubject() && blank($subject)) {
            throw new DomainException('Email templates need a subject.');
        }

        if (blank($body)) {
            throw new DomainException('A template needs a body.');
        }

        if ($channel === CommunicationChannel::Sms && mb_strlen($body) > 1000) {
            throw new DomainException('SMS templates are limited to 1,000 characters.');
        }

        $this->renderer->validate((string) $subject, $body);
    }

    private function snapshot(CommunicationTemplate $template, ?Employee $actor): void
    {
        CommunicationTemplateVersion::query()->create([
            'communication_template_id' => $template->id,
            'version' => $template->version,
            'subject' => $template->subject,
            'body' => $template->body,
            'provider_template' => $template->provider_template,
            'created_by' => $actor?->id,
        ]);
    }
}
