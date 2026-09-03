@if ($sources->isNotEmpty())
    <div class="mx-4 mt-4 sm:mx-6 lg:mx-8">
        <div class="flex items-start gap-3 rounded-xl border border-red-300 bg-red-50 px-4 py-3 text-sm text-red-800 dark:border-red-500/30 dark:bg-red-500/10 dark:text-red-200">
            <x-filament::icon icon="heroicon-o-exclamation-triangle" class="mt-0.5 size-5 shrink-0 text-red-500 dark:text-red-400" />
            <div class="flex-1">
                <p class="font-semibold">
                    {{ $sources->count() === 1 ? '1 fonte Punter falhou' : $sources->count().' fontes Punter falharam' }} na última sincronização.
                </p>
                <ul class="mt-1 list-inside list-disc space-y-0.5">
                    @foreach ($sources as $source)
                        <li>
                            <span class="font-medium">{{ $source['label'] }}</span>
                            @if ($source['last_error'])
                                — {{ \Illuminate\Support\Str::limit($source['last_error'], 140) }}
                            @endif
                        </li>
                    @endforeach
                </ul>
                <a href="{{ \App\Filament\Resources\SheetSources\SheetSourceResource::getUrl() }}" class="mt-2 inline-block font-semibold underline hover:no-underline">
                    Ver detalhes em Fontes Punter →
                </a>
            </div>
        </div>
    </div>
@endif
