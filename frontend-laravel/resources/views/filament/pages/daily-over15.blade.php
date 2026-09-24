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

        .o15 { width: 100%; }
        .o15 td { vertical-align: top; }
        .o15 small { display: block; font-size: .6875rem; color: #6b7280; font-weight: 400; }
        .dark .o15 small { color: #94a3b8; }
        .o15 .c-num { white-space: nowrap; text-align: right; font-variant-numeric: tabular-nums; }
        .o15 .c-min strong { font-size: 1rem; }
        .o15 .c-offer input {
            width: 5.5rem; border: 1px solid #d1d5db; border-radius: .375rem; padding: .25rem .5rem;
            font-size: .875rem; background: transparent; font-variant-numeric: tabular-nums;
        }
        .dark .o15 .c-offer input { border-color: #475569; }
        .o15-verdict { display: inline-block; margin-top: .25rem; border-radius: .375rem; padding: .125rem .5rem; font-size: .75rem; font-weight: 700; white-space: nowrap; }
        .o15-verdict--enter { background: #d1fae5; color: #065f46; }
        .o15-verdict--skip { background: #fee2e2; color: #991b1b; }
        .dark .o15-verdict--enter { background: rgba(16,185,129,.2); color: #a7f3d0; }
        .dark .o15-verdict--skip { background: rgba(239,68,68,.2); color: #fecaca; }
        .o15-edge--pos { color: #047857; }
        .o15-edge--neg { color: #b91c1c; }
        .dark .o15-edge--pos { color: #6ee7b7; }
        .dark .o15-edge--neg { color: #fca5a5; }

        @media (max-width: 820px) {
            .o15, .o15 tbody, .o15 tr, .o15 td { display: block; width: auto; }
            .o15 thead { display: none; }
            .o15 tr { border: 1px solid #e5e7eb; border-radius: .75rem; margin-top: .75rem; padding: .75rem; }
            .dark .o15 tr { border-color: #475569; }
            .o15 td { border: 0; padding: .125rem 0; }
            .o15 .c-num, .o15 .c-offer { display: inline-block; text-align: left; margin-right: 1rem; }
            .o15 .c-num::before, .o15 .c-offer::before {
                content: attr(data-label); display: block; font-size: .625rem; text-transform: uppercase;
                letter-spacing: .04em; opacity: .6; font-weight: 700;
            }
        }
    </style>

    <x-oracly.page-header eyebrow="Punter · exchange com {{ number_format(\App\Oracly\Services\Over15ValueStrategy::COMMISSION * 100, 1, ',') }}% de comissão">
        {{ static::$title }}
        <x-slot name="description">
            @if ($mode === 'upcoming')
                Jogos de {{ \Carbon\Carbon::parse($date)->format('d/m/Y') }}. Entre só quando a exchange pagar
                a odd mínima ou mais — abaixo dela o retorno esperado é negativo, por mais que o over 1,5 acerte.
            @else
                Resultado simulado pagando a odd justa (sem margem) do fechamento, com o edge da liga
                calculado só com jogos anteriores a cada partida.
            @endif
        </x-slot>
    </x-oracly.page-header>

    <x-oracly.chip-group :options="$this::MODE_OPTIONS" :active="$mode" method="setMode" />
    <x-oracly.chip-group :options="$this::PROFILES" :active="$profile" method="setProfile" />

    @php($stats = $this->profileStats)
    <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
        @foreach ($this::PROFILES as $key => $label)
            @php($all = $stats[$key]['all'])
            @php($val = $stats[$key]['validation'])
            <x-oracly.stat-tile :active="$profile === $key"
                :value="$val['roi'] === null ? '—' : sprintf('%+.1f%%', $val['roi'])"
                :label="$label.' · ROI desde '.\Carbon\Carbon::parse($this::VALIDATION_FROM)->format('m/Y')">
                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                    {{ number_format($val['entries'], 0, ',', '.') }} entradas ·
                    acerto {{ $val['hitRate'] === null ? '—' : number_format($val['hitRate'], 1, ',', '.').'%' }} ·
                    1% abaixo da justa {{ $val['roiDiscount'] === null ? '—' : sprintf('%+.1f%%', $val['roiDiscount']) }}
                </p>
                <p class="text-xs text-gray-500 dark:text-gray-400">
                    Base inteira: {{ number_format($all['entries'], 0, ',', '.') }} entradas ·
                    {{ $all['roi'] === null ? '—' : sprintf('%+.1f%%', $all['roi']) }}
                </p>
            </x-oracly.stat-tile>
        @endforeach
    </div>

    <div class="oracly-warning">
        O ganho é fino: no backtest, pagar 1% abaixo da odd justa come cerca de 1 ponto de retorno,
        e em casa de aposta (margem de ~7%) todo perfil perde. A lista do dia usa odd de
        <strong>abertura</strong> com margem só para estimar a probabilidade — quem decide é a odd
        que a exchange paga na hora.
    </div>

    @if ($mode === 'upcoming')
        <x-oracly.chip-group :options="$this->hourOptions" :active="$hourFilter" method="setHourFilter" />
        <x-oracly.chip-group :options="$this::BEST_PER_HOUR_OPTIONS" :active="$bestPerHourFilter" method="setBestPerHourFilter" />
    @endif

    <div class="oracly-scroll-x">
        <table class="oracly-table o15">
            <thead>
                <tr>
                    <th>{{ $mode === 'history' ? 'Data' : 'Horário' }}</th>
                    <th>Jogo</th>
                    <th class="c-num">Odd {{ $mode === 'history' ? 'fechamento' : 'abertura' }}</th>
                    <th class="c-num">Odd justa</th>
                    <th class="c-num">Edge da liga</th>
                    <th class="c-num">Probabilidade</th>
                    <th class="c-num">Odd mínima</th>
                    @if ($mode === 'upcoming')
                        <th>Odd na exchange</th>
                    @else
                        <th class="c-num">Placar</th>
                        <th>Resultado</th>
                    @endif
                </tr>
            </thead>
            <tbody>
                @forelse ($this->filteredRows as $row)
                    @php($edge = $row['leagueEdge']['edge'] ?? null)
                    <tr wire:key="o15-{{ $row['rowId'] }}">
                        <td class="whitespace-nowrap">
                            @if ($mode === 'history')
                                {{ \Carbon\Carbon::parse($row['matchDate'])->format('d/m/Y') }}
                            @else
                                <strong>{{ \App\Oracly\Support\BrasiliaDate::timeFromKickoff($row['kickoffAt'] ?? null) ?? '—' }}</strong>
                                <x-oracly.opportunity-rank-badge :rank="$row['opportunityRank'] ?? null" />
                            @endif
                        </td>
                        <td>
                            <x-oracly.team-pair :home="$row['homeTeam'] ?? ''" :away="$row['awayTeam'] ?? ''" />
                            <small>
                                <button type="button" wire:click="toggleLeague('', {{ json_encode($row['competition'] ?? '') }})" wire:loading.attr="disabled" wire:target="toggleLeague">
                                    {{ in_array('::'.($row['competition'] ?? ''), $favoriteLeagues, true) ? '★' : '☆' }}
                                </button>
                                {{ str_replace('_', ' ', $row['competition'] ?? '—') }}
                            </small>
                        </td>
                        <td class="c-num" data-label="Odd {{ $mode === 'history' ? 'fechamento' : 'abertura' }}">{{ number_format((float) $row['oddOver15'], 2, ',', '.') }}</td>
                        <td class="c-num" data-label="Odd justa">{{ number_format((float) $row['fairOdd'], 2, ',', '.') }}</td>
                        <td class="c-num" data-label="Edge da liga">
                            @if ($edge === null)
                                —
                                <small>&lt; {{ \App\Oracly\Services\Over15ValueStrategy::MIN_LEAGUE_ENTRIES }} jogos</small>
                            @else
                                <span @class(['o15-edge--pos' => $edge > 0, 'o15-edge--neg' => $edge < 0])>{{ sprintf('%+.1f', $edge * 100) }}pp</span>
                                <small>{{ number_format($row['leagueEdge']['entries'], 0, ',', '.') }} jogos</small>
                            @endif
                        </td>
                        <td class="c-num" data-label="Probabilidade">{{ number_format((float) $row['probability'] * 100, 1, ',', '.') }}%</td>
                        <td class="c-num c-min" data-label="Odd mínima"><strong>{{ number_format((float) $row['minEntryOdd'], 2, ',', '.') }}</strong></td>
                        @if ($mode === 'upcoming')
                            @php($verdict = $this->verdictFor($row))
                            <td class="c-offer" data-label="Odd na exchange">
                                <input type="text" inputmode="decimal" placeholder="ex.: {{ number_format((float) $row['minEntryOdd'] + 0.02, 2, ',', '') }}"
                                    wire:model.live.debounce.400ms="offeredOdds.{{ $row['rowId'] }}" aria-label="Odd oferecida na exchange" />
                                @if ($verdict)
                                    <span @class(['o15-verdict', 'o15-verdict--enter' => $verdict['verdict'] === 'enter', 'o15-verdict--skip' => $verdict['verdict'] === 'skip'])>
                                        {{ $verdict['verdict'] === 'enter' ? 'Entrar' : 'Não entrar' }} · EV {{ sprintf('%+.1f%%', $verdict['edge']) }}
                                    </span>
                                @endif
                            </td>
                        @else
                            <td class="c-num" data-label="Placar">{{ $row['homeGoals'] ?? '—' }}-{{ $row['awayGoals'] ?? '—' }}</td>
                            <td><x-oracly.result-badge :hit="$this->strategy()->result($row) === 'green'" /></td>
                        @endif
                    </tr>
                @empty
                    <tr>
                        <td colspan="9" class="py-6 text-gray-500 dark:text-gray-400">
                            Nenhum jogo neste perfil.
                            @if ($mode === 'upcoming')
                                A lista vem de panel_fixtures (Punter): só entram jogos com odd de over 1,5 de abertura.
                            @endif
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <p class="oracly-note">
        <strong>Odd mínima</strong> é a menor odd com retorno esperado zero já descontada a comissão:
        1 + (1 − p) / (p × (1 − comissão)). <strong>Probabilidade</strong> é a do mercado sem margem
        somada ao edge da liga encolhido pelo tamanho da amostra. <strong>Edge da liga</strong> é
        quanto o over 1,5 saiu acima do que o mercado precificava naquela liga, no histórico.
    </p>
    @if ($mode === 'history')
        <p class="oracly-note">A tabela mostra os 500 jogos mais recentes do perfil; os números dos cartões usam a base inteira.</p>
    @endif
</x-filament-panels::page>
