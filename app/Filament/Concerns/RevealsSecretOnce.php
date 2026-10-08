<?php

namespace App\Filament\Concerns;

use Filament\Actions\Action;
use Filament\Infolists\Components\TextEntry;

/**
 * SaaS-6: shows a newly issued secret once, in a modal, right after it is issued — it lives only
 * in this browser's action state, never in the session, a notification or a log, and is never
 * shown again.
 */
trait RevealsSecretOnce
{
    public function revealSecretAction(): Action
    {
        return Action::make('revealSecret')
            ->modalHeading(fn (array $arguments): string => (string) ($arguments['title'] ?? 'Copy the secret now'))
            ->modalDescription('It is shown only now. Store it in your system\'s secret store; if it is lost, rotate it.')
            ->schema(fn (array $arguments): array => array_values(array_filter([
                filled($arguments['url'] ?? null) ? TextEntry::make('url')->label('Endpoint URL')->state((string) $arguments['url'])->copyable() : null,
                TextEntry::make('secret')->label((string) ($arguments['label'] ?? 'Secret'))->state((string) ($arguments['secret'] ?? ''))->copyable()->fontFamily('mono'),
            ])))
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Done');
    }

    protected function reveal(string $title, string $label, string $secret, ?string $url = null): void
    {
        $this->replaceMountedAction('revealSecret', ['title' => $title, 'label' => $label, 'secret' => $secret, 'url' => $url]);
    }
}
