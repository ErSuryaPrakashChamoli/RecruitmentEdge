<x-filament-panels::page>
    @if (! $this->isAiConfigured())
        <div class="mb-4 rounded-lg border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-800 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-300">
            AI is not configured yet. I can still show you what the Copilot will do, but responses will say so until an administrator adds an <code>OPENAI_API_KEY</code>. The rest of the app works normally either way.
        </div>
    @endif

    <div class="flex flex-col gap-4">
        <div class="rounded-xl border border-gray-200 bg-white shadow-sm dark:border-white/10 dark:bg-gray-900">
            <div class="flex flex-col gap-4 p-4 max-h-[60vh] overflow-y-auto" id="ai-copilot-messages">
                @forelse ($this->visibleMessages() as $message)
                    <div class="flex {{ $message['role'] === 'user' ? 'justify-end' : 'justify-start' }}">
                        <div class="max-w-2xl rounded-2xl px-4 py-2.5 text-sm {{ $message['role'] === 'user' ? 'bg-amber-600 text-white' : 'bg-gray-100 text-gray-950 dark:bg-white/5 dark:text-gray-100' }}">
                            @if (filled($message['content']))
                                {{-- html_input 'strip' + allow_unsafe_links false: AI-generated/retrieved
                                     content is never trusted as raw HTML (prompt-injection defence). --}}
                                <div class="prose prose-sm dark:prose-invert max-w-none">
                                    {!! \Illuminate\Support\Str::markdown($message['content'], ['html_input' => 'strip', 'allow_unsafe_links' => false]) !!}
                                </div>
                            @endif

                            @foreach ($message['tool_calls'] as $call)
                                <div class="mt-2 rounded-lg border border-gray-200 bg-white/70 p-3 text-xs dark:border-white/10 dark:bg-black/20">
                                    <div class="flex items-center justify-between gap-2">
                                        <span class="font-medium">{{ Str::headline($call['tool_name']) }}</span>
                                        <span @class([
                                            'rounded-full px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide',
                                            'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-400' => $call['status'] === 'executed',
                                            'bg-rose-100 text-rose-700 dark:bg-rose-500/10 dark:text-rose-400' => in_array($call['status'], ['failed', 'rejected']),
                                            'bg-amber-100 text-amber-700 dark:bg-amber-500/10 dark:text-amber-400' => $call['status'] === 'pending',
                                        ])>{{ $call['status_label'] }} · {{ $call['risk_level'] }}</span>
                                    </div>

                                    @if ($call['status'] === 'pending')
                                        <p class="mt-1 text-gray-600 dark:text-gray-400">This action needs your approval before it runs.</p>

                                        @if ($this->canApproveActions())
                                            <div class="mt-2 flex gap-2">
                                                <button
                                                    type="button"
                                                    wire:click="approveToolCall({{ $call['id'] }})"
                                                    wire:loading.attr="disabled"
                                                    class="rounded-md bg-amber-600 px-2.5 py-1 text-white hover:bg-amber-500"
                                                >Approve</button>
                                                <button
                                                    type="button"
                                                    wire:click="rejectToolCall({{ $call['id'] }})"
                                                    wire:loading.attr="disabled"
                                                    class="rounded-md bg-gray-200 px-2.5 py-1 text-gray-800 hover:bg-gray-300 dark:bg-white/10 dark:text-gray-200"
                                                >Cancel</button>
                                            </div>
                                        @else
                                            <p class="mt-1 text-gray-500">Waiting for someone with action-approval permission.</p>
                                        @endif
                                    @elseif ($call['output'] !== null)
                                        <pre class="mt-1 whitespace-pre-wrap text-gray-600 dark:text-gray-400">{{ is_string($call['output']['summary'] ?? null) ? $call['output']['summary'] : json_encode($call['output'], JSON_PRETTY_PRINT) }}</pre>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    </div>
                @empty
                    <div class="py-8 text-center text-sm text-gray-500 dark:text-gray-400">
                        Ask anything about recruitment — candidates, requisitions, analytics, or research.
                    </div>
                @endforelse

                @if ($sending)
                    <div class="flex justify-start">
                        <div class="rounded-2xl bg-gray-100 px-4 py-2.5 text-sm text-gray-500 dark:bg-white/5 dark:text-gray-400">
                            Thinking…
                        </div>
                    </div>
                @endif
            </div>

            @if ($this->visibleMessages()->isEmpty())
                <div class="flex flex-wrap gap-2 border-t border-gray-200 p-3 dark:border-white/10">
                    @foreach ($this->suggestedPrompts() as $prompt)
                        <button
                            type="button"
                            wire:click="$set('question', @js($prompt))"
                            class="rounded-full border border-gray-300 px-3 py-1 text-xs text-gray-700 hover:bg-gray-50 dark:border-white/10 dark:text-gray-300 dark:hover:bg-white/5"
                        >{{ $prompt }}</button>
                    @endforeach
                </div>
            @endif

            <form wire:submit="ask" class="flex items-end gap-2 border-t border-gray-200 p-3 dark:border-white/10">
                <textarea
                    wire:model="question"
                    rows="2"
                    placeholder="Ask anything about recruitment…"
                    class="fi-input block w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-amber-500 focus:ring-amber-500 dark:border-white/10 dark:bg-white/5 dark:text-white"
                    x-on:keydown.enter.prevent="if (! $event.shiftKey) { $wire.ask() }"
                ></textarea>
                <button
                    type="submit"
                    wire:loading.attr="disabled"
                    wire:target="ask"
                    class="fi-btn rounded-lg bg-amber-600 px-4 py-2 text-sm font-medium text-white hover:bg-amber-500 disabled:opacity-50"
                >Send</button>
            </form>
        </div>
    </div>
</x-filament-panels::page>
