<x-filament-panels::page>
    <style>
        .oracly-scroll-x { overflow-x: auto; -webkit-overflow-scrolling: touch; }
        .oracly-note { font-size: .8125rem; color: #6b7280; margin-top: .5rem; }
        .dark .oracly-note { color: #94a3b8; }

        .oracly-lay small { display: block; font-size: .6875rem; color: #6b7280; font-weight: 400; }
        .dark .oracly-lay small { color: #94a3b8; }
        .oracly-lay td { vertical-align: top; }
        .oracly-lay .c-num { white-space: nowrap; }
        .oracly-lay .c-max strong { font-size: 1rem; color: #065f46; }
        .dark .oracly-lay .c-max strong { color: #6ee7b7; }

        .oracly-quote { display: flex; align-items: center; gap: .375rem; }
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
        $stats = $this->currentStats;
        $scoreLabel = $this->strategy()->label();
        $pct = fn (?float $v, int $d = 1): string => $v === null ? '—' : number_format($v, $d).'%';
        $odd = fn (?float $v): string => $v === null ? '—' : number_format($v, 2);
    @endphp

    <x-oracly.page-header :eyebrow="$this->eyebrow">
        {{ static::$title }}
        <x-slot name="description">{{ $this->strategyDescription }}</x-slot>
    </x-oracly.page-header>

    <x-oracly.chip-group :options="$this::MODE_OPTIONS" :active="$mode" method="setMode" />
    <x-oracly.chip-group :options="$this->strategy()->profiles()" :active="$signalProfile" method="setSignalProfile" />
    @if ($mode === 'upcoming' && count($this->hours) > 1)
        <x-oracly.chip-group :options="$this->hourOptions" :active="$hourFilter" method="setHourFilter" />
    @endif
    <p class="oracly-note">{{ $this->selectionNote }}</p>

    <div class="grid grid-cols-2 gap-3 xl:grid-cols-4">
        <x-oracly.stat-tile :value="$pct($stats['hitRate'])" :label="'Acerto no histórico ('.number_format($stats['entries'], 0, ',', '.').' jogos)'" accent />
        <x-oracly.stat-tile :value="$pct($stats['validation'])" :label="'Validação: 30% mais recentes (treino '.$pct($stats['development']).')'" />
        <x-oracly.stat-tile :value="$pct($stats['frequency'], 2)" :label="'Saiu '.$scoreLabel.' · modelo previa '.$pct($stats['predicted'], 2)" />
        <x-oracly.stat-tile :value="$odd($stats['maxEntryOdd'])" :label="'Odd máxima média do perfil (justa '.$odd($stats['fairOdd']).')'" active />
    </div>

    <div class="oracly-scroll-x">
        <table class="oracly-table oracly-lay">
            <thead>
                <tr>
                    <th>{{ $mode === 'history' ? 'Data' : 'Horário' }}</th>
                    <th>Jogo</th>
                    <th>Favorito</th>
                    <th>Chance de {{ $scoreLabel }}</th>
                    <th>Odd justa</th>
                    <th>Entre até</th>
                    @if ($mode === 'history')
                        <th>Placar final</th>
                        <th>Resultado</th>
                    @else
                        <th>Odd de lay na exchange</th>
                    @endif
                </tr>
            </thead>
            <tbody>
                @forelse ($this->filteredRows as $row)
                    <tr wire:key="lay-{{ $row['quoteHash'] }}">
                        <td class="c-when">
                            @if ($mode === 'history')
                                {{ $row['matchDate'] }}
                            @else
                                <strong>{{ \App\Oracly\Support\BrasiliaDate::timeFromKickoff($row['kickoffAt'] ?? null) ?? '—' }}</strong>
                            @endif
                        </td>
                        <td>
                            <x-oracly.team-pair :home="$row['homeTeam'] ?? ''" :away="$row['awayTeam'] ?? ''" />
                            <small>{{ $row['competition'] ?: '—' }}</small>
                        </td>
                        <td class="c-num" data-label="Favorito">
                            {{ $pct($row['favourite'] === null ? null : $row['favourite'] * 100, 0) }}
                            <small>{{ match ($row['favouriteSide'] ?? null) { 'home' => $row['homeTeam'], 'away' => $row['awayTeam'], default => 'sem favorito' } }}</small>
                        </td>
                        <td class="c-num" data-label="Chance de {{ $scoreLabel }}">
                            {{ $pct($row['priceProbability'], 2) }}
                            @if ($row['priceIsCohort'])
                                <small title="Jogo sem odd de over 2,5: usa a frequência observada do perfil">média do perfil</small>
                            @endif
                        </td>
                        <td class="c-num" data-label="Odd justa">{{ $odd($row['fairOdd']) }}</td>
                        <td class="c-num c-max" data-label="Entre até"><strong>{{ $odd($row['maxEntryOdd']) }}</strong></td>
                        @if ($mode === 'history')
                            <td class="c-num" data-label="Placar final">{{ $row['homeGoals'] ?? '—' }}-{{ $row['awayGoals'] ?? '—' }}</td>
                            <td class="c-num" data-label="Resultado">
                                <x-oracly.result-badge :hit="$row['result'] === null ? null : $row['result'] === 'green'" :label="$row['result'] === null ? null : ($row['result'] === 'green' ? 'Green' : 'Red')" />
                            </td>
                        @else
                            <td>
                                <form class="oracly-quote" wire:submit="saveQuote('{{ $row['quoteHash'] }}')">
                                    <input type="text" inputmode="decimal" placeholder="ex.: {{ $odd($row['maxEntryOdd']) }}"
                                        wire:model.live.debounce.400ms="quoteInputs.{{ $row['quoteHash'] }}"
                                        aria-label="Odd de lay na exchange para {{ $row['homeTeam'] }} x {{ $row['awayTeam'] }}" />
                                    <button type="submit">Registrar</button>
                                </form>
                                @if ($row['verdict'])
                                    @php($v = $row['verdict'])
                                    <span class="oracly-verdict-chip oracly-verdict-chip--{{ $v['verdict'] }}">
                                        {{ match ($v['verdict']) { 'enter' => 'Entra', 'thin' => 'Margem curta', default => 'Não entra' } }}
                                        · {{ number_format($v['ratio'] * 100, 0) }}% da justa
                                        @if ($v['expectedReturn'] !== null)
                                            · {{ $v['expectedReturn'] >= 0 ? '+' : '' }}{{ number_format($v['expectedReturn'], 2) }}%
                                        @endif
                                    </span>
                                @endif
                            </td>
                        @endif
                    </tr>
                @empty
                    <tr>
                        <td colspan="{{ $mode === 'history' ? 8 : 7 }}">
                            Nenhum jogo neste perfil.
                            @if ($mode === 'upcoming')
                                A lista do dia vem de panel_fixtures (Punter) e cobre cerca de uma semana à frente.
                            @endif
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <p class="oracly-note">
        Odd justa = 100 ÷ chance de {{ $scoreLabel }}. "Entre até" é {{ number_format(\App\Oracly\Support\LayPricing::ENTRY_RATIO * 100, 0) }}% da justa:
        com {{ number_format(\App\Oracly\Support\LayPricing::COMMISSION * 100, 1, ',', '') }}% de comissão o empate fica perto de {{ number_format((1 - \App\Oracly\Support\LayPricing::COMMISSION) * 100, 1, ',', '') }}%, e a folga cobre o erro da estimativa.
        O retorno no selo é por unidade de responsabilidade. A lista do dia usa odd de abertura; os cortes foram medidos em odd de fechamento.
    </p>

    <h2 class="oracly-section-title">Suas odds registradas</h2>
    @php($summary = $this->quoteSummary)
    <div class="grid grid-cols-2 gap-3 xl:grid-cols-4">
        <x-oracly.stat-tile :value="$summary['count']" label="Odds registradas" active />
        <x-oracly.stat-tile :value="$summary['medianRatio'] === null ? '—' : number_format($summary['medianRatio'] * 100, 0).'%'" label="Odd da exchange / odd justa (mediana)" accent />
        <x-oracly.stat-tile :value="$pct($summary['enterShare'], 0)" label="Registros dentro da odd máxima" />
        <x-oracly.stat-tile :value="$pct($summary['realizedReturn'], 2)" :label="'Retorno realizado · '.$summary['settled'].' apurados, '.$summary['reds'].' red'" />
    </div>
    <p class="oracly-note">
        A pergunta é se a exchange paga abaixo da odd justa. Se a mediana ficar acima de
        {{ number_format(\App\Oracly\Support\LayPricing::ENTRY_RATIO * 100, 0) }}% depois de algumas centenas de registros, a estratégia não tem vantagem
        de preço, por mais que acerte. O retorno realizado precisa de muito mais entradas para dizer alguma coisa.
    </p>

    @if (count($this->recentQuotes))
        <div class="oracly-scroll-x">
            <table class="oracly-table oracly-lay">
                <thead>
                    <tr>
                        <th>Data</th>
                        <th>Jogo</th>
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
                            <td>{{ $quote['matchDate'] }}</td>
                            <td>
                                <x-oracly.team-pair :home="$quote['homeTeam']" :away="$quote['awayTeam']" />
                                <small>{{ $quote['competition'] ?: '—' }}</small>
                            </td>
                            <td class="c-num" data-label="Odd exchange">{{ $odd($quote['offeredOdd']) }}</td>
                            <td class="c-num" data-label="Odd justa">{{ $odd($quote['fairOdd']) }}</td>
                            <td class="c-num" data-label="% da justa">{{ $quote['ratio'] === null ? '—' : number_format($quote['ratio'] * 100, 0).'%' }}</td>
                            <td class="c-num" data-label="Resultado">
                                @if ($quote['result'] === null)
                                    <x-oracly.result-badge :hit="null" label="Pendente" />
                                @else
                                    <x-oracly.result-badge :hit="$quote['result'] === 'green'" :label="($quote['result'] === 'green' ? 'Green' : 'Red').' '.$quote['score']['homeGoals'].'-'.$quote['score']['awayGoals']" />
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
