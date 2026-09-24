<x-filament-panels::page>
    <style>
        .oracly-scroll-x { overflow-x: auto; -webkit-overflow-scrolling: touch; }
        .oracly-note { font-size: .8125rem; color: #6b7280; margin-top: .5rem; }
        .dark .oracly-note { color: #94a3b8; }
        .oracly-warning {
            border: 1px solid #fcd34d; background: #fffbeb; color: #78350f;
            border-radius: .75rem; padding: .75rem 1rem; font-size: .8125rem;
        }
        .dark .oracly-warning { border-color: #92400e; background: rgba(251,191,36,.08); color: #fde68a; }

        .oracly-lay small { display: block; font-size: .6875rem; color: #6b7280; font-weight: 400; }
        .dark .oracly-lay small { color: #94a3b8; }
        .oracly-lay td { vertical-align: top; }
        .oracly-lay .c-num { white-space: nowrap; }

        .oracly-leg + .oracly-leg { margin-top: .625rem; padding-top: .625rem; border-top: 1px dashed #e5e7eb; }
        .dark .oracly-leg + .oracly-leg { border-top-color: #475569; }
        .oracly-leg__head { display: flex; flex-wrap: wrap; align-items: baseline; gap: .25rem .875rem; }
        .oracly-leg__score { font-size: 1rem; font-weight: 800; letter-spacing: .02em; }
        .oracly-leg__max { font-weight: 700; color: #065f46; }
        .dark .oracly-leg__max { color: #6ee7b7; }
        .oracly-leg__meta { font-size: .75rem; color: #6b7280; }
        .dark .oracly-leg__meta { color: #94a3b8; }

        .oracly-quote { display: flex; align-items: center; gap: .375rem; margin-top: .375rem; }
        .oracly-quote input {
            width: 5.5rem; border-radius: .5rem; border: 1px solid #d1d5db; padding: .25rem .5rem; font-size: .875rem;
            background: #fff; color: #111827;
        }
        .dark .oracly-quote input { background: rgba(255,255,255,.05); border-color: rgba(255,255,255,.15); color: #f8fafc; }
        .oracly-quote button {
            border-radius: .5rem; padding: .25rem .625rem; font-size: .75rem; font-weight: 700;
            background: #fbbf24; color: #111827;
        }

        .oracly-verdict-chip {
            display: inline-flex; margin-top: .25rem; border-radius: 9999px; padding: .0625rem .5rem;
            font-size: .6875rem; font-weight: 700; white-space: nowrap;
        }
        .oracly-verdict-chip--enter { background: #d1fae5; color: #065f46; }
        .oracly-verdict-chip--thin { background: #fef3c7; color: #92400e; }
        .oracly-verdict-chip--skip { background: #fee2e2; color: #991b1b; }
        .dark .oracly-verdict-chip--enter { background: rgba(16,185,129,.2); color: #a7f3d0; }
        .dark .oracly-verdict-chip--thin { background: rgba(251,191,36,.2); color: #fde68a; }
        .dark .oracly-verdict-chip--skip { background: rgba(239,68,68,.2); color: #fecaca; }

        .oracly-section-title { font-size: 1rem; font-weight: 700; margin-top: 1.5rem; }

        @media (max-width: 820px) {
            .oracly-lay, .oracly-lay tbody, .oracly-lay tr, .oracly-lay td { display: block; width: auto; }
            .oracly-lay thead { display: none; }
            .oracly-lay tr { border: 1px solid #e5e7eb; border-radius: .75rem; margin-top: .75rem; padding: .75rem; }
            .dark .oracly-lay tr { border-color: #475569; }
            .oracly-lay td { border: 0; padding: .125rem 0; }
            .oracly-lay .c-num { display: inline-block; margin-right: 1rem; }
            .oracly-lay .c-num::before {
                content: attr(data-label); display: block; font-size: .625rem; text-transform: uppercase;
                letter-spacing: .04em; opacity: .6; font-weight: 700;
            }
            .oracly-quote input { width: 100%; }
        }
    </style>

    @php
        $stats = $this->profileStats[$profile];
        $pct = fn (?float $v, int $d = 1): string => $v === null ? '—' : number_format($v, $d, ',', '.').'%';
        $odd = fn (?float $v): string => $v === null ? '—' : number_format($v, 2, ',', '.');
        $scoreText = fn (string $score): string => str_replace('-', 'x', $score);
    @endphp

    <x-oracly.page-header eyebrow="Punter · lay de placar exato escolhido por partida">
        {{ static::$title }}
        <x-slot name="description">
            Em cada jogo, a tela laya o placar (ou os dois) que o modelo de mercado mais superestima para
            aquele tipo de partida — força do favorito e gols esperados. Não é sempre o mesmo placar: na
            validação saíram 0x1, 0x2 e 0x0 da zebra, e também 1x2 e 3x0 em grupos específicos.
        </x-slot>
    </x-oracly.page-header>

    <x-oracly.chip-group :options="$this::MODE_OPTIONS" :active="$mode" method="setMode" />
    <x-oracly.chip-group :options="$this::PROFILES" :active="$profile" method="setProfile" />
    @if ($mode === 'upcoming' && count($this->hours) > 1)
        <x-oracly.chip-group :options="$this->hourOptions" :active="$hourFilter" method="setHourFilter" />
    @endif

    <div class="grid grid-cols-2 gap-3 xl:grid-cols-4">
        <x-oracly.stat-tile :value="$pct($stats['matchHitRate'])" :label="'Acerto por partida desde '.\Carbon\Carbon::parse($this::VALIDATION_FROM)->format('m/Y')" accent />
        <x-oracly.stat-tile :value="$pct($stats['legHitRate'])" :label="'Acerto por perna · '.number_format($stats['legs'], 0, ',', '.').' pernas'" />
        <x-oracly.stat-tile :value="number_format($stats['matches'], 0, ',', '.')" label="Partidas com entrada na validação" />
        <x-oracly.stat-tile :value="$stats['modelRatio'] === null ? '—' : number_format($stats['modelRatio'], 2, ',', '.')" label="Saiu ÷ modelo previa (abaixo de 1 = superestimado)" active />
    </div>

    <div class="oracly-warning">
        A escolha é contra um <strong>modelo</strong> de mercado (Poisson das odds de 1X2 e over 2,5), não contra a
        odd real de placar exato, que nenhuma base tem. Se a exchange já corrigir esses placares, o valor some.
        Só as odds registradas abaixo respondem isso — registre mesmo quando não entrar.
    </div>

    <div class="oracly-scroll-x">
        <table class="oracly-table oracly-lay">
            <thead>
                <tr>
                    <th>{{ $mode === 'history' ? 'Data' : 'Horário' }}</th>
                    <th>Jogo</th>
                    <th>Favorito</th>
                    @if ($mode === 'history')
                        <th>Placar final</th>
                    @endif
                    <th>Lay</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($this->filteredRows as $row)
                    <tr wire:key="dyn-{{ md5($row['matchKey']) }}">
                        <td class="c-when">
                            @if ($mode === 'history')
                                {{ \Carbon\Carbon::parse($row['matchDate'])->format('d/m/Y') }}
                            @else
                                <strong>{{ \App\Oracly\Support\BrasiliaDate::timeFromKickoff($row['kickoffAt'] ?? null) ?? '—' }}</strong>
                            @endif
                        </td>
                        <td>
                            <x-oracly.team-pair :home="$row['homeTeam']" :away="$row['awayTeam']" />
                            <small>{{ str_replace('_', ' ', $row['competition'] ?: '—') }}</small>
                        </td>
                        <td class="c-num" data-label="Favorito">
                            {{ match ($row['favouriteSide']) { 'home' => $row['homeTeam'], 'away' => $row['awayTeam'], default => '—' } }}
                            <small>{{ $row['favouriteSide'] === 'home' ? 'casa' : 'fora' }} · odd {{ $odd((float) min($row['oddHome'], $row['oddAway'])) }}</small>
                        </td>
                        @if ($mode === 'history')
                            <td class="c-num" data-label="Placar final">{{ $row['homeGoals'] }}x{{ $row['awayGoals'] }}</td>
                        @endif
                        <td>
                            @foreach ($row['legs'] as $leg)
                                <div class="oracly-leg" wire:key="leg-{{ $leg['hash'] }}">
                                    <div class="oracly-leg__head">
                                        <span class="oracly-leg__score">{{ $scoreText($leg['score']) }}</span>
                                        <span class="oracly-leg__meta">chance {{ $pct($leg['probability'], 2) }} · justa {{ $odd($leg['fairOdd']) }}</span>
                                        <span class="oracly-leg__meta" title="Quanto este placar sai em relação ao que o modelo prevê, neste tipo de jogo">saiu {{ number_format($leg['ratio'] * 100, 0) }}% do modelo</span>
                                        @if ($mode === 'upcoming')
                                            <span class="oracly-leg__max">entre até {{ $odd($leg['maxEntryOdd']) }}</span>
                                        @else
                                            <x-oracly.result-badge :hit="$leg['result'] === null ? null : $leg['result'] === 'green'" :label="$leg['result'] === null ? null : ($leg['result'] === 'green' ? 'Green' : 'Red')" />
                                        @endif
                                    </div>
                                    @if ($mode === 'upcoming')
                                        <form class="oracly-quote" wire:submit="saveQuote('{{ $leg['hash'] }}')">
                                            <input type="text" inputmode="decimal" placeholder="odd de lay"
                                                wire:model.live.debounce.400ms="quoteInputs.{{ $leg['hash'] }}"
                                                aria-label="Odd de lay {{ $scoreText($leg['score']) }} em {{ $row['homeTeam'] }} x {{ $row['awayTeam'] }}" />
                                            <button type="submit">Registrar</button>
                                        </form>
                                        @if ($leg['verdict'])
                                            @php($v = $leg['verdict'])
                                            <span class="oracly-verdict-chip oracly-verdict-chip--{{ $v['verdict'] }}">
                                                {{ match ($v['verdict']) { 'enter' => 'Entra', 'thin' => 'Margem curta', default => 'Não entra' } }}
                                                · {{ number_format($v['ratio'] * 100, 0) }}% da justa
                                                @if ($v['expectedReturn'] !== null)
                                                    · {{ $v['expectedReturn'] >= 0 ? '+' : '' }}{{ number_format($v['expectedReturn'], 2, ',', '.') }}%
                                                @endif
                                            </span>
                                        @endif
                                    @endif
                                </div>
                            @endforeach
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="{{ $mode === 'history' ? 5 : 4 }}">
                            Nenhum jogo neste perfil.
                            @if ($mode === 'upcoming')
                                A lista vem de panel_fixtures (Punter): o jogo precisa de odd de 1X2 e de over 2,5.
                            @endif
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <p class="oracly-note">
        Placares na ordem casa x fora. "Entre até" é {{ number_format(\App\Oracly\Support\LayPricing::ENTRY_RATIO * 100, 0) }}% da odd justa:
        com {{ number_format(\App\Oracly\Support\LayPricing::COMMISSION * 100, 1, ',', '') }}% de comissão o empate fica perto de
        {{ number_format((1 - \App\Oracly\Support\LayPricing::COMMISSION) * 100, 1, ',', '') }}%. Laydando dois placares do mesmo jogo só um
        pode sair: o prejuízo é a responsabilidade dele menos o que a outra perna paga. O retorno do selo é por unidade de responsabilidade.
        @if ($mode === 'history')
            O histórico mostra as 500 partidas mais recentes da validação, com a escolha feita só com o que se sabia antes de {{ \Carbon\Carbon::parse($this::VALIDATION_FROM)->format('d/m/Y') }}.
        @endif
    </p>

    <h2 class="oracly-section-title">Suas odds registradas</h2>
    @php($summary = $this->quoteSummary)
    <div class="grid grid-cols-2 gap-3 xl:grid-cols-4">
        <x-oracly.stat-tile :value="$summary['count']" label="Odds registradas (pernas)" active />
        <x-oracly.stat-tile :value="$summary['medianRatio'] === null ? '—' : number_format($summary['medianRatio'] * 100, 0).'%'" label="Odd da exchange / odd justa (mediana)" accent />
        <x-oracly.stat-tile :value="$pct($summary['enterShare'], 0)" label="Registros dentro da odd máxima" />
        <x-oracly.stat-tile :value="$pct($summary['realizedReturn'], 2)" :label="'Retorno realizado · '.$summary['settled'].' apuradas, '.$summary['reds'].' red'" />
    </div>
    <p class="oracly-note">
        Se a mediana ficar acima de {{ number_format(\App\Oracly\Support\LayPricing::ENTRY_RATIO * 100, 0) }}% depois de algumas centenas de registros,
        a exchange já precifica esses placares melhor que o modelo e a estratégia não tem vantagem de preço.
    </p>

    @if (count($this->recentQuotes))
        <div class="oracly-scroll-x">
            <table class="oracly-table oracly-lay">
                <thead>
                    <tr>
                        <th>Data</th>
                        <th>Jogo</th>
                        <th>Lay</th>
                        <th>Odd exchange</th>
                        <th>Odd justa</th>
                        <th>% da justa</th>
                        <th>Resultado</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($this->recentQuotes as $quote)
                        <tr wire:key="quote-{{ $quote['id'] }}">
                            <td>{{ \Carbon\Carbon::parse($quote['matchDate'])->format('d/m/Y') }}</td>
                            <td>
                                <x-oracly.team-pair :home="$quote['homeTeam']" :away="$quote['awayTeam']" />
                                <small>{{ str_replace('_', ' ', $quote['competition'] ?: '—') }}</small>
                            </td>
                            <td class="c-num" data-label="Lay"><strong>{{ $scoreText($quote['score']) }}</strong></td>
                            <td class="c-num" data-label="Odd exchange">{{ $odd($quote['offeredOdd']) }}</td>
                            <td class="c-num" data-label="Odd justa">{{ $odd($quote['fairOdd']) }}</td>
                            <td class="c-num" data-label="% da justa">{{ $quote['ratio'] === null ? '—' : number_format($quote['ratio'] * 100, 0).'%' }}</td>
                            <td class="c-num" data-label="Resultado">
                                @if ($quote['result'] === null)
                                    <x-oracly.result-badge :hit="null" label="Pendente" />
                                @else
                                    <x-oracly.result-badge :hit="$quote['result'] === 'green'" :label="($quote['result'] === 'green' ? 'Green' : 'Red').' '.$quote['finalScore']['homeGoals'].'x'.$quote['finalScore']['awayGoals']" />
                                @endif
                            </td>
                            <td>
                                <button type="button" class="oracly-note" wire:click="deleteQuote({{ $quote['id'] }})" wire:confirm="Remover este registro?">remover</button>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</x-filament-panels::page>
