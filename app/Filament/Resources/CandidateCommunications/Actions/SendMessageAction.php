<?php

namespace App\Filament\Resources\CandidateCommunications\Actions;

use App\Enums\CommunicationChannel;
use App\Enums\CommunicationStatus;
use App\Enums\TemplateStatus;
use App\Filament\Resources\Candidates\Schemas\CandidatePicker;
use App\Filament\Resources\Interviews\Tables\InterviewsTable;
use App\Models\Candidate;
use App\Models\CandidateApplication;
use App\Models\CommunicationTemplate;
use App\Services\Communication\CommunicationPreferenceService;
use App\Services\Communication\CommunicationService;
use App\Services\Communication\MessageContext;
use App\Services\Communication\TemplateRenderer;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Support\Str;
use Throwable;

/**
 * "Send message" — the Communication Center's compose action, also on the Candidate 360 page.
 * Everything real (preference/consent, provider availability, rendering, timeline, audit, queue)
 * happens in CommunicationService; this only collects input and shows a live preview.
 */
class SendMessageAction
{
    public static function make(?Candidate $candidate = null): Action
    {
        return Action::make('sendMessage')
            ->label('Send message')
            ->icon('heroicon-o-paper-airplane')
            ->visible(fn (): bool => (bool) auth()->user()?->can('communications.send')
                && ($candidate === null || (bool) auth()->user()?->can('view', $candidate)))
            ->modalWidth('3xl')
            ->schema(fn (): array => [
                CandidatePicker::scoped('candidate_id')
                    ->required()
                    ->live()
                    ->hidden($candidate !== null)
                    ->default($candidate?->id),
                Select::make('candidate_application_id')
                    ->label('About application')
                    ->options(fn (Get $get): array => self::applicationOptions($candidate?->id ?? $get('candidate_id')))
                    ->placeholder('No specific application')
                    ->live(),
                Select::make('channel')
                    ->options(CommunicationChannel::options(sendableOnly: true))
                    ->default(CommunicationChannel::Email->value)
                    ->required()
                    ->live(),
                Select::make('communication_template_id')
                    ->label('Template')
                    ->options(fn (Get $get): array => CommunicationTemplate::query()
                        ->where('status', TemplateStatus::Active)
                        ->where('channel', $get('channel'))
                        ->orderBy('name')
                        ->pluck('name', 'id')
                        ->all())
                    ->placeholder('Write a custom message')
                    ->live(),
                TextInput::make('subject')
                    ->maxLength(255)
                    ->visible(fn (Get $get): bool => $get('channel') === CommunicationChannel::Email->value && blank($get('communication_template_id')))
                    ->required(fn (Get $get): bool => $get('channel') === CommunicationChannel::Email->value && blank($get('communication_template_id'))),
                Textarea::make('body')
                    ->label('Message')
                    ->rows(6)
                    ->maxLength(5000)
                    ->helperText('You may use variables such as {{candidate.first_name}} or {{interview.date}}.')
                    ->visible(fn (Get $get): bool => blank($get('communication_template_id')))
                    ->required(fn (Get $get): bool => blank($get('communication_template_id')))
                    ->live(debounce: 600),
                TextEntry::make('delivery_check')
                    ->label('Delivery')
                    ->state(fn (Get $get): string => self::deliveryNote($candidate?->id ?? $get('candidate_id'), $get('channel')))
                    ->color(fn (Get $get): string => str_starts_with(self::deliveryNote($candidate?->id ?? $get('candidate_id'), $get('channel')), 'Will not') ? 'danger' : 'gray'),
                TextEntry::make('preview')
                    ->label('Preview')
                    ->state(fn (Get $get): string => self::preview($candidate?->id ?? $get('candidate_id'), $get))
                    ->extraAttributes(['class' => 'whitespace-pre-line']),
            ])
            ->action(function (array $data) use ($candidate): void {
                $target = $candidate ?? CandidatePicker::selectableCandidates()->findOrFail($data['candidate_id']);

                abort_unless((bool) auth()->user()?->can('communications.send') && (bool) auth()->user()?->can('view', $target), 403);

                $context = self::context($target, $data['candidate_application_id'] ?? null);
                $template = filled($data['communication_template_id'] ?? null) ? CommunicationTemplate::query()->findOrFail($data['communication_template_id']) : null;

                $message = InterviewsTable::guarded('Message could not be sent', fn () => app(CommunicationService::class)->send(
                    CommunicationChannel::from($data['channel']),
                    $context,
                    $template,
                    $data['subject'] ?? null,
                    $data['body'] ?? null,
                    auth()->user()?->employee,
                ));

                $message->status === CommunicationStatus::Blocked
                    ? Notification::make()->title('Message not sent')->body($message->blocked_reason)->warning()->persistent()->send()
                    : Notification::make()->title('Message queued for sending')->success()->send();
            });
    }

    /**
     * Records the message is about: the chosen application (only one of this candidate's own) and
     * its next open interview, latest offer and joining record, so interview/offer variables work.
     */
    public static function context(Candidate $candidate, mixed $applicationId): MessageContext
    {
        $application = filled($applicationId) ? $candidate->applications()->find($applicationId) : null;

        return new MessageContext(
            $candidate,
            $application,
            $application?->interviews()->whereNotIn('status', ['completed', 'cancelled', 'no_show'])->orderBy('scheduled_at')->first(),
            $application?->offers()->latest()->first(),
            $application?->joining,
        );
    }

    /**
     * @return array<int, string>
     */
    private static function applicationOptions(mixed $candidateId): array
    {
        if (blank($candidateId)) {
            return [];
        }

        return CandidateApplication::query()
            ->where('candidate_id', $candidateId)
            ->with('requisition.designation')
            ->get()
            ->mapWithKeys(fn (CandidateApplication $a) => [$a->id => $a->application_code.' · '.($a->requisition?->designation?->name ?? $a->requisition?->code)])
            ->all();
    }

    private static function deliveryNote(mixed $candidateId, ?string $channel): string
    {
        $candidate = filled($candidateId) ? CandidatePicker::selectableCandidates()->find($candidateId) : null;
        $channel = CommunicationChannel::tryFrom((string) $channel);

        if ($candidate === null || $channel === null) {
            return 'Choose a candidate and channel.';
        }

        $recipient = app(CommunicationService::class)->recipientFor($candidate, $channel);
        $blocked = $recipient === null ? "no {$channel->label()} contact on file." : app(CommunicationPreferenceService::class)->blockedReason($candidate, $channel);

        return $blocked !== null ? "Will not be sent: {$blocked}" : "To {$recipient} — {$channel->label()} preference: ".app(CommunicationPreferenceService::class)->statusFor($candidate, $channel)->label();
    }

    private static function preview(mixed $candidateId, Get $get): string
    {
        $candidate = filled($candidateId) ? CandidatePicker::selectableCandidates()->find($candidateId) : null;
        $template = filled($get('communication_template_id')) ? CommunicationTemplate::query()->find($get('communication_template_id')) : null;
        $subject = $template?->subject ?? $get('subject');
        $body = $template?->body ?? (string) $get('body');

        if ($candidate === null || blank($body)) {
            return '—';
        }

        try {
            $renderer = app(TemplateRenderer::class);
            $context = self::context($candidate, $get('candidate_application_id'));

            return (filled($subject) ? 'Subject: '.$renderer->render($subject, $context)."\n\n" : '').$renderer->render($body, $context);
        } catch (Throwable $e) {
            return 'Cannot preview: '.Str::limit($e->getMessage(), 200);
        }
    }
}
