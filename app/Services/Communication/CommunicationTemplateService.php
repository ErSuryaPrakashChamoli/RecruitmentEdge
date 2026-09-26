<?php

namespace App\Services\Communication;

use App\Enums\CommunicationChannel;
use App\Enums\TemplateStatus;
use App\Models\CommunicationTemplate;
use App\Models\CommunicationTemplateVersion;
use App\Models\Employee;
use DomainException;
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

        return DB::transaction(function () use ($template, $data, $subject, $body, $actor): CommunicationTemplate {
            $wordingChanged = $subject !== $template->subject || $body !== $template->body;

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
        $template->update(['status' => $status, 'updated_by' => $actor?->id]);

        return $template;
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
            'created_by' => $actor?->id,
        ]);
    }
}
