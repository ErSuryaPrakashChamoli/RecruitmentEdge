@php
    /** @var \App\Models\AiConversation $conversation */
    $conversation = $getRecord();
    $messages = $conversation->messages()->with(['toolCalls.result', 'toolCalls.approver'])->get();
    $pretty = fn ($value) => json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
@endphp

<div class="flex flex-col gap-4">
    @forelse ($messages as $message)
        <div
            wire:key="ai-transcript-message-{{ $message->id }}"
            @class([
                'rounded-lg border p-3 text-sm',
                'border-gray-200 bg-white dark:border-white/10 dark:bg-gray-900' => $message->role->value !== 'tool',
                'border-gray-200 bg-gray-100 dark:border-white/10 dark:bg-white/5' => $message->role->value === 'tool',
            ])
        >
            <div class="flex items-center justify-between gap-2">
                <x-filament::badge :color="match ($message->role->value) { 'user' => 'primary', 'assistant' => 'success', 'tool' => 'gray', default => 'warning' }">
                    {{ \Illuminate\Support\Str::headline($message->role->value) }}{{ $message->tool_name ? ' · '.$message->tool_name : '' }}
                </x-filament::badge>
                <span class="text-xs text-gray-500 dark:text-gray-400">
                    {{ $message->created_at?->toDayDateTimeString() }}
                    @if ($message->input_tokens || $message->output_tokens)
                        · {{ (int) $message->input_tokens }} in / {{ (int) $message->output_tokens }} out
                    @endif
                </span>
            </div>

            @if (filled($message->content))
                @if ($message->role->value === 'tool')
                    <pre class="mt-2 whitespace-pre-wrap text-xs text-gray-600 dark:text-gray-400">{{ is_array(json_decode($message->content, true)) ? $pretty(json_decode($message->content, true)) : $message->content }}</pre>
                @else
                    {{-- AI/user text is never trusted as raw HTML (prompt-injection defence). --}}
                    <div class="prose prose-sm dark:prose-invert mt-2 max-w-none">
                        {!! \Illuminate\Support\Str::markdown($message->content, ['html_input' => 'strip', 'allow_unsafe_links' => false]) !!}
                    </div>
                @endif
            @endif

            @foreach ($message->toolCalls as $call)
                <div class="mt-2 rounded-lg border border-gray-200 bg-white/70 p-3 text-xs dark:border-white/10 dark:bg-black/20">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <span class="font-medium">Tool call: {{ $call->tool_name }}</span>
                        <span class="flex flex-wrap gap-2">
                            <x-filament::badge size="sm" :color="match ($call->status->value) { 'executed' => 'success', 'pending' => 'warning', default => 'danger' }">
                                {{ $call->status->label() }}
                            </x-filament::badge>
                            <x-filament::badge size="sm" color="gray">{{ $call->risk_level->label() }}</x-filament::badge>
                        </span>
                    </div>

                    @if ($call->approver)
                        <p class="mt-1 text-gray-500">Decided by {{ $call->approver->name }} {{ $call->approved_at?->diffForHumans() }}</p>
                    @endif

                    <p class="mt-2 font-medium">Arguments</p>
                    <pre class="mt-1 whitespace-pre-wrap text-gray-600 dark:text-gray-400">{{ $pretty($call->arguments ?? []) }}</pre>

                    @if ($call->result)
                        <p class="mt-2 font-medium">Result {{ $call->result->success ? '' : '(failed)' }}</p>
                        @if (filled($call->result->error))
                            <p class="mt-1 text-rose-700 dark:text-rose-400">{{ $call->result->error }}</p>
                        @endif
                        <pre class="mt-1 whitespace-pre-wrap text-gray-600 dark:text-gray-400">{{ $pretty($call->result->output ?? []) }}</pre>
                    @endif
                </div>
            @endforeach
        </div>
    @empty
        <p class="text-sm text-gray-500 dark:text-gray-400">This conversation has no messages yet.</p>
    @endforelse
</div>
