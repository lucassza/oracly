<x-filament-panels::page>
    <style>
        .oracly-scroll-x { overflow-x: auto; -webkit-overflow-scrolling: touch; }
        .oracly-note { font-size: .8125rem; color: #6b7280; margin-top: .5rem; }
        .dark .oracly-note { color: #94a3b8; }
        .oracly-section-title { font-size: 1rem; font-weight: 700; margin-top: 1.5rem; }

        .oracly-lay small { display: block; font-size: .6875rem; color: #6b7280; font-weight: 400; }
        .dark .oracly-lay small { color: #94a3b8; }
        .oracly-lay td { vertical-align: top; }
        .oracly-lay .c-num { white-space: nowrap; }
        .oracly-lay .c-stake strong { font-size: 1rem; color: #065f46; }
        .dark .oracly-lay .c-stake strong { color: #6ee7b7; }
        .oracly-pos { color: #047857; font-weight: 700; }
        .oracly-neg { color: #b91c1c; font-weight: 700; }
        .dark .oracly-pos { color: #6ee7b7; }
        .dark .oracly-neg { color: #fca5a5; }

        .oracly-inputs { display: flex; flex-wrap: wrap; gap: .75rem; align-items: flex-end; margin-top: .75rem; }
        .oracly-inputs label { font-size: .75rem; font-weight: 700; color: #374151; }
        .dark .oracly-inputs label { color: #e5e7eb; }
        .oracly-inputs input, .oracly-quote input {
            display: block; width: 6rem; border-radius: .5rem; border: 1px solid #d1d5db; padding: .25rem .5rem; font-size: .875rem;
            background: #fff; color: #111827; margin-top: .125rem;
        }
        .dark .oracly-inputs input, .dark .oracly-quote input { background: rgba(255,255,255,.05); border-color: rgba(255,255,255,.15); color: #f8fafc; }

        .oracly-quote { display: flex; flex-wrap: wrap; align-items: flex-end; gap: .375rem; }
        .oracly-quote label { font-size: .625rem; font-weight: 700; text-transform: uppercase; opacity: .7; }
        .oracly-quote input { width: 4.5rem; }
        .oracly-quote button {
            border-radius: .5rem; padding: .3rem .625rem; font-size: .75rem; font-weight: 700;
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

        .oracly-months { display: grid; grid-template-columns: repeat(auto-fill, minmax(5.5rem, 1fr)); gap: .5rem; margin-top: .5rem; }
        .oracly-month { border: 1px solid #e5e7eb; border-radius: .5rem; padding: .375rem .5rem; font-size: .75rem; }
        .dark .oracly-month { border-color: rgba(255,255,255,.1); }

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
        }
    </style>

    @php
        $pct = fn (?float $v, int $d = 1): string => $v === null ? '—' : number_format($v, $d, ',', '.').'%';
        $odd = fn (?float $v): string => $v === null ? '—' : number_format($v, 2, ',', '.');
        $money = fn (?float $v): string => $v === null ? '—' : 'R$ '.number_format($v, 2, ',', '.');
        $signed = fn (?float $v, int $d = 0): string => $v === null ? '—' : ($v >= 0 ? '+' : '').number_format($v, $d, ',', '.');
        $resultLabel = fn (?string $r): string => match ($r) { 'green' => 'Green', 'red_nil' => 'Red 0x0', 'red_one' => 'Red 0x1', default => 'Pendente' };
        $max = \App\Oracly\Services\NilNilZeroOneLayStrategy::MAX_ODDS;
        $ref = $this->referenceProbabilities;
    @endphp

    <x-oracly.page-header eyebrow="Sinais LAY 0x1 do Punter">
        {{ static::$title }}
        <x-slot name="description">
            Lay no 0x0 e no 0x1 no mesmo jogo, com a mesma responsabilidade nas duas pernas: só um dos dois placares
            pode sair, então o risco máximo é a responsabilidade de uma perna. Nos sinais da regra escolhida o 0x0 saiu em
            {{ $pct($ref['nil'] === null ? null : $ref['nil'] * 100) }} e o 0x1 em {{ $pct($ref['one'] === null ? null : $ref['one'] * 100) }}
            dos jogos. Entre com o 0x0 até {{ $odd($max['nil']) }} e o 0x1 até {{ $odd($max['one']) }}.
        </x-slot>
    </x-oracly.page-header>

    <x-oracly.chip-group :options="$this::MODE_OPTIONS" :active="$mode" method="setMode" />
    <x-oracly.chip-group :options="\App\Oracly\Support\HourRank::RULES" :active="$rule" method="setRule" />

    <div class="oracly-inputs">
        <label>Responsabilidade por perna (R$)
            <input type="text" inputmode="decimal" wire:model.live.debounce.500ms="liability" />
        </label>
        @if ($mode === 'history')
            <label>Odd 0x0 (simulação)
                <input type="text" inputmode="decimal" wire:model.live.debounce.500ms="simOddNil" />
            </label>
            <label>Odd 0x1 (simulação)
                <input type="text" inputmode="decimal" wire:model.live.debounce.500ms="simOddOne" />
            </label>
        @endif
    </div>

    @if ($mode === 'upcoming')
        <div class="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-gray-200 bg-white px-4 py-3 shadow-sm dark:border-white/10 dark:bg-white/[0.04]">
            <button type="button" wire:click="previousDay" class="rounded-lg px-3 py-1.5 text-sm font-semibold text-gray-600 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-white/10">‹ Anterior</button>
            <strong class="text-sm">{{ \Carbon\Carbon::parse($date)->format('d/m/Y') }}</strong>
            <button type="button" wire:click="nextDay" class="rounded-lg px-3 py-1.5 text-sm font-semibold text-gray-600 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-white/10">Próximo ›</button>
        </div>

        <div class="oracly-scroll-x">
            <table class="oracly-table oracly-lay">
                <thead>
                    <tr>
                        <th>Horário</th>
                        <th>Jogo</th>
                        <th>Odds casa / fora</th>
                        <th>Lay 0x0</th>
                        <th>Lay 0x1</th>
                        <th>Odds de lay na exchange</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($this->upcomingRows as $row)
                        <tr wire:key="signal-{{ $row['quoteHash'] }}">
                            <td class="c-when">
                                <strong>{{ \App\Oracly\Support\BrasiliaDate::timeFromKickoff($row['kickoffAt']) ?? '—' }}</strong>
                                <x-oracly.opportunity-rank-badge :rank="$row['rank'] ?? null" />
                            </td>
                            <td>
                                <x-oracly.team-pair :home="$row['homeTeam']" :away="$row['awayTeam']" />
                                <small>{{ trim(($row['country'] ?? '').' · '.($row['competition'] ?? ''), ' ·') ?: '—' }}</small>
                            </td>
                            <td class="c-num" data-label="Odds casa / fora">{{ $odd($row['oddHome']) }} / {{ $odd($row['oddAway']) }}</td>
                            <td class="c-num c-stake" data-label="Lay 0x0">
                                <strong>{{ $money($row['stakes']['nil']) }}</strong>
                                <small>a {{ $odd($row['odds']['nil']) }}{{ $row['oddsTyped'] ? '' : ' (padrão)' }}</small>
                            </td>
                            <td class="c-num c-stake" data-label="Lay 0x1">
                                <strong>{{ $money($row['stakes']['one']) }}</strong>
                                <small>a {{ $odd($row['odds']['one']) }}{{ $row['oddsTyped'] ? '' : ' (padrão)' }}</small>
                            </td>
                            <td>
                                <form class="oracly-quote" wire:submit="saveQuote('{{ $row['quoteHash'] }}')">
                                    <label>0x0
                                        <input type="text" inputmode="decimal" placeholder="até {{ $odd($max['nil']) }}"
                                            wire:model.live.debounce.400ms="quoteInputs.{{ $row['quoteHash'] }}.nil"
                                            aria-label="Odd de lay do 0x0 em {{ $row['homeTeam'] }} x {{ $row['awayTeam'] }}" />
                                    </label>
                                    <label>0x1
                                        <input type="text" inputmode="decimal" placeholder="até {{ $odd($max['one']) }}"
                                            wire:model.live.debounce.400ms="quoteInputs.{{ $row['quoteHash'] }}.one"
                                            aria-label="Odd de lay do 0x1 em {{ $row['homeTeam'] }} x {{ $row['awayTeam'] }}" />
                                    </label>
                                    <button type="submit">Registrar</button>
                                </form>
                                @if ($row['verdict'])
                                    @php($v = $row['verdict'])
                                    <span class="oracly-verdict-chip oracly-verdict-chip--{{ $v['verdict'] }}">
                                        {{ match ($v['verdict']) { 'enter' => 'Entra', 'thin' => 'Odd acima do teto', default => 'Não entra' } }}
                                        · {{ $signed($v['expectedReturn'] * 100, 2) }}% esperado
                                    </span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6">Nenhum sinal LAY 0x1 do Punter neste dia pela regra escolhida.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <p class="oracly-note">
            Stake de lay = responsabilidade ÷ (odd − 1). Coloque as duas apostas no mercado Placar Correto do mesmo jogo:
            a exchange limita o risco à responsabilidade de uma perna. O selo de retorno esperado usa as odds digitadas e a
            frequência histórica de cada placar na regra escolhida ({{ number_format($ref['entries'], 0, ',', '.') }} sinais apurados),
            já descontados {{ number_format(\App\Oracly\Support\LayPricing::COMMISSION * 100, 1, ',', '') }}% de comissão.
        </p>
    @else
        <x-oracly.chip-group :options="$this->yearOptions" :active="$historyYear" method="setHistoryYear" />
        <x-oracly.chip-group :options="$this::CYCLE_OPTIONS" :active="$cycle" method="setCycle" />

        @php($stats = $this->historyStats)
        @php($cycleStats = $this->cycleStats)
        <div class="grid grid-cols-2 gap-3 xl:grid-cols-4">
            <x-oracly.stat-tile :value="$pct($stats['hitRate'], 2)" :label="'Green em '.number_format($stats['entries'], 0, ',', '.').' entradas'" accent />
            <x-oracly.stat-tile :value="$stats['redNil'].' / '.$stats['redOne']" label="Reds de 0x0 / de 0x1" />
            <x-oracly.stat-tile :value="$pct($stats['avgReturn'], 2)" label="Retorno por entrada (sobre a responsabilidade)" />
            <x-oracly.stat-tile :value="$signed($cycleStats['result'])" :label="'Resultado do ciclo · base '.$money($this->liabilityValue)" active />
            <x-oracly.stat-tile :value="$money($cycleStats['maxDrawdown'])" label="Maior queda" />
            <x-oracly.stat-tile :value="$cycleStats['completed'].' / '.$cycleStats['broken']" label="Ciclos completos / quebrados" />
            <x-oracly.stat-tile :value="count(array_filter($cycleStats['months'], fn (float $v): bool => $v < 0)).' de '.count($cycleStats['months'])" label="Meses negativos" />
            <x-oracly.stat-tile :value="$stats['realOdds']" label="Entradas com odd registrada por você" />
        </div>

        <h2 class="oracly-section-title">Por ano</h2>
        <div class="oracly-scroll-x">
            <table class="oracly-table oracly-lay">
                <thead>
                    <tr>
                        <th>Ano</th>
                        <th>Entradas</th>
                        <th>Green</th>
                        <th>Red 0x0 / 0x1</th>
                        <th>Retorno por entrada</th>
                        <th>Ciclo</th>
                        <th>Maior queda</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($this->yearStats as $year)
                        <tr wire:key="year-{{ $year['year'] }}">
                            <td><strong>{{ $year['year'] }}</strong></td>
                            <td class="c-num" data-label="Entradas">{{ number_format($year['entries'], 0, ',', '.') }}</td>
                            <td class="c-num" data-label="Green">{{ $pct($year['hitRate'], 2) }}</td>
                            <td class="c-num" data-label="Red 0x0 / 0x1">{{ $year['redNil'] }} / {{ $year['redOne'] }}</td>
                            <td class="c-num" data-label="Retorno por entrada">{{ $pct($year['avgReturn'], 2) }}</td>
                            <td class="c-num {{ $year['cycle']['result'] >= 0 ? 'oracly-pos' : 'oracly-neg' }}" data-label="Ciclo">{{ $signed($year['cycle']['result']) }}</td>
                            <td class="c-num" data-label="Maior queda">{{ $money($year['cycle']['maxDrawdown']) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        @if (count($cycleStats['months']))
            <h2 class="oracly-section-title">Ciclo mês a mês</h2>
            <div class="oracly-months">
                @foreach ($cycleStats['months'] as $month => $value)
                    <div class="oracly-month">
                        <small>{{ \Carbon\Carbon::parse($month.'-01')->format('m/Y') }}</small>
                        <div class="{{ $value >= 0 ? 'oracly-pos' : 'oracly-neg' }}">{{ $signed($value) }}</div>
                    </div>
                @endforeach
            </div>
        @endif

        <h2 class="oracly-section-title">Entradas mais recentes</h2>
        <div class="oracly-scroll-x">
            <table class="oracly-table oracly-lay">
                <thead>
                    <tr>
                        <th>Data</th>
                        <th>Jogo</th>
                        <th>Odds casa / fora</th>
                        <th>Placar</th>
                        <th>Resultado</th>
                        <th>Odds de lay usadas</th>
                        <th>Retorno</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($this->displayedHistory as $row)
                        <tr wire:key="hist-{{ md5($row['matchKey']) }}">
                            <td class="c-when">
                                {{ \Carbon\Carbon::parse($row['dateBrasilia'])->format('d/m/Y') }}
                                <small>{{ \App\Oracly\Support\BrasiliaDate::timeFromKickoff($row['kickoffAt']) }}</small>
                            </td>
                            <td>
                                <x-oracly.team-pair :home="$row['homeTeam']" :away="$row['awayTeam']" />
                                <small>{{ $row['competitionLabel'] ?: '—' }}</small>
                            </td>
                            <td class="c-num" data-label="Odds casa / fora">{{ $odd($row['oddHome']) }} / {{ $odd($row['oddAway']) }}</td>
                            <td class="c-num" data-label="Placar">
                                {{ $row['ftHome'] }}-{{ $row['ftAway'] }}
                                @if ($row['htHome'] !== null)
                                    <small>HT {{ $row['htHome'] }}-{{ $row['htAway'] }}</small>
                                @endif
                            </td>
                            <td class="c-num" data-label="Resultado">
                                <x-oracly.result-badge :hit="$row['result'] === 'green'" :label="$resultLabel($row['result'])" />
                            </td>
                            <td class="c-num" data-label="Odds de lay usadas">
                                {{ $odd($row['odds']['nil']) }} / {{ $odd($row['odds']['one']) }}
                                <small>{{ $row['realOdds'] ? 'registradas' : 'simulação' }}</small>
                            </td>
                            <td class="c-num {{ $row['return'] >= 0 ? 'oracly-pos' : 'oracly-neg' }}" data-label="Retorno">{{ $signed($row['return'] * 100, 1) }}%</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7">Nenhum sinal apurado neste período.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <p class="oracly-note">
            Resultado apurado pelo próprio Punter (placar final do sinal). O ciclo reinveste o lucro de cada green até fechar
            e volta à base depois de fechar ou de um red; não há juros compostos entre ciclos.
            A lista mostra as {{ min(100, $stats['entries']) }} entradas mais recentes.
        </p>
    @endif

    <h2 class="oracly-section-title">Suas odds registradas</h2>
    @php($summary = $this->quoteSummary)
    <div class="grid grid-cols-2 gap-3 xl:grid-cols-4">
        <x-oracly.stat-tile :value="$summary['count']" label="Jogos registrados" active />
        <x-oracly.stat-tile :value="$odd($summary['medianNil']).' / '.$odd($summary['medianOne'])" :label="'Odd mediana 0x0 / 0x1 (teto '.$odd($max['nil']).' / '.$odd($max['one']).')'" accent />
        <x-oracly.stat-tile :value="$summary['settled'].' · '.$summary['reds'].' red'" label="Apurados" />
        <x-oracly.stat-tile :value="$pct($summary['realizedReturn'], 2)" label="Retorno realizado por entrada" />
    </div>
    <p class="oracly-note">
        A estratégia depende da odd: se as medianas ficarem acima do teto depois de algumas dezenas de registros, ela perde a vantagem
        por mais que acerte.
    </p>

    @if (count($this->recentQuotes))
        <div class="oracly-scroll-x">
            <table class="oracly-table oracly-lay">
                <thead>
                    <tr>
                        <th>Data</th>
                        <th>Jogo</th>
                        <th>Odd 0x0</th>
                        <th>Odd 0x1</th>
                        <th>Resultado</th>
                        <th>Retorno</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($this->recentQuotes as $quote)
                        <tr wire:key="quote-{{ $quote['hash'] }}">
                            <td>{{ \Carbon\Carbon::parse($quote['matchDate'])->format('d/m/Y') }}</td>
                            <td>
                                <x-oracly.team-pair :home="$quote['homeTeam']" :away="$quote['awayTeam']" />
                                <small>{{ $quote['competition'] ?: '—' }}</small>
                            </td>
                            <td class="c-num" data-label="Odd 0x0">{{ $odd($quote['odds']['nil']) }}</td>
                            <td class="c-num" data-label="Odd 0x1">{{ $odd($quote['odds']['one']) }}</td>
                            <td class="c-num" data-label="Resultado">
                                <x-oracly.result-badge :hit="$quote['result'] === null ? null : $quote['result'] === 'green'"
                                    :label="$resultLabel($quote['result']).($quote['score'] ? ' '.$quote['score'] : '')" />
                            </td>
                            <td class="c-num" data-label="Retorno">
                                {{ $quote['realizedReturn'] === null ? '—' : $signed($quote['realizedReturn'] * 100, 1).'%' }}
                            </td>
                            <td>
                                <button type="button" class="oracly-note" wire:click="deleteQuote('{{ $quote['hash'] }}')" wire:confirm="Remover este registro?">remover</button>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</x-filament-panels::page>
